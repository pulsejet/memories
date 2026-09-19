"""Memories Lens daemon: index API, search API, health/stats."""

import asyncio
from contextlib import asynccontextmanager

from fastapi import FastAPI

from config import config
from embedding import EmbeddingModel

embedding_model = EmbeddingModel()
state = {"ready": False}


@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Provision snapshot, load model, mark ready; startup fails on error."""

    await asyncio.to_thread(embedding_model.ensure_snapshot)
    await asyncio.to_thread(embedding_model.load)
    state["ready"] = True
    yield


app = FastAPI(title="Memories Lens", lifespan=lifespan)


@app.get("/v1/health")
def health():
    """Liveness + model/qdrant readiness; degraded until wired."""

    return {
        "status": "ok" if state["ready"] else "degraded",
        "embedding_model": {
            "id": config.embedding.model_id,
            "revision": config.embedding.model_revision,
            "version": config.embedding.version,
            "device": embedding_model.device(),
            "dimension": embedding_model.dim() or None,
        },
        "qdrant": "unknown",
    }


@app.get("/v1/stats")
def stats():
    """Queue/index/failure counters; zeros until worker lands."""

    return {
        "queued": 0,
        "indexed_total": 0,
        "failed_total": 0,
    }
