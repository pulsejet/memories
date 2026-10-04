"""Nextcloud file downloads and asynchronous catalog scanning."""

from nextcloud.client import (
    AuthError,
    FetchError,
    FetchResult,
    FileMetadata,
    Face,
    NotFoundError,
    Place,
    fetch_file,
    parse_metadata,
)
from nextcloud.faces import delete_faces, get_faces_batch, post_face_clusters, post_faces
from nextcloud.scan import ScanBatch, ScanFile, fetch_scan_batch

__all__ = [
    "AuthError",
    "Face",
    "FetchError",
    "FetchResult",
    "FileMetadata",
    "NotFoundError",
    "Place",
    "ScanBatch",
    "ScanFile",
    "delete_faces",
    "fetch_file",
    "fetch_scan_batch",
    "get_faces_batch",
    "parse_metadata",
    "post_face_clusters",
    "post_faces",
]
