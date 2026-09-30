"""Faces collection: one SFace vector per (file, face) pair."""

import logging
import uuid
from dataclasses import dataclass

from qdrant_client import AsyncQdrantClient, models

from config import config
from process.scoring import drop_low_scores
from store.base import (
    META_ID,
    check_meta,
    ensure_collection,
    ensure_integer_indexes,
    ensure_keyword_indexes,
    require_unnamed_vectors,
)

log = logging.getLogger("lens.store")


@dataclass(frozen=True)
class FacePoint:
    """One detected face: geometry in fractions, L2-normed 128-d vector, uint63 cluster or null."""

    fileid: int
    parent_id: int
    owner_id: str
    face_idx: int
    vector: list[float]
    x: float
    y: float
    w: float
    h: float
    det_score: float
    cluster_id: int | None = None


def face_point_id(fileid: int, face_idx: int) -> str:
    """Deterministic point id for one (file, face) pair; re-index overwrites."""

    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"lens_face:{int(fileid)}:{int(face_idx)}"))


class FacesStore:
    """Faces collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, dim):
        self.client = client
        self.dim = dim

    async def ensure_collection(self):
        """Create the per-face collection, indexes, and sentinel; reruns are safe."""

        name = config.face.qdrant_collection
        info = await ensure_collection(self.client, name, self.dim)
        require_unnamed_vectors(info, name)

        # Integer indexes for folder-scoped search, per-file delete, cluster moves.
        await ensure_integer_indexes(self.client, name, info, ("parent_id", "fileid", "cluster_id"))

        # Keyword index for owner-scoped assignment KNN; no cross-owner matching ever.
        await ensure_keyword_indexes(self.client, name, info, ("owner_id",))

        # Sentinel guard: stamp when absent, refuse when the space differs.
        await check_meta(self.client, name, META_ID, self._expected_meta(), self.dim)

    async def upsert_many(self, points: list[FacePoint]):
        """Store per-(file, face) embeddings with final cluster ids; re-index overwrites."""

        structs = []

        for point in points:
            payload = {
                "fileid": int(point.fileid),
                "parent_id": int(point.parent_id),
                "face_idx": int(point.face_idx),
                "x": point.x,
                "y": point.y,
                "w": point.w,
                "h": point.h,
                "det_score": point.det_score,
            }

            # Empty owner means unknown; omit so the keyword index only sees real UIDs.
            if point.owner_id:
                payload["owner_id"] = point.owner_id

            # Null cluster means unassigned; omit so the integer index only sees real ids.
            if point.cluster_id is not None:
                payload["cluster_id"] = int(point.cluster_id)

            structs.append(
                models.PointStruct(
                    id=face_point_id(point.fileid, point.face_idx),
                    vector=point.vector,
                    payload=payload,
                ),
            )

        if structs:
            await self.client.upsert(config.face.qdrant_collection, points=structs)

    async def search(self, vector, folders, limit):
        """Nearest face vectors scoped to folders, low scores dropped, score desc."""

        # Sentinel has no parent_id, so the filter excludes it automatically.
        filtr = models.Filter(must=[models.FieldCondition(
            key="parent_id",
            match=models.MatchAny(any=folders),
        )])

        res = await self.client.query_points(
            collection_name=config.face.qdrant_collection,
            query=vector,
            query_filter=filtr,
            limit=limit,
        )

        hits = [
            {"id": p.id, **p.payload, "score": p.score}
            for p in res.points
        ]

        return drop_low_scores(hits, config.face.score_margin)

    async def search_owner(self, vector, owner_id, limit):
        """
        Nearest face vectors within one owner's scope; raw hits, score desc.

        No relative cutoff here: the caller applies the absolute
        FACE_MAX_DISTANCE gate plus the core-point check, and a relative
        cutoff could discard valid within-threshold neighbors whenever
        the top hit is much better than the rest.
        """

        if not owner_id:
            raise ValueError("owner_id must not be empty")

        # Sentinel has no owner_id, so the filter excludes it automatically.
        filtr = models.Filter(must=[models.FieldCondition(
            key="owner_id",
            match=models.MatchValue(value=owner_id),
        )])

        res = await self.client.query_points(
            collection_name=config.face.qdrant_collection,
            query=vector,
            query_filter=filtr,
            limit=limit,
        )

        return [
            {"id": p.id, **p.payload, "score": p.score}
            for p in res.points
        ]

    async def assign_faces(self, pairs: list[tuple[str, int]]):
        """Move face points to new clusters; owner untouched (same-owner moves only)."""

        by_cluster: dict[int, list] = {}

        for point_id, cluster_id in pairs:
            by_cluster.setdefault(int(cluster_id), []).append(point_id)

        for cluster_id, point_ids in by_cluster.items():
            selector = models.PointIdsList(points=point_ids)
            await self.client.set_payload(
                collection_name=config.face.qdrant_collection,
                payload={"cluster_id": cluster_id},
                points=selector,
            )

    async def delete_fileid(self, fileid: int):
        """Remove all face embeddings for one file (all its hashed pairs)."""

        selector = models.FilterSelector(filter=models.Filter(must=[models.FieldCondition(
            key="fileid",
            match=models.MatchValue(value=int(fileid)),
        )]))

        await self.client.delete(config.face.qdrant_collection, points_selector=selector)

    def _expected_meta(self):
        """Sentinel payload describing the face embedding space."""

        return {
            "kind": "lens_faces_meta",
            "face": {
                "det_sha": config.face.det_sha,
                "rec_sha": config.face.rec_sha,
                "version": config.face.version,
                "dimension": self.dim,
                "normalization": "l2",
            },
        }
