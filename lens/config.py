"""Env-only config with .env auto-load; fails fast on missing/invalid values."""

import os
from dataclasses import dataclass

from dotenv import load_dotenv

load_dotenv()

REQUIRED = (
    "NEXTCLOUD_URL",
    "NC_USER",
    "NC_TOKEN",
    "QDRANT_URL",
    "EMBEDDING_MODEL_REVISION",
    "SENTENCE_MODEL_REVISION",
)


def _int(name: str, default: int) -> int:
    """Read env var as int, else default; raise on garbage."""
    try:
        return int(os.environ.get(name, default))
    except ValueError as exc:
        raise RuntimeError(f"{name} must be an integer") from exc


def _float(name: str, default: float) -> float:
    """Read env var as float, else default; raise on garbage."""
    try:
        return float(os.environ.get(name, default))
    except ValueError as exc:
        raise RuntimeError(f"{name} must be a number") from exc


@dataclass(frozen=True)
class EmbeddingConfig:
    """Pinned embedding checkpoint settings."""

    model_id: str
    model_revision: str
    version: int
    qdrant_collection: str
    score_margin: float


@dataclass(frozen=True)
class SentenceConfig:
    """Pinned sentence checkpoint settings."""

    model_id: str
    model_revision: str
    version: int


@dataclass(frozen=True)
class PlacesConfig:
    """Geo place lookup settings (Qdrant places collection)."""

    qdrant_collection: str
    top_k: int
    min_score: float
    score_margin: float


@dataclass(frozen=True)
class Config:  # pylint: disable=too-many-instance-attributes
    """All daemon settings; see ARCH.md config table."""

    nextcloud_url: str
    nc_user: str
    nc_token: str
    qdrant_url: str
    embedding: EmbeddingConfig
    sentence_model: SentenceConfig
    places: PlacesConfig
    model_cache_dir: str
    device: str
    workers: int
    queue_max: int
    port: int
    torch_num_threads: int | None
    index_batch_size: int


def load_config() -> Config:
    """Build Config from env; raise on missing required or locked values."""

    missing = [v for v in REQUIRED if not os.environ.get(v)]
    if missing:
        raise RuntimeError(f"missing required env vars: {', '.join(missing)}")

    workers = _int("WORKERS", 1)
    if workers != 1:
        raise RuntimeError("WORKERS is locked to 1 for v1")

    threads = os.environ.get("TORCH_NUM_THREADS")

    batch_size = _int("INDEX_BATCH_SIZE", 4)
    if batch_size < 1 or batch_size > 32:
        raise RuntimeError("INDEX_BATCH_SIZE must be between 1 and 32")

    embedding = EmbeddingConfig(
        model_id=os.environ.get("EMBEDDING_MODEL_ID", "google/siglip2-base-patch16-256"),
        model_revision=os.environ["EMBEDDING_MODEL_REVISION"],
        version=_int("EMBEDDING_VERSION", 3),
        qdrant_collection=os.environ.get("EMBEDDING_QDRANT_COLLECTION", "lens_images"),
        score_margin=_float("EMBEDDING_SCORE_MARGIN", 0.1),
    )

    sentence_model = SentenceConfig(
        model_id=os.environ.get("SENTENCE_MODEL_ID", "intfloat/multilingual-e5-small"),
        model_revision=os.environ["SENTENCE_MODEL_REVISION"],
        version=_int("SENTENCE_VERSION", 1),
    )

    places_top_k = _int("PLACES_TOP_K", 3)
    if places_top_k < 1 or places_top_k > 32:
        raise RuntimeError("PLACES_TOP_K must be between 1 and 32")

    places_min_score = _float("PLACES_MIN_SCORE", 0.82)
    if places_min_score < 0 or places_min_score > 1:
        raise RuntimeError("PLACES_MIN_SCORE must be between 0 and 1")

    places = PlacesConfig(
        qdrant_collection=os.environ.get("PLACES_QDRANT_COLLECTION", "lens_places"),
        top_k=places_top_k,
        min_score=places_min_score,
        score_margin=_float("PLACES_SCORE_MARGIN", 0.02),
    )

    return Config(
        nextcloud_url=os.environ["NEXTCLOUD_URL"],
        nc_user=os.environ["NC_USER"],
        nc_token=os.environ["NC_TOKEN"],
        qdrant_url=os.environ["QDRANT_URL"],
        embedding=embedding,
        sentence_model=sentence_model,
        places=places,
        model_cache_dir=os.environ.get("MODEL_CACHE_DIR", "/app/models"),
        device=os.environ.get("DEVICE", "auto"),
        workers=workers,
        queue_max=_int("QUEUE_MAX", 100000),
        port=_int("PORT", 47789),
        torch_num_threads=int(threads) if threads else None,
        index_batch_size=batch_size,
    )


config = load_config()
