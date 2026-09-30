"""Enqueue a file; delete its points."""

import asyncio

from fastapi import APIRouter, HTTPException, Path
from pydantic import BaseModel, Field

from routes.context import state

router = APIRouter()


class IndexRequest(BaseModel):
    """One index job: file to embed and its parent folder."""

    fileid: int = Field(gt=0)
    parent_id: int


@router.post("/v1/index", status_code=202)
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


@router.delete("/v1/index/{fileid}")
async def delete_index(fileid: int = Path(gt=0)):
    """Delete a point and drop its queued entry, if any."""

    await state.store.embedding.delete(fileid)
    await state.store.places.delete_fileid(fileid)
    await state.store.faces.delete_fileid(fileid)
    state.index_queue.drop(fileid)

    return {"fileid": fileid, "status": "deleted"}
