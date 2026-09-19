"""Memories Lens daemon: index API, search API, health/stats."""

import asyncio
import logging
from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI, HTTPException, Path, Request
from qdrant_client import AsyncQdrantClient
from qdrant_client.http.exceptions import UnexpectedResponse

from config import config
from embedding import EmbeddingModel
from queueing import IndexQueue
from sentence import SentenceModel
from state import IndexRequest, SearchRequest, State
from store import CompatMismatch, Store

log = logging.getLogger("lens.app")

embedding_model = EmbeddingModel()
sentence_model = SentenceModel()
state = State()


@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Load models, ensure collection, start worker; mismatch is fatal."""

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s: %(message)s",
    )

    await asyncio.to_thread(embedding_model.ensure_snapshot)
    await asyncio.to_thread(embedding_model.load)
    await asyncio.to_thread(sentence_model.ensure_snapshot)
    await asyncio.to_thread(sentence_model.load)

    client = AsyncQdrantClient(url=config.qdrant_url)
    store = Store(client, embedding_model.dim())
    state.store = store

    index_queue = IndexQueue(maxsize=config.queue_max)
    state.index_queue = index_queue
    worker = index_queue.run(embedding_model=embedding_model, store=store)

    try:
        await store.ensure_embedding_collection()
    except CompatMismatch:
        worker.cancel()
        await client.close()
        raise

    except (UnexpectedResponse, httpx.HTTPError):
        log.exception("qdrant unreachable, staying degraded")
    else:
        state.qdrant = "ok"
        state.ready = True

    yield
    worker.cancel()
    await client.close()


app = FastAPI(title="Memories Lens", lifespan=lifespan)


@app.get("/v1/health")
def health():
    """Liveness + models/qdrant readiness; degraded until wired."""

    return {
        "status": "ok" if state.ready else "degraded",
        "embedding_model": {
            "id": config.embedding.model_id,
            "revision": config.embedding.model_revision,
            "version": config.embedding.version,
            "device": embedding_model.device(),
            "dimension": embedding_model.dim() or None,
            "batch_size": config.index_batch_size,
        },
        "sentence_model": {
            "id": config.sentence_model.model_id,
            "revision": config.sentence_model.model_revision,
            "version": config.sentence_model.version,
            "device": sentence_model.device(),
            "dimension": sentence_model.dim() or None,
        },
        "qdrant": state.qdrant,
    }


@app.get("/v1/stats")
def stats():
    """Queue depth, in-flight fileids, and index/failure counters."""

    queue = state.index_queue

    return {
        "queue_depth": queue.depth,
        "in_flight": sorted(queue.in_flight),
        "indexed_total": queue.indexed_total,
        "failed_total": queue.failed_total,
    }


@app.post("/v1/index", status_code=202)
def index(body: IndexRequest):
    """Enqueue a file; 429 when the queue is full."""

    try:
        state.index_queue.enqueue(
            fileid=body.fileid,
            parent_id=body.parent_id,
        )
    except asyncio.QueueFull as exc:
        raise HTTPException(
            status_code=429,
            detail="queue full",
            headers={"Retry-After": "5"},
        ) from exc

    return {"fileid": body.fileid, "status": "queued"}


@app.delete("/v1/index/{fileid}")
async def delete_index(fileid: int = Path(gt=0)):
    """Delete a point and drop its queued entry, if any."""

    await state.store.delete(fileid)
    state.index_queue.drop(fileid)

    return {"fileid": fileid, "status": "deleted"}


@app.post("/v1/search")
async def search(body: SearchRequest):
    """Embed the query and return matching fileids, score desc."""

    require_ready()

    if not body.folders:
        raise HTTPException(
            status_code=400,
            detail="folders must not be empty",
        )

    vec = await embedding_model.embed_text_async(body.text)
    hits = await state.store.search(
        vector=vec,
        folders=body.folders,
        limit=body.limit,
    )

    if not hits:
        return {"hits": []}

    cutoff = hits[0]["score"] - config.score_margin
    kept = [h for h in hits if h["score"] >= cutoff]

    return {"hits": kept}


@app.post("/v1/embedding/text")
async def embedding_text(body: dict):
    """Embed text for testing; returns the raw vector."""

    require_ready()

    vec = await embedding_model.embed_text_async(body.get("text", ""))

    return {"vector": vec, "dimension": len(vec)}


@app.post("/v1/embedding/image")
async def embedding_image(request: Request):
    """Embed raw image bytes for testing; returns the raw vector."""

    require_ready()

    vec = await embedding_model.embed_image_async(await request.body())

    return {"vector": vec, "dimension": len(vec)}


@app.post("/v1/sentence/query")
async def sentence_query(body: dict):
    """Embed text with the sentence model for testing; returns the raw vector."""

    require_ready()

    vec = await sentence_model.embed_query_async(body.get("text", ""))

    return {"vector": vec, "dimension": len(vec)}


def require_ready():
    """Raise 503 unless the models are loaded."""

    if not state.ready:
        raise HTTPException(
            status_code=503,
            detail="model not loaded",
        )
