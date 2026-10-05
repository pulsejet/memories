"""Face collection provisioning and file-scoped maintenance."""

import logging
import time
import uuid
from dataclasses import dataclass

from qdrant_client import AsyncQdrantClient, models

from config import config
from store.base import (
    META_ID,
    FileStore,
    check_meta,
    ensure_collection,
    ensure_integer_indexes,
    ensure_keyword_indexes,
    require_unnamed_vectors,
)

log = logging.getLogger("lens.store")

FACE_KIND = "face"


def face_point_id(face_id: int) -> str:
    """Deterministic point ID from the daemon-minted face ID."""

    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"lens_face:{face_id}"))


def _chunks(ids: list[str], size: int):
    """Split point IDs for bounded retrieve calls."""

    for offset in range(0, len(ids), size):
        yield ids[offset:offset + size]


def _usable_vector(vector) -> bool:
    """Stored vectors must be finite with nonzero norm; anything else is reindexed."""

    if not vector:
        return False

    return all(v == v and v not in (float("inf"), float("-inf")) for v in vector) and any(
        v != 0 for v in vector
    )


@dataclass(frozen=True)
class FacePoint:
    """One committed detection with its vector, ready for Qdrant."""

    face_id: int
    fileid: int
    parent_id: int
    owner_id: str
    embed_version: int
    vector: list[float]
    x: float
    y: float
    w: float
    h: float
    det_score: float
    cluster_id: int | None
    etag: str
    mtime: int | None


class FacesStore(FileStore):
    """Faces collection handle; refuses to mix embedding spaces."""

    def __init__(self, client: AsyncQdrantClient, dim):
        """Bind the faces collection."""

        super().__init__(client, config.face.qdrant_collection, dim)

    async def ensure_collection(self):
        """Create the per-face collection, indexes, and sentinel; reruns are safe."""

        name = self.collection
        info = await ensure_collection(self.client, name, self.dim)
        require_unnamed_vectors(info, name)

        await ensure_integer_indexes(self.client, name, info, ("parent_id", "fileid", "cluster_id"))
        await ensure_keyword_indexes(self.client, name, info, ("owner_id",))
        await check_meta(self.client, name, META_ID, self._expected_meta(), self.dim)

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

    async def upsert_faces(self, points: list[FacePoint]):
        """Store committed detections; an empty batch writes nothing."""

        started = time.monotonic()
        if not points:
            return

        structs = []

        for point in points:
            payload = {
                "kind": FACE_KIND,
                "face_id": point.face_id,
                "fileid": point.fileid,
                "parent_id": point.parent_id,
                "owner_id": point.owner_id,
                "embed_version": point.embed_version,
                "x": point.x,
                "y": point.y,
                "w": point.w,
                "h": point.h,
                "det_score": point.det_score,
                "etag": point.etag,
                "mtime": point.mtime,
            }

            if point.cluster_id is not None:
                payload["cluster_id"] = point.cluster_id

            structs.append(
                models.PointStruct(
                    id=face_point_id(point.face_id),
                    vector=point.vector,
                    payload=payload,
                ),
            )

        await self.client.upsert(self.collection, points=structs, wait=True)
        log.info("insert faces n=%d elapsed=%.2fs", len(structs), time.monotonic() - started)

    async def delete_points(self, point_ids: list[str]):
        """Remove obsolete points by ID; an empty batch writes nothing."""

        if not point_ids:
            return

        await self.client.delete(
            collection_name=self.collection,
            points_selector=models.PointIdsList(points=point_ids),
            wait=True,
        )

    async def get_file_face_ids(self, fileid: int) -> list[int]:
        """Current face IDs stored for one file."""

        return [
            point.payload["face_id"]
            async for point in self.scroll_files([fileid])
            if point.payload.get("kind") == FACE_KIND
        ]

    async def get_batch_vectors(self, faces: list) -> dict[int, list[float]]:
        """Vectors for due faces whose stored point still matches SQL scope/version.

        Faces with missing vectors, mismatched payloads or unusable vectors are
        skipped: the single pipeline reindexes those files fully on its next
        pass, grouping never repairs indexing inline.
        """

        ids = [face_point_id(face.id) for face in faces]
        by_id = {face.id: face for face in faces}
        vectors = {}

        for chunk in _chunks(ids, 256):
            points = await self.client.retrieve(
                collection_name=self.collection,
                ids=chunk,
                with_payload=True,
                with_vectors=True,
            )

            for point in points:
                payload = point.payload or {}
                face_id = payload.get("face_id")
                face = by_id.get(face_id)

                if (
                    face is None
                    or payload.get("kind") != FACE_KIND
                    or payload.get("fileid") != face.fileid
                    or payload.get("owner_id") != face.owner
                    or payload.get("embed_version") != face.embed_version
                    or not _usable_vector(point.vector)
                ):
                    continue

                vectors[face_id] = list(point.vector)

        return vectors

    async def search_assigned_batch(
        self, vectors: list[list[float]], owner: str, version: int, top_k: int,
    ) -> list[list[dict]]:
        """Nearest assigned faces for many vectors in few round trips.

        Unassigned points cannot match: the assigned filter is server-side so
        they never fill the per-query result limit.
        """

        filtr = models.Filter(must=[
            models.FieldCondition(key="kind", match=models.MatchValue(value=FACE_KIND)),
            models.FieldCondition(key="owner_id", match=models.MatchValue(value=owner)),
            models.FieldCondition(key="embed_version", match=models.MatchValue(value=version)),
        ], must_not=[
            models.FieldCondition(key="cluster_id", is_null=True),
            models.FieldCondition(key="cluster_id", is_empty=True),
        ])

        hits = []

        for offset in range(0, len(vectors), 128):
            res = await self.client.query_batch_points(
                collection_name=self.collection,
                requests=[
                    models.QueryRequest(
                        query=vector,
                        filter=filtr,
                        limit=top_k,
                        with_payload=["face_id", "cluster_id"],
                        with_vector=False,
                    )
                    for vector in vectors[offset:offset + 128]
                ],
            )
            hits.extend(
                [point.payload for point in response.points if (point.payload or {}).get("cluster_id")]
                for response in res
            )

        return hits

    async def sample_clusters(
        self, cluster_ids: list[int], owner: str, version: int, limit: int,
    ) -> dict[int, dict[int, list[float]]]:
        """Deterministic member vectors from distinct files for many clusters at once."""

        if not cluster_ids:
            return {}

        filtr = models.Filter(must=[
            models.FieldCondition(key="kind", match=models.MatchValue(value=FACE_KIND)),
            models.FieldCondition(key="owner_id", match=models.MatchValue(value=owner)),
            models.FieldCondition(key="embed_version", match=models.MatchValue(value=version)),
            models.FieldCondition(key="cluster_id", match=models.MatchAny(any=cluster_ids)),
        ])

        members: dict[int, set] = {}

        async for point in self._scroll(filtr):
            payload = point.payload or {}

            if payload.get("face_id") and payload.get("fileid") and payload.get("cluster_id") is not None:
                members.setdefault(payload["cluster_id"], set()).add((payload["face_id"], payload["fileid"]))

        # Stable order, one member per file; there is no permanent seed class.
        wanted: dict[str, tuple[int, int]] = {}

        for cluster_id in cluster_ids:
            files = set()

            for face_id, fileid in sorted(members.get(cluster_id, ())):
                if fileid not in files:
                    files.add(fileid)
                    wanted[face_point_id(face_id)] = (cluster_id, face_id)

                if len(files) >= limit:
                    break

        samples: dict[int, dict[int, list[float]]] = {}

        for chunk in _chunks(list(wanted), 256):
            points = await self.client.retrieve(
                collection_name=self.collection,
                ids=chunk,
                with_payload=True,
                with_vectors=True,
            )

            for point in points:
                payload = point.payload or {}
                entry = wanted.get(point.id)

                if entry is not None and _usable_vector(point.vector):
                    cluster_id, face_id = entry
                    samples.setdefault(cluster_id, {})[face_id] = list(point.vector)

        return samples

    async def set_face_clusters(self, mapping: dict[int, int]):
        """Stamp cluster payloads, but only on points that are still unassigned.

        A concurrent manual correction wins: its inline payload update already
        assigned the point, so a stale grouping outcome never clobbers it here.
        """

        if not mapping:
            return

        points = await self.client.retrieve(
            collection_name=self.collection,
            ids=[face_point_id(face_id) for face_id in mapping],
            with_payload=["kind", "face_id", "cluster_id"],
            with_vectors=False,
        )
        by_cluster = {}

        for point in points:
            payload = point.payload or {}
            face_id = payload.get("face_id", 0)

            if (
                payload.get("kind") == FACE_KIND
                and payload.get("cluster_id") is None
                and mapping.get(face_id) is not None
            ):
                by_cluster.setdefault(mapping[face_id], []).append(point.id)

        for cluster_id, point_ids in by_cluster.items():
            await self.client.set_payload(
                collection_name=self.collection,
                payload={"cluster_id": cluster_id},
                points=point_ids,
                wait=True,
            )

    async def reassign_clusters(self, mapping: dict[int, int | None]) -> int:
        """Overwrite cluster payloads from a user correction; intent wins.

        Unlike grouping stamps, this applies unconditionally: SQL already
        moved these faces, Qdrant must follow so the next grouping pass
        samples them under the right cluster. A null target clears the key,
        which reads back as unassigned everywhere else.
        """

        by_cluster: dict[int | None, list] = {}

        for face_id, cluster_id in mapping.items():
            by_cluster.setdefault(cluster_id, []).append(face_point_id(face_id))

        for cluster_id, point_ids in by_cluster.items():
            await self.client.set_payload(
                collection_name=self.collection,
                payload={"cluster_id": cluster_id},
                points=point_ids,
                wait=True,
            )

        return len(mapping)
