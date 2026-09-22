"""Shared Qdrant collection guards: sentinel compat + index helpers."""

import logging

from qdrant_client import AsyncQdrantClient, models

log = logging.getLogger("lens.store")

# Nextcloud fileids are positive, so id 0 never collides with real points.
META_ID = 0

# Index builds on large collections take a while; the client default (5s) trips.
PAYLOAD_INDEX_TIMEOUT = 120


class CompatMismatch(RuntimeError):
    """Stored embedding metadata differs from current config."""


async def ensure_collection(client: AsyncQdrantClient, name, dim):
    """Create the cosine collection when absent; return its info."""

    if not await client.collection_exists(name):
        params = models.VectorParams(size=dim, distance=models.Distance.COSINE)
        await client.create_collection(name, vectors_config=params)
        log.info("created collection %s", name)

    return await client.get_collection(name)


async def check_meta(client: AsyncQdrantClient, collection, point_id, expected, dim):
    """Generic meta guard for any collection; stamp if absent, refuse if changed."""

    points = await client.retrieve(collection, ids=[point_id])

    if not points:
        # Unit stub: sentinels need a vector, and all-zero breaks cosine.
        stub = [1.0] + [0.0] * (dim - 1)
        point = models.PointStruct(id=point_id, vector=stub, payload=expected)

        await client.upsert(collection, points=[point])
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


def require_unnamed_vectors(info, name):
    """Refuse collections with named vectors; all of ours are single-vector."""

    if isinstance(info.config.params.vectors, dict):
        log.error("named vectors in %s, expected a single unnamed space", name)
        raise CompatMismatch(f"{name}: named vectors, expected single unnamed space")


async def ensure_integer_indexes(client: AsyncQdrantClient, name, info, fields):
    """Create missing integer payload indexes; reruns are safe."""

    for field in fields:
        if field not in (info.payload_schema or {}):
            await client.create_payload_index(
                collection_name=name,
                field_name=field,
                field_schema=models.PayloadSchemaType.INTEGER,
                timeout=PAYLOAD_INDEX_TIMEOUT,
            )
            log.info("indexed %s in %s", field, name)


async def ensure_keyword_indexes(client: AsyncQdrantClient, name, info, fields):
    """Create missing keyword payload indexes; reruns are safe."""

    for field in fields:
        if field not in (info.payload_schema or {}):
            await client.create_payload_index(
                collection_name=name,
                field_name=field,
                field_schema=models.PayloadSchemaType.KEYWORD,
                timeout=PAYLOAD_INDEX_TIMEOUT,
            )
            log.info("indexed %s in %s", field, name)
