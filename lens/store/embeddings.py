"""Image embedding collection: one SigLIP vector per file."""

import logging
import time
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone

from qdrant_client import AsyncQdrantClient, models

from config import config
from process.scoring import drop_low_scores
from store.base import (
    META_ID,
    FileStore,
    check_meta,
    ensure_collection,
    ensure_integer_indexes,
    require_unnamed_vectors,
)

log = logging.getLogger("lens.store")

FAILURE_KIND = "lens_failure"
RETRY_INITIAL = 60 * 60
RETRY_MAX = 90 * 24 * 60 * 60


def failure_point_id(fileid: int) -> str:
    """Keep retry state separate from the file's last successful image embedding."""

    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"lens_failure:{fileid}"))


@dataclass(frozen=True)
class FileMeta:
    """Display metadata and catalog mtime stored alongside each embedding."""

    w: int
    h: int
    etag: str
    mimetype: str
    epoch: int | None
    dayid: int | None
    mtime: int | None


@dataclass(frozen=True)
class UpsertPoint:
    """One file embedding with display metadata, ready for Qdrant."""

    fileid: int
    vector: list[float]
    parent_id: int
    meta: FileMeta
    osm_ids: list[int] | None = None


class EmbeddingStore(FileStore):
    """Image collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, dim):
        """Bind the image collection."""

        super().__init__(client, config.embedding.qdrant_collection, dim)

    async def ensure_collection(self):
        """Create collection, indexes, and sentinel step by step; reruns are safe."""

        name = self.collection
        info = await ensure_collection(self.client, name, self.dim)

        # Clean break from the reset named-vectors attempt: refuse those
        # collections instead of failing later at upsert (wipe + reindex).
        require_unnamed_vectors(info, name)

        # Integer index on parent_id for folder-scoped search.
        # Integer index on osm_ids for place-filtered search.
        await ensure_integer_indexes(self.client, name, info, ("parent_id", "osm_ids", "fileid"))

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
                "mtime": point.meta.mtime,
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

        await self.client.upsert(self.collection, points=structs, wait=True)

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
            collection_name=self.collection,
            query=vector,
            query_filter=models.Filter(
                must=must,
                must_not=[models.FieldCondition(key="kind", match=models.MatchValue(value=FAILURE_KIND))],
            ),
            limit=limit,
        )

        hits = [
            {**p.payload, "score": p.score}
            for p in res.points
        ]

        return drop_low_scores(hits, config.embedding.score_margin)

    async def get_failures(self, fileids: list[int]) -> dict[int, dict]:
        """Fetch durable retry counters and deadlines for a batch of files."""

        if not fileids:
            return {}

        points = await self.client.retrieve(
            collection_name=self.collection,
            ids=[failure_point_id(fileid) for fileid in fileids],
            with_payload=True,
            with_vectors=False,
        )

        return {point.payload["fileid"]: point.payload for point in points}

    async def record_failure(self, fileid: int) -> dict:
        """Increase persistent backoff from one hour up to ninety days."""

        previous = (await self.get_failures([fileid])).get(fileid, {})
        attempts = previous.get("attempts", 0) + 1
        delay = min(RETRY_INITIAL * 2 ** (attempts - 1), RETRY_MAX)
        payload = {
            "kind": FAILURE_KIND,
            "fileid": fileid,
            "attempts": attempts,
            "retry_at": int(time.time()) + delay,
        }
        point = models.PointStruct(
            id=failure_point_id(fileid),
            vector=[1.0] + [0.0] * (self.dim - 1),
            payload=payload,
        )
        await self.client.upsert(self.collection, points=[point], wait=True)

        return payload

    async def clear_failures(self, fileids: list[int]):
        """Reset retry history only after successful indexing."""

        if not fileids:
            return

        await self.client.delete(
            collection_name=self.collection,
            points_selector=models.PointIdsList(points=[failure_point_id(fileid) for fileid in fileids]),
            wait=True,
        )

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
