"""Memories Lens daemon: index API, search API, health/stats."""

import asyncio
import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from qdrant_client import AsyncQdrantClient

from auth import bearer_matches
from config import config
from index import Indexer, IndexQueue, Scanner
from routes import routers
from routes.context import embedding_model, face_model, schema_model, sentence_model, state
from store import Store


@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Load models and collections, then start jobs; initialization failures are fatal."""

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s: %(message)s",
    )

    await asyncio.to_thread(embedding_model.ensure_snapshot)
    await asyncio.to_thread(embedding_model.load)
    await asyncio.to_thread(sentence_model.ensure_snapshot)
    await asyncio.to_thread(sentence_model.load)
    await asyncio.to_thread(schema_model.ensure_snapshot)
    await asyncio.to_thread(schema_model.load)
    await asyncio.to_thread(face_model.ensure_snapshot)
    await asyncio.to_thread(face_model.load)

    client = AsyncQdrantClient(url=config.qdrant_url)
    store = Store(
        client=client,
        embedding_dim=embedding_model.dim(),
        sentence_dim=sentence_model.dim(),
        face_dim=face_model.dim(),
    )
    state.store = store

    index_queue = IndexQueue(maxsize=config.queue_max)
    state.index_queue = index_queue
    indexer = Indexer(
        embedding_model=embedding_model,
        sentence_model=sentence_model,
        store=store,
        done=index_queue.done,
    )
    scanner = Scanner(store, index_queue)
    state.scanner = scanner

    tasks = []
    try:
        for collection in store.collections:
            await collection.ensure_collection()

        state.qdrant = "ok"
        state.ready = True
        tasks = [index_queue.run(indexer.handle_batch), asyncio.create_task(scanner.run())]

        yield
    finally:
        state.ready = False
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        await client.close()


app = FastAPI(title="Memories Lens", lifespan=lifespan)


@app.middleware("http")
async def require_daemon_token(request: Request, call_next):
    """Require the shared bearer token on every route, including debug."""

    header = request.headers.get("authorization", "")
    if not bearer_matches(header, config.daemon_token):
        peer = request.client.host if request.client else "-"
        logging.getLogger("lens.auth").warning(
            "rejected %s %s from %s",
            request.method,
            request.url.path,
            peer,
        )

        return JSONResponse(status_code=401, content={"detail": "unauthorized"})

    return await call_next(request)


for router in routers:
    app.include_router(router)
