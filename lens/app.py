"""Memories Lens daemon: index API, search API, health/stats."""

import asyncio
import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, Path, Request
from qdrant_client import AsyncQdrantClient

from config import config
from embedding import EmbeddingModel
from face import FaceModel
from queueing import IndexQueue
from schema import SchemaModel
from sentence import SentenceModel
from state import IndexRequest, SearchRequest, State
from store import CompatMismatch, Store
import geo

log = logging.getLogger("lens.app")

embedding_model = EmbeddingModel()
sentence_model = SentenceModel()
schema_model = SchemaModel()
face_model = FaceModel()
state = State()


@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Load models, ensure collections, start worker; mismatch is fatal."""

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s: %(message)s",
    )

    await asyncio.to_thread(embedding_model.ensure_snapshot)
    await asyncio.to_thread(embedding_model.load)
    await asyncio.to_thread(sentence_model.ensure_snapshot)
    await asyncio.to_thread(sentence_model.load)
    await asyncio.to_thread(schema_model.ensure_snapshot)
    await asyncio.to_thread(schema_model.load)
    await asyncio.to_thread(face_model.ensure_snapshot)
    await asyncio.to_thread(face_model.load)

    client = AsyncQdrantClient(url=config.qdrant_url)
    store = Store(
        client=client,
        embedding_dim=embedding_model.dim(),
        sentence_dim=sentence_model.dim(),
    )
    state.store = store

    index_queue = IndexQueue(maxsize=config.queue_max)
    state.index_queue = index_queue
    worker = index_queue.run(
        embedding_model=embedding_model,
        sentence_model=sentence_model,
        store=store,
    )

    try:
        await store.ensure_embedding_collection()
        await store.ensure_places_collection()
    except CompatMismatch:
        worker.cancel()
        await client.close()
        raise

    except Exception:  # pylint: disable=broad-exception-caught
        log.exception("qdrant unreachable, staying degraded")
    else:
        state.qdrant = "ok"
        state.ready = True

    yield
    worker.cancel()
    await client.close()


app = FastAPI(title="Memories Lens", lifespan=lifespan)


@app.get("/v1/health")
def health():
    """Liveness + models/qdrant readiness; degraded until wired."""

    return {
        "status": "ok" if state.ready else "degraded",
        "embedding_model": {
            "id": config.embedding.model_id,
            "revision": config.embedding.model_revision,
            "version": config.embedding.version,
            "device": embedding_model.device(),
            "dimension": embedding_model.dim() or None,
            "batch_size": config.index_batch_size,
        },
        "sentence_model": {
            "id": config.sentence_model.model_id,
            "revision": config.sentence_model.model_revision,
            "version": config.sentence_model.version,
            "device": sentence_model.device(),
            "dimension": sentence_model.dim() or None,
        },
        "schema_model": {
            "id": config.schema_model.model_id,
            "revision": config.schema_model.model_revision,
            "threshold": config.schema_model.threshold,
            "device": schema_model.device(),
        },
        "face": {
            "det_url": config.face.det_url,
            "det_sha": config.face.det_sha,
            "rec_url": config.face.rec_url,
            "rec_sha": config.face.rec_sha,
            "version": config.face.version,
            "qdrant_collection": config.face.qdrant_collection,
            "dimension": face_model.dim() or None,
            "device": face_model.device(),
            "det_threshold": config.face.det_threshold,
            "det_max_side": config.face.det_max_side,
            "max_distance": config.face.max_distance,
            "min_faces": config.face.min_faces,
            "restore_center_frac": config.face.restore_center_frac,
            "merge_distance": config.face.merge_distance,
            "merge_quorum": config.face.merge_quorum,
            "merge_samples": config.face.merge_samples,
            "merge_batch": config.face.merge_batch,
            "merge_retry_interval": config.face.merge_retry_interval,
        },
        "qdrant": state.qdrant,
    }


@app.get("/v1/stats")
def stats():
    """Queue depth, in-flight fileids, and index/failure counters."""

    queue = state.index_queue

    return {
        "queue_depth": queue.depth,
        "in_flight": sorted(queue.in_flight),
        "indexed_total": queue.indexed_total,
        "failed_total": queue.failed_total,
    }


@app.post("/v1/index", status_code=202)
def index(body: IndexRequest):
    """Enqueue a file; 429 when the queue is full."""

    try:
        state.index_queue.enqueue(
            fileid=body.fileid,
            parent_id=body.parent_id,
        )
    except asyncio.QueueFull as exc:
        raise HTTPException(
            status_code=429,
            detail="queue full",
            headers={"Retry-After": "5"},
        ) from exc

    return {"fileid": body.fileid, "status": "queued"}


@app.delete("/v1/index/{fileid}")
async def delete_index(fileid: int = Path(gt=0)):
    """Delete a point and drop its queued entry, if any."""

    await state.store.delete(fileid)
    state.index_queue.drop(fileid)

    return {"fileid": fileid, "status": "deleted"}


@app.post("/v1/search")
async def search(body: SearchRequest):
    """Split search: geo spans feed the place filter, the rest feeds visual."""

    _require_ready()

    if not body.folders:
        raise HTTPException(
            status_code=400,
            detail="folders must not be empty",
        )

    osm_ids = None
    visual = body.text

    try:
        spans = await schema_model.extract_async(body.text)
        geo_text, visual = geo.split_query(body.text, spans)

        if geo_text is not None:
            osm_ids = await geo.match_places(
                text=geo_text,
                sentence_model=sentence_model,
                store=state.store,
                folders=body.folders,
            )
    except Exception:  # pylint: disable=broad-exception-caught
        log.warning("geo split failed, full-text fallback", exc_info=True)
        osm_ids, visual = None, body.text

    vec = await embedding_model.embed_text_async(visual)
    hits = await state.store.search(
        vector=vec,
        folders=body.folders,
        limit=body.limit,
        osm_ids=osm_ids,
    )

    return {"hits": hits}


@app.post("/v1/embedding/text")
async def embedding_text(body: dict):
    """Embed text for testing; returns the raw vector."""

    _require_ready()

    vec = await embedding_model.embed_text_async(body.get("text", ""))

    return {"vector": vec, "dimension": len(vec)}


@app.post("/v1/embedding/image")
async def embedding_image(request: Request):
    """Embed raw image bytes for testing; returns the raw vector."""

    _require_ready()

    vec = await embedding_model.embed_image_async(await request.body())

    return {"vector": vec, "dimension": len(vec)}


@app.post("/v1/sentence/query")
async def sentence_query(body: dict):
    """Embed text with the sentence model for testing; returns the raw vector."""

    _require_ready()

    vec = await sentence_model.embed_query_async(body.get("text", ""))

    return {"vector": vec, "dimension": len(vec)}


@app.post("/v1/schema/extract")
async def extract_schema(body: dict):
    """Extract geo spans for testing; returns raw spans in model order."""

    _require_ready()

    spans = await schema_model.extract_async(body.get("text", ""))

    return {"spans": spans}


@app.post("/v1/faces/detect")
async def detect_faces(request: Request):
    """Detect faces in posted image bytes for tuning; returns boxes in fractions."""

    _require_ready()

    image = await asyncio.to_thread(face_model.decode_image, await request.body())
    faces = await face_model.detect_async(image)

    return {"faces": faces}


def _require_ready():
    """Raise 503 unless the models are loaded."""

    if not state.ready:
        raise HTTPException(
            status_code=503,
            detail="model not loaded",
        )
