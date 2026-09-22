"""Env-only config with .env auto-load; fails fast on missing/invalid values."""

import os
from dataclasses import asdict, dataclass

from dotenv import load_dotenv

load_dotenv()

REQUIRED = (
    "NEXTCLOUD_URL",
    "NC_USER",
    "NC_TOKEN",
    "QDRANT_URL",
    "EMBEDDING_MODEL_REVISION",
    "SENTENCE_MODEL_REVISION",
    "SCHEMA_MODEL_REVISION",
    "FACE_DET_URL",
    "FACE_DET_SHA",
    "FACE_REC_URL",
    "FACE_REC_SHA",
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
class SchemaModelConfig:
    """Pinned schema-extraction checkpoint settings."""

    model_id: str
    model_revision: str
    threshold: float


@dataclass(frozen=True)
class PlacesConfig:
    """Geo place lookup settings (Qdrant places collection)."""

    qdrant_collection: str
    top_k: int
    min_score: float
    score_margin: float


@dataclass(frozen=True)
class FaceConfig:  # pylint: disable=too-many-instance-attributes
    """Pinned YuNet/SFace checkpoints plus detection/clustering thresholds."""

    det_url: str
    det_sha: str
    rec_url: str
    rec_sha: str
    version: int
    qdrant_collection: str
    det_threshold: float
    det_max_side: int
    max_distance: float
    min_faces: int
    restore_center_frac: float
    merge_distance: float
    merge_quorum: int
    merge_samples: int
    merge_batch: int
    merge_retry_interval: int
    score_margin: float
    suggest_samples: int
    suggest_ttl: int


@dataclass(frozen=True)
class Config:  # pylint: disable=too-many-instance-attributes
    """All daemon settings; see ARCH.md config table."""

    nextcloud_url: str
    nc_user: str
    nc_token: str
    qdrant_url: str
    embedding: EmbeddingConfig
    sentence_model: SentenceConfig
    schema_model: SchemaModelConfig
    places: PlacesConfig
    face: FaceConfig
    model_cache_dir: str
    device: str
    workers: int
    queue_max: int
    port: int
    torch_num_threads: int | None
    index_batch_size: int


def _face_config() -> FaceConfig:
    """Build the face section from env; raise on garbage."""

    det_threshold = _float("FACE_DET_THRESHOLD", 0.6)
    if det_threshold < 0 or det_threshold > 1:
        raise RuntimeError("FACE_DET_THRESHOLD must be between 0 and 1")

    det_max_side = _int("FACE_DET_MAX_SIDE", 1920)
    if det_max_side < 320 or det_max_side > 8192:
        raise RuntimeError("FACE_DET_MAX_SIDE must be between 320 and 8192")

    max_distance = _float("FACE_MAX_DISTANCE", 0.6)
    if max_distance <= 0 or max_distance > 2:
        raise RuntimeError("FACE_MAX_DISTANCE must be between 0 and 2")

    min_faces = _int("FACE_MIN_FACES", 3)
    if min_faces < 1:
        raise RuntimeError("FACE_MIN_FACES must be at least 1")

    restore_center_frac = _float("FACE_RESTORE_CENTER_FRAC", 0.25)
    if restore_center_frac <= 0 or restore_center_frac > 1:
        raise RuntimeError("FACE_RESTORE_CENTER_FRAC must be between 0 and 1")

    merge_distance = _float("FACE_MERGE_DISTANCE", max_distance)
    if merge_distance <= 0 or merge_distance > max_distance:
        raise RuntimeError("FACE_MERGE_DISTANCE must be between 0 and FACE_MAX_DISTANCE (never looser)")

    score_margin = _float("FACE_SCORE_MARGIN", 0.1)
    if score_margin < 0 or score_margin > 1:
        raise RuntimeError("FACE_SCORE_MARGIN must be between 0 and 1")

    suggest_samples = _int("SUGGEST_SAMPLES", 5)
    if suggest_samples < 1:
        raise RuntimeError("SUGGEST_SAMPLES must be at least 1")

    suggest_ttl = _int("SUGGEST_TTL", 300)
    if suggest_ttl < 0:
        raise RuntimeError("SUGGEST_TTL must not be negative")

    return FaceConfig(
        det_url=os.environ["FACE_DET_URL"],
        det_sha=os.environ["FACE_DET_SHA"],
        rec_url=os.environ["FACE_REC_URL"],
        rec_sha=os.environ["FACE_REC_SHA"],
        version=_int("FACE_VERSION", 1),
        qdrant_collection=os.environ.get("FACE_QDRANT_COLLECTION", "lens_faces"),
        det_threshold=det_threshold,
        det_max_side=det_max_side,
        max_distance=max_distance,
        min_faces=min_faces,
        restore_center_frac=restore_center_frac,
        merge_distance=merge_distance,
        score_margin=score_margin,
        suggest_samples=suggest_samples,
        suggest_ttl=suggest_ttl,
        **asdict(_face_merge_config()),
    )


@dataclass(frozen=True)
class FaceMergeConfig:
    """Merge sweep knobs; flattened into FaceConfig at construction."""

    merge_quorum: int
    merge_samples: int
    merge_batch: int
    merge_retry_interval: int


def _face_merge_config() -> FaceMergeConfig:
    """Merge sweep knobs; split out so _face_config stays under the branch budget."""

    merge_quorum = _int("FACE_MERGE_QUORUM", 2)
    if merge_quorum < 1:
        raise RuntimeError("FACE_MERGE_QUORUM must be at least 1")

    merge_samples = _int("FACE_MERGE_SAMPLES", 5)
    if merge_samples < 1:
        raise RuntimeError("FACE_MERGE_SAMPLES must be at least 1")

    merge_batch = _int("FACE_MERGE_BATCH", 20)
    if merge_batch < 1:
        raise RuntimeError("FACE_MERGE_BATCH must be at least 1")

    merge_retry_interval = _int("FACE_MERGE_RETRY_INTERVAL", 604800)
    if merge_retry_interval < 0:
        raise RuntimeError("FACE_MERGE_RETRY_INTERVAL must not be negative")

    return FaceMergeConfig(
        merge_quorum=merge_quorum,
        merge_samples=merge_samples,
        merge_batch=merge_batch,
        merge_retry_interval=merge_retry_interval,
    )


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
        score_margin=_float("EMBEDDING_SCORE_MARGIN", 0.4),
    )

    sentence_model = SentenceConfig(
        model_id=os.environ.get("SENTENCE_MODEL_ID", "intfloat/multilingual-e5-small"),
        model_revision=os.environ["SENTENCE_MODEL_REVISION"],
        version=_int("SENTENCE_VERSION", 1),
    )

    schema_threshold = _float("SCHEMA_THRESHOLD", 0.7)
    if schema_threshold < 0 or schema_threshold > 1:
        raise RuntimeError("SCHEMA_THRESHOLD must be between 0 and 1")

    schema_model = SchemaModelConfig(
        model_id=os.environ.get("SCHEMA_MODEL_ID", "fastino/gliner2.5-multi-v1"),
        model_revision=os.environ["SCHEMA_MODEL_REVISION"],
        threshold=schema_threshold,
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
        schema_model=schema_model,
        places=places,
        face=_face_config(),
        model_cache_dir=os.environ.get("MODEL_CACHE_DIR", "/app/models"),
        device=os.environ.get("DEVICE", "auto"),
        workers=workers,
        queue_max=_int("QUEUE_MAX", 100000),
        port=_int("PORT", 47789),
        torch_num_threads=int(threads) if threads else None,
        index_batch_size=batch_size,
    )


config = load_config()
