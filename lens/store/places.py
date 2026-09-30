"""Places collection: one sentence vector per (file, place) pair."""

import logging
import uuid
from dataclasses import dataclass

from qdrant_client import AsyncQdrantClient, models

from config import config
from store.base import META_ID, check_meta, ensure_collection, ensure_integer_indexes, require_unnamed_vectors

log = logging.getLogger("lens.store")


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


class PlacesStore:
    """Places collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, dim):
        self.client = client
        self.dim = dim

    async def ensure_collection(self):
        """Create the per-file places collection, indexes, and sentinel; reruns are safe."""

        name = config.places.qdrant_collection
        info = await ensure_collection(self.client, name, self.dim)
        require_unnamed_vectors(info, name)

        # Integer indexes for folder-scoped grouped search + per-file delete.
        await ensure_integer_indexes(self.client, name, info, ("parent_id", "osm_id", "fileid"))

        # Sentinel guard: stamp when absent, refuse when the space differs.
        await check_meta(self.client, name, META_ID, self._expected_meta(), self.dim)

    async def upsert_many(self, points: list[PlacePoint]):
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

    async def search(self, vector, folders, limit):
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

    async def delete_fileid(self, fileid: int):
        """Remove all address embeddings for one file (all its hashed pairs)."""

        selector = models.FilterSelector(filter=models.Filter(must=[models.FieldCondition(
            key="fileid",
            match=models.MatchValue(value=int(fileid)),
        )]))

        await self.client.delete(config.places.qdrant_collection, points_selector=selector)

    def _expected_meta(self):
        """Sentinel payload describing the places sentence space."""

        return {
            "kind": "lens_places_meta",
            "sentence": {
                "id": config.sentence_model.model_id,
                "revision": config.sentence_model.model_revision,
                "version": config.sentence_model.version,
                "dimension": self.dim,
                "normalization": "l2",
            },
        }
