"""Nextcloud face writeback client (blocking; call via to_thread)."""

import logging

import httpx
import orjson

from config import config
from nextcloud.client import TIMEOUT, AuthError, FetchError, cookie_jar

log = logging.getLogger("lens.nextcloud")


def post_faces(fileid: int, owner: str, faces: list[dict]) -> list[dict]:
    """Replace one file's face rows; returns the committed ids and clusters."""

    url = f"{config.nextcloud_url}/index.php/apps/memories/lens/faces"

    with httpx.Client(
        timeout=TIMEOUT,
        auth=(config.nc_user, config.nc_token),
        cookies=cookie_jar,
    ) as client:
        res = client.post(url, json={"fileid": fileid, "owner": owner, "faces": faces})

    if res.status_code == 401:
        log.error("lens service account rejected (401); re-issue via occ user:auth-tokens:add")
        raise AuthError(f"POST {url} -> 401")

    if res.status_code != 200:
        raise FetchError(f"POST {url} -> {res.status_code}")

    try:
        committed = orjson.loads(res.content)["faces"]
    except (ValueError, KeyError, TypeError) as exc:
        raise FetchError(f"POST {url} -> bad faces response") from exc

    if not isinstance(committed, list) or any(not isinstance(row, dict) for row in committed):
        raise FetchError(f"POST {url} -> bad faces response")

    return committed
