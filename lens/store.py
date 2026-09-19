"""Qdrant vector store with embedding-compat guard."""

import logging
from dataclasses import dataclass
from datetime import datetime, timezone

from qdrant_client import AsyncQdrantClient, models

from config import config

log = logging.getLogger("lens.store")

# Nextcloud fileids are positive, so id 0 never collides with real points.
META_ID = 0


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


class CompatMismatch(RuntimeError):
    """Stored embedding metadata differs from current config."""


class Store:
    """Qdrant collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, embedding_dim):
        self.client = client
        self.embedding_dim = embedding_dim

    async def ensure_embedding_collection(self):
        """Create collection, index, and sentinel step by step; reruns are safe."""

        name = config.embedding.qdrant_collection

        # Collection with cosine vectors sized to the embedding dim.
        if not await self.client.collection_exists(name):
            params = models.VectorParams(size=self.embedding_dim, distance=models.Distance.COSINE)
            await self.client.create_collection(name, vectors_config=params)
            log.info("created collection %s", name)

        info = await self.client.get_collection(name)

        # Integer index on parent_id for folder-scoped search.
        if "parent_id" not in (info.payload_schema or {}):
            await self.client.create_payload_index(
                collection_name=name,
                field_name="parent_id",
                field_schema=models.PayloadSchemaType.INTEGER,
            )
            log.info("indexed parent_id in %s", name)

        # Sentinel guard: stamp when absent, refuse when the space differs.
        await self._check_meta(name, META_ID, self._expected_meta())

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

            structs.append(
                models.PointStruct(
                    id=int(point.fileid),
                    vector=point.vector,
                    payload=payload,
                ),
            )

        await self.client.upsert(config.embedding.qdrant_collection, points=structs)

    async def search(self, vector, folders, limit):
        """Nearest vectors scoped to parent folders, score desc."""

        # Sentinel has no parent_id, so the filter excludes it automatically.
        cond = models.FieldCondition(key="parent_id", match=models.MatchAny(any=folders))
        filtr = models.Filter(must=[cond])

        res = await self.client.query_points(
            collection_name=config.embedding.qdrant_collection,
            query=vector,
            query_filter=filtr,
            limit=limit,
        )

        return [
            {**p.payload, "score": p.score}
            for p in res.points
        ]

    async def delete(self, fileid: int):
        """Remove one file embedding."""

        selector = models.PointIdsList(points=[int(fileid)])

        await self.client.delete(config.embedding.qdrant_collection, points_selector=selector)

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

    async def _check_meta(self, collection, point_id, expected):
        """Generic meta guard for any collection; stamp if absent, refuse if changed."""

        points = await self.client.retrieve(collection, ids=[point_id])

        if not points:
            # Unit stub: sentinels need a vector, and all-zero breaks cosine.
            stub = [1.0] + [0.0] * (self.embedding_dim - 1)
            point = models.PointStruct(id=point_id, vector=stub, payload=expected)

            await self.client.upsert(collection, points=[point])
            log.info("stamped fresh %s#%d: %s", collection, point_id, expected)
            return

        actual = points[0].payload or {}
        got = actual.get("embedding") or {}
        want = expected.get("embedding") or {}

        mismatched = {k: (got.get(k), want.get(k)) for k in set(got) | set(want) if got.get(k) != want.get(k)}

        if actual.get("kind") != expected.get("kind"):
            mismatched["kind"] = (actual.get("kind"), expected.get("kind"))

        if mismatched:
            # Never mix spaces: same dim can still mean incompatible vectors.
            log.error("embedding metadata mismatch in %s: %s", collection, mismatched)
            raise CompatMismatch(f"{collection}: {mismatched}")

        log.info("collection %s compatible: %s", collection, expected)
