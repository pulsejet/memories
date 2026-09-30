"""Nextcloud file-bytes client (blocking; call via to_thread)."""

from nextcloud.client import (
    AuthError,
    FetchError,
    FetchResult,
    FileMetadata,
    NotFoundError,
    Place,
    fetch_file,
    parse_metadata,
)

__all__ = [
    "AuthError",
    "FetchError",
    "FetchResult",
    "FileMetadata",
    "NotFoundError",
    "Place",
    "fetch_file",
    "parse_metadata",
]
