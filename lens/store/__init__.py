"""Qdrant vector store facade over the three per-collection sub-stores."""

from qdrant_client import AsyncQdrantClient

from store.base import CompatMismatch
from store.embeddings import EmbeddingStore, FileMeta, UpsertPoint
from store.faces import FacePoint, FacesStore, face_point_id
from store.places import PlacePoint, PlacesStore, place_point_id

__all__ = [
    "CompatMismatch",
    "EmbeddingStore",
    "FacePoint",
    "FacesStore",
    "FileMeta",
    "PlacePoint",
    "PlacesStore",
    "Store",
    "UpsertPoint",
    "face_point_id",
    "place_point_id",
]


class Store:
    """Qdrant collections handle; one sub-store per collection, no delegates."""

    def __init__(self, client: AsyncQdrantClient, embedding_dim, sentence_dim, face_dim):
        self.embedding = EmbeddingStore(client, embedding_dim)
        self.places = PlacesStore(client, sentence_dim)
        self.faces = FacesStore(client, face_dim)
