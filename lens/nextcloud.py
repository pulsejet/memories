"""Nextcloud file-bytes client (blocking; call via to_thread)."""

import base64
import logging
from dataclasses import dataclass, replace

import httpx
import orjson
from dacite import DaciteError, from_dict

from config import config

log = logging.getLogger("lens.nextcloud")

TIMEOUT = 30.0


@dataclass(frozen=True)
class Place:
    """One OSM place from the file metadata, leaf-first order kept."""

    osm_id: int
    admin_level: int
    name: str


@dataclass(frozen=True)
class FileMetadata:
    """File metadata from the X-Memories-Metadata header (empties when missing)."""

    etag: str
    mimetype: str
    epoch: int | None
    dayid: int | None
    places: list[Place]


@dataclass(frozen=True)
class FetchResult:
    """Downloaded bytes plus file metadata."""

    data: bytes
    metadata: FileMetadata


class FetchError(RuntimeError):
    """File fetch failed (network, oversize, or unexpected status)."""


class AuthError(FetchError):
    """Service-account token rejected (401); re-issue via occ."""


class NotFoundError(FetchError):
    """No such fileid (404)."""


def fetch_file(fileid: int) -> FetchResult:
    """Download raw file bytes plus validators for one fileid; raise on any failure."""

    url = f"{config.nextcloud_url}/index.php/apps/memories/lens/file/{fileid}"

    with httpx.Client(timeout=TIMEOUT, auth=(config.nc_user, config.nc_token)) as client:
        with client.stream("GET", url) as res:
            if res.status_code == 401:
                # Token expired/removed: loud, the runbook is re-issuing it.
                log.error("lens service account rejected (401); re-issue via occ user:auth-tokens:add")
                raise AuthError(f"GET {url} -> 401")

            if res.status_code == 404:
                raise NotFoundError(f"GET {url} -> 404")

            if res.status_code != 200:
                raise FetchError(f"GET {url} -> {res.status_code}")

            metadata = parse_metadata(res.headers.get("x-memories-metadata", "") or "")

            chunks = []

            for chunk in res.iter_bytes():
                chunks.append(chunk)

    return FetchResult(
        data=b"".join(chunks),
        metadata=metadata,
    )


def parse_metadata(value: str) -> FileMetadata:
    """Decode the base64 JSON metadata header; garbage yields empty metadata, never raises."""

    if value:
        try:
            decoded = orjson.loads(base64.b64decode(value))  # pylint: disable=no-member
        except ValueError:
            decoded = {}

        if isinstance(decoded, dict):
            try:
                meta = from_dict(FileMetadata, decoded)
            except DaciteError:
                pass
            else:
                return replace(meta, places=[p for p in meta.places if p.osm_id > 0 and p.name])

    return FileMetadata(etag="", mimetype="", epoch=None, dayid=None, places=[])
