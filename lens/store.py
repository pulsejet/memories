"""Qdrant vector store with embedding-compat guard."""

import logging
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone

from qdrant_client import AsyncQdrantClient, models

from config import config
from embedding_scoring import drop_low_scores

log = logging.getLogger("lens.store")

# Nextcloud fileids are positive, so id 0 never collides with real points.
META_ID = 0

# Index builds on large collections take a while; the client default (5s) trips.
PAYLOAD_INDEX_TIMEOUT = 120


@dataclass(frozen=True)
class FileMeta:
    """Display metadata stored alongside each embedding."""

    w: int
    h: int
    etag: str
    mimetype: str
    epoch: int | None
    dayid: int | None


@dataclass(frozen=True)
class UpsertPoint:
    """One file embedding with display metadata, ready for Qdrant."""

    fileid: int
    vector: list[float]
    parent_id: int
    meta: FileMeta
    osm_ids: list[int] | None = None


@dataclass(frozen=True)
class PlacePoint:
    """One per-(file, place) address embedding, scoped by parent_id, grouped by osm_id."""

    fileid: int
    parent_id: int
    osm_id: int
    vector: list[float]
    admin_level: int
    name: str
    full_address: str


def place_point_id(fileid: int, osm_id: int) -> str:
    """Deterministic point id for one (file, place) pair; re-index overwrites."""

    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"lens_places:{int(fileid)}:{int(osm_id)}"))


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


class CompatMismatch(RuntimeError):
    """Stored embedding metadata differs from current config."""


class Store:
    """Qdrant collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, embedding_dim, sentence_dim, face_dim):
        self.client = client
        self.embedding_dim = embedding_dim
        self.sentence_dim = sentence_dim
        self.face_dim = face_dim

    async def ensure_embedding_collection(self):
        """Create collection, indexes, and sentinel step by step; reruns are safe."""

        name = config.embedding.qdrant_collection

        # Collection with cosine vectors sized to the embedding dim.
        if not await self.client.collection_exists(name):
            params = models.VectorParams(size=self.embedding_dim, distance=models.Distance.COSINE)
            await self.client.create_collection(name, vectors_config=params)
            log.info("created collection %s", name)

        info = await self.client.get_collection(name)

        # Clean break from the reset named-vectors attempt: refuse those
        # collections instead of failing later at upsert (wipe + reindex).
        self._require_unnamed_vectors(info, name)

        # Integer index on parent_id for folder-scoped search.
        if "parent_id" not in (info.payload_schema or {}):
            await self.client.create_payload_index(
                collection_name=name,
                field_name="parent_id",
                field_schema=models.PayloadSchemaType.INTEGER,
                timeout=PAYLOAD_INDEX_TIMEOUT,
            )
            log.info("indexed parent_id in %s", name)

        # Integer index on osm_ids for place-filtered search.
        if "osm_ids" not in (info.payload_schema or {}):
            await self.client.create_payload_index(
                collection_name=name,
                field_name="osm_ids",
                field_schema=models.PayloadSchemaType.INTEGER,
                timeout=PAYLOAD_INDEX_TIMEOUT,
            )
            log.info("indexed osm_ids in %s", name)

        # Sentinel guard: stamp when absent, refuse when the space differs.
        await self._check_meta(name, META_ID, self._expected_meta(), self.embedding_dim)

    async def ensure_places_collection(self):
        """Create the per-file places collection, indexes, and sentinel; reruns are safe."""

        name = config.places.qdrant_collection

        # Single unnamed sentence vector per (file, place) pair.
        if not await self.client.collection_exists(name):
            params = models.VectorParams(size=self.sentence_dim, distance=models.Distance.COSINE)
            await self.client.create_collection(name, vectors_config=params)
            log.info("created collection %s", name)

        info = await self.client.get_collection(name)
        self._require_unnamed_vectors(info, name)

        # Integer indexes for folder-scoped grouped search + per-file delete.
        for field in ("parent_id", "osm_id", "fileid"):
            if field not in (info.payload_schema or {}):
                await self.client.create_payload_index(
                    collection_name=name,
                    field_name=field,
                    field_schema=models.PayloadSchemaType.INTEGER,
                    timeout=PAYLOAD_INDEX_TIMEOUT,
                )
                log.info("indexed %s in %s", field, name)

        # Sentinel guard: stamp when absent, refuse when the space differs.
        await self._check_meta(name, META_ID, self._expected_places_meta(), self.sentence_dim)

    async def ensure_faces_collection(self):
        """Create the per-face collection, indexes, and sentinel; reruns are safe."""

        name = config.face.qdrant_collection

        # Single unnamed SFace vector per (file, face) pair.
        if not await self.client.collection_exists(name):
            params = models.VectorParams(size=self.face_dim, distance=models.Distance.COSINE)
            await self.client.create_collection(name, vectors_config=params)
            log.info("created collection %s", name)

        info = await self.client.get_collection(name)
        self._require_unnamed_vectors(info, name)

        # Integer indexes for folder-scoped search, per-file delete, cluster moves.
        for field in ("parent_id", "fileid", "cluster_id"):
            if field not in (info.payload_schema or {}):
                await self.client.create_payload_index(
                    collection_name=name,
                    field_name=field,
                    field_schema=models.PayloadSchemaType.INTEGER,
                    timeout=PAYLOAD_INDEX_TIMEOUT,
                )
                log.info("indexed %s in %s", field, name)

        # Sentinel guard: stamp when absent, refuse when the space differs.
        await self._check_meta(name, META_ID, self._expected_faces_meta(), self.face_dim)

    async def upsert(self, point: UpsertPoint):
        """Store one file embedding with display metadata (re-index overwrites)."""

        await self.upsert_many([point])

    async def upsert_many(self, points: list[UpsertPoint]):
        """Store a batch of file embeddings in one request."""

        structs = []
        now = datetime.now(timezone.utc).isoformat()

        for point in points:
            payload = {
                "fileid": point.fileid,
                "parent_id": point.parent_id,
                "indexed_at": now,
                "w": point.meta.w,
                "h": point.meta.h,
                "etag": point.meta.etag,
                "mimetype": point.meta.mimetype,
            }

            if point.meta.epoch is not None:
                payload["epoch"] = point.meta.epoch

            if point.meta.dayid is not None:
                payload["dayid"] = point.meta.dayid

            if point.osm_ids:
                payload["osm_ids"] = list(point.osm_ids)

            structs.append(
                models.PointStruct(
                    id=int(point.fileid),
                    vector=point.vector,
                    payload=payload,
                ),
            )

        await self.client.upsert(config.embedding.qdrant_collection, points=structs)

    async def upsert_places(self, points: list[PlacePoint]):
        """Store per-(file, place) address embeddings; re-index overwrites the same pair."""

        structs = [
            models.PointStruct(
                id=place_point_id(point.fileid, point.osm_id),
                vector=point.vector,
                payload={
                    "fileid": int(point.fileid),
                    "parent_id": int(point.parent_id),
                    "osm_id": int(point.osm_id),
                    "admin_level": point.admin_level,
                    "name": point.name,
                    "full_address": point.full_address,
                },
            )
            for point in points
        ]

        if structs:
            await self.client.upsert(config.places.qdrant_collection, points=structs)

    async def upsert_faces(self, points: list[FacePoint]):
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

    async def search_faces(self, vector, folders, limit):
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

    async def search(self, vector, folders, limit, osm_ids=None):
        """Nearest image vectors scoped to folders, low scores dropped, score desc."""

        # Sentinel has no parent_id, so the filter excludes it automatically.
        must = [models.FieldCondition(
            key="parent_id",
            match=models.MatchAny(any=folders),
        )]

        if osm_ids:
            must.append(models.FieldCondition(
                key="osm_ids",
                match=models.MatchAny(any=[int(i) for i in osm_ids]),
            ))

        res = await self.client.query_points(
            collection_name=config.embedding.qdrant_collection,
            query=vector,
            query_filter=models.Filter(must=must),
            limit=limit,
        )

        hits = [
            {**p.payload, "score": p.score}
            for p in res.points
        ]

        return drop_low_scores(hits, config.embedding.score_margin)

    async def search_places(self, vector, folders, limit):
        """Nearest per-(file, place) address embeddings in folders, one hit per osm_id."""

        # Sentinel has no parent_id, so the filter excludes it automatically.
        filtr = models.Filter(must=[models.FieldCondition(
            key="parent_id",
            match=models.MatchAny(any=folders),
        )])

        res = await self.client.query_points_groups(
            collection_name=config.places.qdrant_collection,
            group_by="osm_id",
            query=vector,
            query_filter=filtr,
            limit=limit,
            group_size=1,
        )

        hits = []

        for group in res.groups:
            if not group.hits:
                continue

            best = group.hits[0]
            hits.append({**(best.payload or {}), "score": best.score})

        return hits

    async def delete_places(self, fileid: int):
        """Remove all address embeddings for one file (all its hashed pairs)."""

        selector = models.FilterSelector(filter=models.Filter(must=[models.FieldCondition(
            key="fileid",
            match=models.MatchValue(value=int(fileid)),
        )]))

        await self.client.delete(config.places.qdrant_collection, points_selector=selector)

    async def delete_faces(self, fileid: int):
        """Remove all face embeddings for one file (all its hashed pairs)."""

        selector = models.FilterSelector(filter=models.Filter(must=[models.FieldCondition(
            key="fileid",
            match=models.MatchValue(value=int(fileid)),
        )]))

        await self.client.delete(config.face.qdrant_collection, points_selector=selector)

    async def delete(self, fileid: int):
        """Remove one file embedding and all its address + face embeddings."""

        selector = models.PointIdsList(points=[int(fileid)])

        await self.client.delete(config.embedding.qdrant_collection, points_selector=selector)
        await self.delete_places(int(fileid))
        await self.delete_faces(int(fileid))

    def _expected_meta(self):
        """Sentinel payload describing the current embedding space."""

        return {
            "kind": "lens_meta",
            "embedding": {
                "id": config.embedding.model_id,
                "revision": config.embedding.model_revision,
                "version": config.embedding.version,
                "dimension": self.embedding_dim,
                "normalization": "l2",
            },
        }

    def _expected_places_meta(self):
        """Sentinel payload describing the places sentence space."""

        return {
            "kind": "lens_places_meta",
            "sentence": {
                "id": config.sentence_model.model_id,
                "revision": config.sentence_model.model_revision,
                "version": config.sentence_model.version,
                "dimension": self.sentence_dim,
                "normalization": "l2",
            },
        }

    def _expected_faces_meta(self):
        """Sentinel payload describing the face embedding space."""

        return {
            "kind": "lens_faces_meta",
            "face": {
                "det_sha": config.face.det_sha,
                "rec_sha": config.face.rec_sha,
                "version": config.face.version,
                "dimension": self.face_dim,
                "normalization": "l2",
            },
        }

    async def _check_meta(self, collection, point_id, expected, dim):
        """Generic meta guard for any collection; stamp if absent, refuse if changed."""

        points = await self.client.retrieve(collection, ids=[point_id])

        if not points:
            # Unit stub: sentinels need a vector, and all-zero breaks cosine.
            stub = [1.0] + [0.0] * (dim - 1)
            point = models.PointStruct(id=point_id, vector=stub, payload=expected)

            await self.client.upsert(collection, points=[point])
            log.info("stamped fresh %s#%d: %s", collection, point_id, expected)
            return

        actual = points[0].payload or {}
        mismatched = {}

        for key, want in expected.items():
            if key == "kind":
                continue

            got = actual.get(key) or {}

            for field in set(got) | set(want):
                if got.get(field) != want.get(field):
                    mismatched[f"{key}.{field}"] = (got.get(field), want.get(field))

        if actual.get("kind") != expected.get("kind"):
            mismatched["kind"] = (actual.get("kind"), expected.get("kind"))

        if mismatched:
            # Never mix spaces: same dim can still mean incompatible vectors.
            log.error("embedding metadata mismatch in %s: %s", collection, mismatched)
            raise CompatMismatch(f"{collection}: {mismatched}")

        log.info("collection %s compatible: %s", collection, expected)

    @staticmethod
    def _require_unnamed_vectors(info, name):
        """Refuse collections with named vectors; both ours are single-vector."""

        if isinstance(info.config.params.vectors, dict):
            log.error("named vectors in %s, expected a single unnamed space", name)
            raise CompatMismatch(f"{name}: named vectors, expected single unnamed space")
