"""Route assembly for the Lens daemon."""

from routes.debug import router as debug_router
from routes.health import router as health_router
from routes.index import router as index_router
from routes.search import router as search_router

routers = [health_router, index_router, search_router, debug_router]
