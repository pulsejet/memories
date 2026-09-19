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
class Config:  # pylint: disable=too-many-instance-attributes
    """All daemon settings; see ARCH.md config table."""
    nextcloud_url: str
    nc_user: str
    nc_token: str
    qdrant_url: str
    embedding_model_id: str
    embedding_model_revision: str
    model_cache_dir: str
    embedding_version: int
    device: str
    workers: int
    queue_max: int
    score_margin: float
    port: int
    torch_num_threads: int | None


def load_config() -> Config:
    """Build Config from env; raise on missing required or locked values."""
    missing = [v for v in REQUIRED if not os.environ.get(v)]
    if missing:
        raise RuntimeError(f"missing required env vars: {', '.join(missing)}")
    workers = _int("WORKERS", 1)
    if workers != 1:
        raise RuntimeError("WORKERS is locked to 1 for v1")
    threads = os.environ.get("TORCH_NUM_THREADS")
    return Config(
        nextcloud_url=os.environ["NEXTCLOUD_URL"],
        nc_user=os.environ["NC_USER"],
        nc_token=os.environ["NC_TOKEN"],
        qdrant_url=os.environ["QDRANT_URL"],
        embedding_model_id=os.environ.get("EMBEDDING_MODEL_ID", "google/siglip2-base-patch16-256"),
        embedding_model_revision=os.environ["EMBEDDING_MODEL_REVISION"],
        model_cache_dir=os.environ.get("MODEL_CACHE_DIR", "/app/models"),
        embedding_version=_int("EMBEDDING_VERSION", 1),
        device=os.environ.get("DEVICE", "auto"),
        workers=workers,
        queue_max=_int("QUEUE_MAX", 1000),
        score_margin=_float("SCORE_MARGIN", 0.1),
        port=_int("PORT", 47789),
        torch_num_threads=int(threads) if threads else None,
    )


config = load_config()
