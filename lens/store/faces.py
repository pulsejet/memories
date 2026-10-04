"""Face collection provisioning and file-scoped maintenance."""

from qdrant_client import AsyncQdrantClient

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
