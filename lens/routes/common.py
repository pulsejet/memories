"""Route guard: 503 until lifespan finishes loading."""

from fastapi import HTTPException

from routes.context import state


def require_ready():
    """Raise 503 unless the models are loaded."""

    if not state.ready:
        raise HTTPException(
            status_code=503,
            detail="model not loaded",
        )
