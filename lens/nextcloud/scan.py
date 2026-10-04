"""Authenticated catalog batches for daemon reconciliation."""

import httpx
from pydantic import BaseModel, Field, model_validator

from config import config
from nextcloud.client import TIMEOUT, cookie_jar


class ScanFile(BaseModel):
    """Current catalog metadata for one file."""

    fileid: int = Field(gt=0)
    parentid: int
    mtime: int
    etag: str
    isvideo: bool


class ScanBatch(BaseModel):
    """One inclusive catalog range; a null end covers the remaining tail."""

    start: int = Field(gt=0)
    end: int | None
    done: bool
    files: list[ScanFile]

    @model_validator(mode="after")
    def validate_range(self):
        """Reject malformed ranges before they can drive cleanup."""

        if self.done != (self.end is None) or (self.end is not None and self.end < self.start):
            raise ValueError("invalid scan range")

        ids = [file.fileid for file in self.files]
        if ids != sorted(set(ids)) or any(i < self.start or (self.end is not None and i > self.end) for i in ids):
            raise ValueError("scan files must be ordered, unique, and within the range")

        return self


async def fetch_scan_batch() -> ScanBatch:
    """Allocate the next PHP batch; HTTP or validation failures never become empty batches."""

    url = f"{config.nextcloud_url}/index.php/apps/memories/lens/scan"

    async with httpx.AsyncClient(
        timeout=TIMEOUT,
        auth=(config.nc_user, config.nc_token),
        cookies=cookie_jar,
    ) as client:
        response = await client.post(url)
        response.raise_for_status()

        return ScanBatch.model_validate(response.json())
