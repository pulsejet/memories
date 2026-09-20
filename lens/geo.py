"""Geo split: proper-noun spans to place query + visual remainder, place matching."""

from typing import TYPE_CHECKING

from config import config

if TYPE_CHECKING:
    from schema import Span
    from sentence import SentenceModel
    from store import Store


def split_query(text: str, spans: list["Span"]) -> tuple[str | None, str]:
    """(geo_text|None, visual_text): NMS spans, join geo, remainder minus spans."""

    kept: list["Span"] = []

    for span in sorted(spans, key=lambda s: s.score, reverse=True):
        if any(span.start < k.end and k.start < span.end for k in kept):
            continue

        kept.append(span)

    kept.sort(key=lambda s: s.start)

    if not kept:
        return None, text

    geo = ", ".join(text[s.start:s.end] for s in kept)

    parts = []
    pos = 0

    for span in kept:
        parts.append(text[pos:span.start])
        pos = span.end

    parts.append(text[pos:])
    remainder = " ".join(" ".join(parts).split())

    if not any(len(token) > 2 for token in remainder.split()):
        return geo, text

    return geo, remainder


async def match_places(
    text: str,
    sentence_model: "SentenceModel",
    store: "Store",
    folders: list[int],
) -> list[int] | None:
    """osm_ids of top places matching the query in folders; None = visual fallback."""

    query = await sentence_model.embed_query_async(text)
    hits = await store.search_places(query, folders=folders, limit=config.places.top_k)

    if not hits:
        return None

    floor = max(config.places.min_score, hits[0]["score"] - config.places.score_margin)
    matched = [h["osm_id"] for h in hits if h["score"] >= floor]

    return matched or None
