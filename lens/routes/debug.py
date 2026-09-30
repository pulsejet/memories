"""Test endpoints: raw vectors, spans, and face boxes without indexing."""

import asyncio

from fastapi import APIRouter, Request

from routes.common import require_ready
from routes.context import embedding_model, face_model, schema_model, sentence_model

router = APIRouter()


@router.post("/v1/embedding/text")
async def embedding_text(body: dict):
    """Embed text for testing; returns the raw vector."""

    require_ready()

    vec = await embedding_model.embed_text_async(body.get("text", ""))

    return {"vector": vec, "dimension": len(vec)}


@router.post("/v1/embedding/image")
async def embedding_image(request: Request):
    """Embed raw image bytes for testing; returns the raw vector."""

    require_ready()

    vec = await embedding_model.embed_image_async(await request.body())

    return {"vector": vec, "dimension": len(vec)}


@router.post("/v1/sentence/query")
async def sentence_query(body: dict):
    """Embed text with the sentence model for testing; returns the raw vector."""

    require_ready()

    vec = await sentence_model.embed_query_async(body.get("text", ""))

    return {"vector": vec, "dimension": len(vec)}


@router.post("/v1/schema/extract")
async def extract_schema(body: dict):
    """Extract geo spans for testing; returns raw spans in model order."""

    require_ready()

    spans = await schema_model.extract_async(body.get("text", ""))

    return {"spans": spans}


@router.post("/v1/faces/detect")
async def detect_faces(request: Request):
    """Detect faces in posted image bytes for tuning; returns boxes in fractions."""

    require_ready()

    image = await asyncio.to_thread(face_model.decode_image, await request.body())
    faces = await face_model.detect_async(image)

    return {"faces": faces}
