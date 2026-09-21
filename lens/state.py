"""Daemon runtime state and API models."""

from dataclasses import dataclass
from typing import TYPE_CHECKING

from pydantic import BaseModel, Field

if TYPE_CHECKING:
    from queueing import IndexQueue
    from store import Store


class IndexRequest(BaseModel):
    """One index job: file to embed and its parent folder."""

    fileid: int = Field(gt=0)
    parent_id: int


class SearchRequest(BaseModel):
    """Text query scoped to parent folders."""

    text: str
    folders: list[int]
    limit: int = Field(default=50, gt=0)


@dataclass
class State:
    """Mutable runtime handles; filled during lifespan, read by routes."""

    ready: bool = False
    qdrant: str = "unknown"
    store: "Store | None" = None
    index_queue: "IndexQueue | None" = None
