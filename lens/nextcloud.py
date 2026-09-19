"""Nextcloud file-bytes client (blocking; call via to_thread)."""

import logging
from dataclasses import dataclass

import httpx

from config import config

log = logging.getLogger("lens.nextcloud")

TIMEOUT = 30.0


@dataclass(frozen=True)
class FetchResult:
    """Downloaded bytes plus response validators (empty when missing)."""

    data: bytes
    etag: str
    mimetype: str
    epoch: int | None
    dayid: int | None


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

            etag = res.headers.get("etag", "") or ""
            mimetype = res.headers.get("content-type", "") or ""

            try:
                epoch = int(res.headers.get("x-memories-epoch", "") or "")
            except ValueError:
                epoch = None

            try:
                dayid = int(res.headers.get("x-memories-dayid", "") or "")
            except ValueError:
                dayid = None

            chunks = []

            for chunk in res.iter_bytes():
                chunks.append(chunk)

    return FetchResult(
        data=b"".join(chunks),
        etag=etag,
        mimetype=mimetype,
        epoch=epoch,
        dayid=dayid,
    )
