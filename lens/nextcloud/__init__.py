"""Nextcloud file downloads and asynchronous catalog scanning."""

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
from nextcloud.scan import ScanBatch, ScanFile, fetch_scan_batch

__all__ = [
    "AuthError",
    "FetchError",
    "FetchResult",
    "FileMetadata",
    "NotFoundError",
    "Place",
    "ScanBatch",
    "ScanFile",
    "fetch_file",
    "fetch_scan_batch",
    "parse_metadata",
]
