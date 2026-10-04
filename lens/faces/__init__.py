"""Daemon-side face phases: detection (§4) and grouping (§5), never mixed."""

from faces.cluster import (
    BatchFace,
    FaceGrouper,
    complete_linkage,
    mint_cluster_id,
    run_periodically,
    select_cluster,
)
from faces.pipeline import FaceError, FaceIndexer, match_faces, mint_face_id

__all__ = [
    "BatchFace",
    "FaceError",
    "FaceGrouper",
    "FaceIndexer",
    "complete_linkage",
    "match_faces",
    "mint_cluster_id",
    "mint_face_id",
    "run_periodically",
    "select_cluster",
]
