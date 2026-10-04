"""Trigger one bounded face-grouping pass; detection never runs here."""

import logging

from fastapi import APIRouter, BackgroundTasks, HTTPException
from pydantic import BaseModel, Field

from routes.context import state

router = APIRouter()
log = logging.getLogger("lens.app")


class ReassignFace(BaseModel):
    """One user-moved face; null cluster unassigns."""

    id: int = Field(gt=0)
    cluster: int | None = Field(default=None, gt=0)


class ReassignBody(BaseModel):
    faces: list[ReassignFace]


@router.post("/v1/faces/cluster", status_code=202)
async def cluster_faces(background_tasks: BackgroundTasks):
    """Queue one grouping pass; 503 when the grouper is not ready."""

    if state.face_grouper is None or state.index_queue is None:
        raise HTTPException(status_code=503, detail="grouper not ready")

    background_tasks.add_task(_run_cluster)

    return {"status": "queued"}


@router.post("/v1/faces/reassign")
async def reassign_faces(body: ReassignBody):
    """Apply user face moves to Qdrant synchronously; SQL already moved."""

    if state.store is None:
        raise HTTPException(status_code=503, detail="store not ready")

    updated = await state.store.faces.reassign_clusters(
        {face.id: face.cluster for face in body.faces}
    )

    return {"updated": updated}


async def _run_cluster():
    """Serialize the explicit trigger with indexing; failures stay visible in logs."""

    try:
        async with state.index_queue.mutation_lock:
            await state.face_grouper.run_once()
    except Exception:
        log.exception("explicit faces grouping failed")
        raise
