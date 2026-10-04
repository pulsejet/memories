"""Face collection provisioning and file-scoped maintenance."""

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

FACE_KIND = "face"
MARKER_KIND = "face_file"


def face_point_id(face_id: int) -> str:
    """Deterministic point ID from the daemon-minted face ID."""

    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"lens_face:{face_id}"))


def marker_point_id(fileid: int) -> str:
    """Deterministic completion-marker ID, disjoint from face point IDs."""

    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"lens_face_file:{fileid}"))


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
        """Current face IDs stored for one file, excluding the completion marker."""

        return [
            point.payload["face_id"]
            async for point in self.scroll_files([fileid])
            if point.payload.get("kind") == FACE_KIND
        ]

    async def get_markers(self, fileids: list[int]) -> dict[int, dict]:
        """Completion markers for a batch of files, keyed by fileid."""

        if not fileids:
            return {}

        ids = {marker_point_id(fileid): fileid for fileid in fileids}
        points = await self.client.retrieve(
            collection_name=self.collection,
            ids=list(ids),
            with_payload=True,
            with_vectors=False,
        )

        return {ids[point.id]: point.payload for point in points if point.id in ids}

    async def put_marker(
        self,
        fileid: int,
        etag: str,
        mtime: int | None,
        owner_id: str,
        embed_version: int,
        face_count: int,
    ):
        """Stamp a file complete; a fixed dummy vector keeps cosine happy."""

        point = models.PointStruct(
            id=marker_point_id(fileid),
            vector=[1.0] + [0.0] * (self.dim - 1),
            payload={
                "kind": MARKER_KIND,
                "etag": etag,
                "mtime": mtime,
                "owner_id": owner_id,
                "embed_version": embed_version,
                "face_count": face_count,
            },
        )

        await self.client.upsert(self.collection, points=[point], wait=True)

    async def delete_marker(self, fileid: int):
        """Drop a file's completion marker before its results are replaced."""

        await self.delete_points([marker_point_id(fileid)])
