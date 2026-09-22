"""Image embedding collection: one SigLIP vector per file."""

import logging
from dataclasses import dataclass
from datetime import datetime, timezone

from qdrant_client import AsyncQdrantClient, models

from config import config
from process.scoring import drop_low_scores
from store.base import META_ID, check_meta, ensure_collection, ensure_integer_indexes, require_unnamed_vectors

log = logging.getLogger("lens.store")


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


class EmbeddingStore:
    """Image collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, dim):
        self.client = client
        self.dim = dim

    async def ensure_collection(self):
        """Create collection, indexes, and sentinel step by step; reruns are safe."""

        name = config.embedding.qdrant_collection
        info = await ensure_collection(self.client, name, self.dim)

        # Clean break from the reset named-vectors attempt: refuse those
        # collections instead of failing later at upsert (wipe + reindex).
        require_unnamed_vectors(info, name)

        # Integer index on parent_id for folder-scoped search.
        # Integer index on osm_ids for place-filtered search.
        await ensure_integer_indexes(self.client, name, info, ("parent_id", "osm_ids"))

        # Sentinel guard: stamp when absent, refuse when the space differs.
        await check_meta(self.client, name, META_ID, self._expected_meta(), self.dim)

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
                "dimension": self.dim,
                "normalization": "l2",
            },
        }
