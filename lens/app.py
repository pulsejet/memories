"""Memories Lens daemon: index API, search API, health/stats."""

import asyncio
import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from qdrant_client import AsyncQdrantClient

from config import config
from index import Indexer, IndexQueue
from routes import routers
from routes.context import embedding_model, face_model, schema_model, sentence_model, state
from store import CompatMismatch, Store

log = logging.getLogger("lens.app")


@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Load models, ensure collections, start worker; mismatch is fatal."""

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
    worker = index_queue.run(indexer.handle_batch)

    try:
        await store.embedding.ensure_collection()
        await store.places.ensure_collection()
        await store.faces.ensure_collection()
    except CompatMismatch:
        worker.cancel()
        await client.close()
        raise

    except Exception:
        log.exception("qdrant unreachable, staying degraded")
    else:
        state.qdrant = "ok"
        state.ready = True

    yield
    worker.cancel()
    await client.close()


app = FastAPI(title="Memories Lens", lifespan=lifespan)

for router in routers:
    app.include_router(router)
