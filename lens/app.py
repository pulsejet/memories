"""Memories Lens daemon: index API, search API, health/stats."""

import asyncio
import logging
from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI, HTTPException, Request
from qdrant_client import AsyncQdrantClient
from qdrant_client.http.exceptions import UnexpectedResponse

from config import config
from embedding import EmbeddingModel
from store import CompatMismatch, Store

log = logging.getLogger("lens.app")

embedding_model = EmbeddingModel()
state = {"ready": False, "qdrant": "unknown", "store": None}


@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Provision snapshot, load model, ensure collection; mismatch is fatal."""

    await asyncio.to_thread(embedding_model.ensure_snapshot)
    await asyncio.to_thread(embedding_model.load)

    client = AsyncQdrantClient(url=config.qdrant_url)
    store = Store(client, embedding_model.dim())
    state["store"] = store

    try:
        await store.ensure_embedding_collection()
    except CompatMismatch:
        await client.close()
        raise

    except (UnexpectedResponse, httpx.HTTPError):
        log.exception("qdrant unreachable, staying degraded")
    else:
        state["qdrant"] = "ok"
        state["ready"] = True

    yield
    await client.close()


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
        "qdrant": state["qdrant"],
    }


@app.get("/v1/stats")
def stats():
    """Queue/index/failure counters; zeros until worker lands."""

    return {
        "queued": 0,
        "indexed_total": 0,
        "failed_total": 0,
    }


@app.post("/v1/embedding/text")
async def embedding_text(body: dict):
    """Embed text for testing; returns the raw vector."""

    if not state["ready"]:
        raise HTTPException(status_code=503, detail="model not loaded")

    vec = await embedding_model.embed_text_async(body.get("text", ""))

    return {"vector": vec, "dimension": len(vec)}


@app.post("/v1/embedding/image")
async def embedding_image(request: Request):
    """Embed raw image bytes for testing; returns the raw vector."""

    if not state["ready"]:
        raise HTTPException(status_code=503, detail="model not loaded")

    vec = await embedding_model.embed_image_async(await request.body())

    return {"vector": vec, "dimension": len(vec)}
