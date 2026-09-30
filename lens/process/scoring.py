"""Relative score cutoff for embedding search hits."""


def drop_low_scores(hits: list[dict], margin: float) -> list[dict]:
    """Keep hits within a relative margin of the top score; always keeps the top hit."""

    if not hits:
        return hits

    floor = hits[0]["score"] * (1.0 - margin)

    return [h for i, h in enumerate(hits) if i == 0 or h["score"] >= floor]
