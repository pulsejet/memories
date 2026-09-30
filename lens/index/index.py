"""Batch indexing: fetch → decode → embed → upsert; failures count, never retry."""

import asyncio
import logging
from dataclasses import replace

from nextcloud import fetch_file
from store import FileMeta, PlacePoint, UpsertPoint

log = logging.getLogger("lens.queue")


class Indexer:
    """Index one batch at a time; completion is reported via the done callback."""

    def __init__(self, embedding_model, sentence_model, store, done):
        self.embedding_model = embedding_model
        self.sentence_model = sentence_model
        self.store = store
        self.done = done

    async def handle_batch(self, batch):
        """Fetch concurrently, decode each once, embed in one forward pass."""

        parents = dict(batch)
        pending = await self._fetch_all([fileid for fileid, _ in batch])
        good = await self._decode_all(pending)

        if not good:
            return

        try:
            images = [image for _, _, image in good]
            vectors = await self.embedding_model.embed_pil_images_async(images)
        except Exception as exc:
            for fileid, _, _ in good:
                log.exception("index failed for %d: %s", fileid, exc)
                self.done(fileid, ok=False)
            return

        try:
            await self._ensure_places(good, parents)
        except Exception as exc:
            log.exception("places ensure failed for batch: %s", exc)

        points = []

        for (fileid, res, image), vector in zip(good, vectors):
            points.append(UpsertPoint(
                fileid=fileid,
                vector=vector,
                parent_id=parents[fileid],
                meta=FileMeta(
                    w=image.width,
                    h=image.height,
                    etag=res.metadata.etag,
                    mimetype=res.metadata.mimetype,
                    epoch=res.metadata.epoch,
                    dayid=res.metadata.dayid,
                ),
                osm_ids=[p.osm_id for p in res.metadata.places],
            ))

        try:
            await self.store.embedding.upsert_many(points)
        except Exception as exc:
            for fileid, _, _ in good:
                log.exception("index failed for %d: %s", fileid, exc)
                self.done(fileid, ok=False)
            return

        for fileid, res, image in good:
            log.info(
                "indexed %d (%dx%d %s epoch=%s dayid=%s places=%d)",
                fileid, image.width, image.height, res.metadata.mimetype,
                res.metadata.epoch, res.metadata.dayid, len(res.metadata.places),
            )
            self.done(fileid, ok=True)

    async def _ensure_places(self, good, parents):
        """Embed every (file, place) address; re-index overwrites the same hashed pair."""

        points = []

        for fileid, res, _ in good:
            places = res.metadata.places
            names = [p.name for p in places]

            for idx, place in enumerate(places):
                points.append(PlacePoint(
                    fileid=fileid,
                    parent_id=parents[fileid],
                    osm_id=place.osm_id,
                    vector=[],
                    admin_level=place.admin_level,
                    name=place.name,
                    full_address=", ".join(names[idx:]),
                ))

        if not points:
            return

        addresses = [p.full_address for p in points]
        vectors = await self.sentence_model.embed_passages_async(addresses)

        await self.store.places.upsert_many([
            replace(point, vector=vector) for point, vector in zip(points, vectors)
        ])

    async def _fetch_all(self, fileids):
        """Download one batch concurrently; fetch failures count immediately."""

        results = await asyncio.gather(
            *(asyncio.to_thread(fetch_file, fileid) for fileid in fileids),
            return_exceptions=True,
        )

        pending = []
        for fileid, res in zip(fileids, results):
            if isinstance(res, Exception):
                log.error("index failed for %d: %s", fileid, res, exc_info=res)
                self.done(fileid, ok=False)
            else:
                pending.append((fileid, res))

        return pending

    async def _decode_all(self, pending):
        """Decode each fetch once, off the event loop; decode failures count immediately."""

        images = await asyncio.gather(
            *(asyncio.to_thread(self.embedding_model.decode_image, res.data) for _, res in pending),
            return_exceptions=True,
        )

        good = []
        for (fileid, res), image in zip(pending, images):
            if isinstance(image, Exception):
                log.error("index failed for %d: %s", fileid, image, exc_info=image)
                self.done(fileid, ok=False)
            else:
                good.append((fileid, res, image))

        return good
