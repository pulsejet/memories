"""Split search: geo spans feed the place filter, the rest feeds visual."""

import logging

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from process.geo import match_places, split_query
from routes.common import require_ready
from routes.context import embedding_model, schema_model, sentence_model, state

log = logging.getLogger("lens.app")

router = APIRouter()


class SearchRequest(BaseModel):
    """Text query scoped to parent folders."""

    text: str
    folders: list[int]
    limit: int = Field(default=50, gt=0)


@router.post("/v1/search")
async def search(body: SearchRequest):
    """Split search: geo spans feed the place filter, the rest feeds visual."""

    require_ready()

    if not body.folders:
        raise HTTPException(
            status_code=400,
            detail="folders must not be empty",
        )

    osm_ids = None
    visual = body.text

    try:
        spans = await schema_model.extract_async(body.text)
        geo_text, visual = split_query(body.text, spans)

        if geo_text is not None:
            osm_ids = await match_places(
                text=geo_text,
                sentence_model=sentence_model,
                store=state.store,
                folders=body.folders,
            )
    except Exception:  # pylint: disable=broad-exception-caught
        log.warning("geo split failed, full-text fallback", exc_info=True)
        osm_ids, visual = None, body.text

    vec = await embedding_model.embed_text_async(visual)
    hits = await state.store.embedding.search(
        vector=vec,
        folders=body.folders,
        limit=body.limit,
        osm_ids=osm_ids,
    )

    return {"hits": hits}
