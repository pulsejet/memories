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


class CompatMismatch(RuntimeError):
    """Stored embedding metadata differs from current config."""


class Store:
    """Qdrant collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, embedding_dim, sentence_dim):
        self.client = client
        self.embedding_dim = embedding_dim
        self.sentence_dim = sentence_dim

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

    async def delete(self, fileid: int):
        """Remove one file embedding and all its address embeddings."""

        selector = models.PointIdsList(points=[int(fileid)])

        await self.client.delete(config.embedding.qdrant_collection, points_selector=selector)
        await self.delete_places(int(fileid))

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
