"""Nextcloud file-bytes client (blocking; call via to_thread)."""

import base64
import logging
from dataclasses import dataclass, field, replace
from http.cookiejar import CookieJar

import httpx
import orjson
from dacite import DaciteError, from_dict

from config import config

log = logging.getLogger("lens.nextcloud")

TIMEOUT = 30.0
cookie_jar = CookieJar()


@dataclass(frozen=True)
class Place:
    """One OSM place from the file metadata, leaf-first order kept."""

    osm_id: int
    admin_level: int
    name: str


@dataclass(frozen=True)
class Face:
    """One current face row from SQL, shipped for restore stability."""

    id: int
    x: float
    y: float
    w: float
    h: float
    det_score: float = 0
    cluster_id: int | None = None
    embed_version: int | None = None


@dataclass(frozen=True)
class FileMetadata:
    """File metadata from the X-Memories-Metadata header (empties when missing)."""

    etag: str
    mimetype: str
    epoch: int | None
    dayid: int | None
    places: list[Place]
    mtime: int | None
    parent_id: int | None
    faces: list[Face] = field(default_factory=list)
    owner: str | None = None


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


def fetch_file(fileid: int, *, metadata_only: bool = False) -> FetchResult:
    """Fetch file metadata via HEAD, or download bytes and metadata via GET."""

    url = f"{config.nextcloud_url}/index.php/apps/memories/lens/file/{fileid}"
    method = "HEAD" if metadata_only else "GET"

    with httpx.Client(
        timeout=TIMEOUT,
        auth=(config.nc_user, config.nc_token),
        cookies=cookie_jar,
    ) as client:
        with client.stream(method, url) as res:
            if res.status_code == 401:
                # Token expired/removed: loud, the runbook is re-issuing it.
                log.error("lens service account rejected (401); re-issue via occ user:auth-tokens:add")
                raise AuthError(f"{method} {url} -> 401")

            if res.status_code == 404:
                raise NotFoundError(f"{method} {url} -> 404")

            if res.status_code != 200:
                raise FetchError(f"{method} {url} -> {res.status_code}")

            metadata = parse_metadata(res.headers.get("x-memories-metadata", ""))

            if metadata_only:
                if metadata.mtime is None or metadata.parent_id is None:
                    raise FetchError(f"HEAD {url} -> missing file metadata")

                return FetchResult(data=b"", metadata=metadata)

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
            decoded = orjson.loads(base64.b64decode(value))
        except ValueError:
            decoded = {}

        if isinstance(decoded, dict):
            try:
                meta = from_dict(FileMetadata, decoded)
            except DaciteError:
                pass
            else:
                return _clean(meta)

    return FileMetadata(etag="", mimetype="", epoch=None, dayid=None, places=[], mtime=None, parent_id=None)


def _clean(meta: FileMetadata) -> FileMetadata:
    """Drop placeholder places."""

    return replace(meta, places=[p for p in meta.places if p.osm_id > 0 and p.name])

