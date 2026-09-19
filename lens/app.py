"""Memories Lens daemon: index API, search API, health/stats."""

from fastapi import FastAPI

from config import config

app = FastAPI(title="Memories Lens")


@app.get("/v1/health")
def health():
    """Liveness + model/qdrant readiness; degraded until wired."""
    return {
        "status": "degraded",
        "model": config.embedding_model_id,
        "embedding_revision": config.embedding_model_revision,
        "embedding_version": config.embedding_version,
        "device": config.device,
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
