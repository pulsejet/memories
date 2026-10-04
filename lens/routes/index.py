"""Enqueue a file; delete its points."""

import asyncio
import logging

from fastapi import APIRouter, BackgroundTasks, HTTPException, Path, Query, Response
from pydantic import BaseModel, Field

from routes.context import state

router = APIRouter()
log = logging.getLogger("lens.app")


class IndexRequest(BaseModel):
    """One index job: file to embed and its parent folder."""

    fileid: int = Field(gt=0)
    parent_id: int


@router.post("/v1/index", status_code=202)
async def index(body: IndexRequest):
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


@router.delete("/v1/index/{fileid}")
async def delete_index(
    background_tasks: BackgroundTasks,
    response: Response,
    fileid: int = Path(gt=0),
    async_: bool = Query(default=False, alias="async"),
):
    """Delete synchronously unless async=true requests background cleanup."""

    if async_:
        background_tasks.add_task(_delete_file, fileid)
        response.status_code = 202
        return {"fileid": fileid, "status": "queued"}

    await _delete_file(fileid)

    return {"fileid": fileid, "status": "deleted"}


async def _delete_file(fileid: int):
    """Serialize cleanup with indexing and log failures for later scan reconciliation."""

    try:
        async with state.index_queue.mutation_lock:
            state.index_queue.drop(fileid)
            for collection in state.store.collections:
                await collection.delete_fileid(fileid)

        log.info("deleted embeddings for %d", fileid)
    except Exception:
        log.exception("failed to delete embeddings for %d", fileid)
        raise
