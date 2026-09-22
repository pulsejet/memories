"""Faces collection: one SFace vector per (file, face) pair plus the merge oracle."""

import logging
import uuid
from dataclasses import dataclass

from qdrant_client import AsyncQdrantClient, models

from config import config
from process.scoring import drop_low_scores
from store.base import META_ID, check_meta, ensure_collection, ensure_integer_indexes, require_unnamed_vectors

log = logging.getLogger("lens.store")


@dataclass(frozen=True)
class FacePoint:  # pylint: disable=too-many-instance-attributes
    """One detected face: geometry in fractions, L2-normed 128-d vector, uint63 cluster or null."""

    fileid: int
    parent_id: int
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


def _normed_mean(vectors: list[list[float]]) -> list[float]:
    """L2-normalized mean of L2-normed vectors (cosine-comparable centroid)."""

    dim = len(vectors[0])
    mean = [sum(v[i] for v in vectors) / len(vectors) for i in range(dim)]
    norm = sum(v * v for v in mean) ** 0.5

    return [v / norm for v in mean] if norm else mean


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

    async def assign_faces(self, pairs: list[tuple[str, int]]):
        """Move face points to new clusters (corrections, merge disposal); integer ids."""

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

    async def merge_candidates(self, limit: int):
        """Pure KNN oracle: quorum + centroid double-gate proposals; stores nothing."""

        members = await self._face_members()

        proposals = []

        for src, point_ids in members.items():
            proposal = await self._propose_merge(src, point_ids)

            if proposal is not None:
                proposals.append(proposal)

        proposals.sort(key=lambda proposal: proposal["score"], reverse=True)

        return proposals[:max(0, limit)]

    async def delete_fileid(self, fileid: int):
        """Remove all face embeddings for one file (all its hashed pairs)."""

        selector = models.FilterSelector(filter=models.Filter(must=[models.FieldCondition(
            key="fileid",
            match=models.MatchValue(value=int(fileid)),
        )]))

        await self.client.delete(config.face.qdrant_collection, points_selector=selector)

    async def _face_members(self):
        """Group assigned face point ids by cluster; the sentinel has no cluster_id."""

        name = config.face.qdrant_collection
        members: dict[int, list] = {}
        offset = None

        while True:
            records, offset = await self.client.scroll(
                collection_name=name,
                limit=1000,
                offset=offset,
                with_payload=True,
                with_vectors=False,
            )

            for record in records:
                cluster_id = (record.payload or {}).get("cluster_id")

                if isinstance(cluster_id, int):
                    members.setdefault(cluster_id, []).append(record.id)

            if offset is None:
                return members

    async def _propose_merge(self, src: int, point_ids: list):
        """Quorum + centroid double-gate merge proposal for one source cluster."""

        gate = 1.0 - config.face.merge_distance
        quorum = config.face.merge_quorum

        # Evenly spaced round-robin sample of the source's members.
        step = max(1, len(point_ids) // config.face.merge_samples)
        vectors = await self._face_vectors(point_ids[::step][:config.face.merge_samples])

        if not vectors:
            return None

        src_centroid = _normed_mean([entry["vector"] for entry in vectors])

        # One vote per sample: its top hit within merge distance,
        # excluding self-cluster and same-fileid faces.
        votes: dict[int, list] = {}

        for entry in vectors:
            vote = await self._sample_vote(entry, src, gate)

            if vote is not None:
                votes.setdefault(vote[0], []).append(vote[1:])

        for dst, agreed in votes.items():
            if len(agreed) < quorum:
                continue

            dst_centroid = _normed_mean([vector for _, vector in agreed])

            if sum(a * b for a, b in zip(src_centroid, dst_centroid)) < gate:
                continue

            score = sum(s for s, _ in agreed) / len(agreed)

            return {"src": src, "dst": dst, "votes": len(agreed), "score": score}

        return None

    async def _sample_vote(self, entry: dict, src: int, gate: float):
        """Top qualifying hit for one sample as (dst, score, vector); None when absent."""

        filtr = models.Filter(must_not=[
            models.FieldCondition(key="cluster_id", match=models.MatchValue(value=src)),
            models.FieldCondition(
                key="fileid",
                match=models.MatchValue(value=entry["fileid"]),
            ),
        ])

        res = await self.client.query_points(
            collection_name=config.face.qdrant_collection,
            query=entry["vector"],
            query_filter=filtr,
            limit=10,
            with_vectors=True,
        )

        for hit in res.points:
            if hit.score < gate:
                return None

            dst = (hit.payload or {}).get("cluster_id")

            if isinstance(dst, int):
                return (dst, hit.score, hit.vector)

        return None

    async def _face_vectors(self, point_ids: list):
        """Fetch vectors + fileids for sampled points; skips vanished ids."""

        if not point_ids:
            return []

        name = config.face.qdrant_collection
        records = await self.client.retrieve(name, ids=point_ids, with_vectors=True)

        return [
            {"vector": list(record.vector), "fileid": (record.payload or {}).get("fileid")}
            for record in records
            if record.vector is not None
        ]

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
