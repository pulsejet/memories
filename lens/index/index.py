"""Batch indexing: fetch → decode → embed → upsert, with persistent failure backoff."""

import asyncio
import logging
import time
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

        try:
            failures = await self.store.embedding.get_failures([fileid for fileid, _ in batch])
        except Exception:
            log.exception("cannot read retry state; deferring index batch")
            for fileid, _ in batch:
                self.done(fileid, ok=None)
            return

        parents = dict(batch)
        now = int(time.time())
        for fileid, failure in failures.items():
            if failure["retry_at"] > now:
                log.info("index deferred for %d until %d", fileid, failure["retry_at"])
                del parents[fileid]
                self.done(fileid, ok=None)

        pending = await self._fetch_all(list(parents))
        good = await self._decode_all(pending)

        if not good:
            return

        try:
            images = [image for _, _, image in good]
            vectors = await self.embedding_model.embed_pil_images_async(images)
        except Exception as exc:
            for fileid, _, _ in good:
                await self._fail(fileid, exc)
            return

        places_error = None
        try:
            await self._ensure_places(good, parents)
        except Exception as exc:
            places_error = exc

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
                    mtime=res.metadata.mtime,
                    mimetype=res.metadata.mimetype,
                    epoch=res.metadata.epoch,
                    dayid=res.metadata.dayid,
                ),
                osm_ids=[p.osm_id for p in res.metadata.places],
            ))

        try:
            await self.store.embedding.upsert_many(points)
            if places_error is None:
                await self.store.embedding.clear_failures([p.fileid for p in points if p.fileid in failures])
        except Exception as exc:
            for fileid, _, _ in good:
                await self._fail(fileid, exc)
            return

        for fileid, res, image in good:
            if places_error is not None:
                await self._fail(fileid, places_error)
                continue

            log.info(
                "indexed %d (%dx%d %s epoch=%s dayid=%s places=%d)",
                fileid, image.width, image.height, res.metadata.mimetype,
                res.metadata.epoch, res.metadata.dayid, len(res.metadata.places),
            )
            self.done(fileid, ok=True)

    async def _fail(self, fileid, error):
        """Persist retry state before releasing the file from the indexing queue."""

        log.error("index failed for %d: %s", fileid, error, exc_info=error)

        try:
            failure = await self.store.embedding.record_failure(fileid)
            log.warning(
                "index retry scheduled: fileid=%d attempts=%d retry_at=%d",
                fileid, failure["attempts"], failure["retry_at"],
            )
        except Exception:
            log.exception("could not persist retry state for %d", fileid)
        finally:
            self.done(fileid, ok=False)

    async def _ensure_places(self, good, parents):
        """Embed current addresses and remove places no longer associated with each file."""

        points = []

        for fileid, res, _ in good:
            places = res.metadata.places
            names = [p.name for p in places]

            for idx, place in enumerate(places):
                points.append(PlacePoint(
                    fileid=fileid,
                    parent_id=parents[fileid],
                    mtime=res.metadata.mtime,
                    etag=res.metadata.etag,
                    osm_id=place.osm_id,
                    vector=[],
                    admin_level=place.admin_level,
                    name=place.name,
                    full_address=", ".join(names[idx:]),
                ))

        if points:
            addresses = [p.full_address for p in points]
            vectors = await self.sentence_model.embed_passages_async(addresses)
            points = [replace(point, vector=vector) for point, vector in zip(points, vectors)]

        await self.store.places.replace_many(
            fileids=[fileid for fileid, _, _ in good],
            points=points,
        )

    async def _fetch_all(self, fileids):
        """Download one batch concurrently; fetch failures count immediately."""

        results = await asyncio.gather(
            *(asyncio.to_thread(fetch_file, fileid) for fileid in fileids),
            return_exceptions=True,
        )

        pending = []
        for fileid, res in zip(fileids, results):
            if isinstance(res, Exception):
                await self._fail(fileid, res)
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
                await self._fail(fileid, image)
            else:
                good.append((fileid, res, image))

        return good
