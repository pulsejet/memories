"""Liveness + models/qdrant readiness; degraded until wired."""

from fastapi import APIRouter

from config import config
from routes.context import embedding_model, face_model, schema_model, sentence_model, state

router = APIRouter()


@router.get("/v1/health")
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
        "schema_model": {
            "id": config.schema_model.model_id,
            "revision": config.schema_model.model_revision,
            "threshold": config.schema_model.threshold,
            "device": schema_model.device(),
        },
        "face": {
            "det_url": config.face.det_url,
            "det_sha": config.face.det_sha,
            "rec_url": config.face.rec_url,
            "rec_sha": config.face.rec_sha,
            "version": config.face.version,
            "qdrant_collection": config.face.qdrant_collection,
            "dimension": face_model.dim() or None,
            "device": face_model.device(),
            "det_threshold": config.face.det_threshold,
            "det_max_side": config.face.det_max_side,
            "max_distance": config.face.max_distance,
            "min_faces": config.face.min_faces,
            "restore_center_frac": config.face.restore_center_frac,
            "merge_distance": config.face.merge_distance,
            "merge_quorum": config.face.merge_quorum,
            "merge_samples": config.face.merge_samples,
            "merge_batch": config.face.merge_batch,
            "merge_retry_interval": config.face.merge_retry_interval,
        },
        "qdrant": state.qdrant,
    }


@router.get("/v1/stats")
def stats():
    """Queue depth, in-flight fileids, and index/failure counters."""

    queue = state.index_queue

    return {
        "queue_depth": queue.depth,
        "in_flight": sorted(queue.in_flight),
        "indexed_total": queue.indexed_total,
        "failed_total": queue.failed_total,
    }
