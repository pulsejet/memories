"""Bounded index queue with queued-only dedupe and stats."""

import asyncio
import logging
from dataclasses import replace

from config import config
from nextcloud import fetch_file
from store import FileMeta, PlacePoint, UpsertPoint

log = logging.getLogger("lens.queue")


class IndexQueue:
    """FIFO of fileids; re-enqueue refreshes parent_id without jumping the queue."""

    def __init__(self, maxsize):
        self._queue = asyncio.Queue(maxsize)
        self._queued = {}
        self.in_flight = set()
        self.indexed_total = 0
        self.failed_total = 0

    def enqueue(self, fileid, parent_id):
        """Refresh queued entry or append; raise asyncio.QueueFull when full."""

        if fileid in self._queued:
            self._queued[fileid] = parent_id
            return

        self._queue.put_nowait(fileid)
        self._queued[fileid] = parent_id

    async def next(self):
        """Pop next queued item; skip entries dropped while queued."""

        while True:
            fileid = await self._queue.get()

            try:
                parent_id = self._queued.pop(fileid)
            except KeyError:
                self._queue.task_done()
                continue

            self.in_flight.add(fileid)
            return fileid, parent_id

    async def next_batch(self, max_items):
        """Block for the first item, then drain queued extras without blocking."""

        batch = [await self.next()]

        while len(batch) < max_items:
            try:
                fileid = self._queue.get_nowait()
            except asyncio.QueueEmpty:
                break

            try:
                parent_id = self._queued.pop(fileid)
            except KeyError:
                self._queue.task_done()
                continue

            self.in_flight.add(fileid)
            batch.append((fileid, parent_id))

        return batch

    def done(self, fileid, ok):
        """Record completion of one item."""

        self.in_flight.discard(fileid)

        if ok:
            self.indexed_total += 1
        else:
            self.failed_total += 1

        self._queue.task_done()

    def drop(self, fileid):
        """Forget a queued entry; its queue slot is skipped on pop."""

        self._queued.pop(fileid, None)

    @property
    def depth(self):
        """Current queue length."""

        return self._queue.qsize()

    def run(self, embedding_model, sentence_model, store):
        """Spawn the background index loop; cancel the task to stop."""

        return asyncio.create_task(self._worker(
            embedding_model=embedding_model,
            sentence_model=sentence_model,
            store=store,
        ))

    async def _worker(self, embedding_model, sentence_model, store):
        """Index loop: fetch batch → decode once → embed batch → upsert; failures count, never retry."""

        while True:
            batch = await self.next_batch(config.index_batch_size)
            await self._index_batch(batch, embedding_model, sentence_model, store)

    async def _index_batch(self, batch, embedding_model, sentence_model, store):  # pylint: disable=too-many-locals
        """Fetch concurrently, decode each once, embed in one forward pass."""

        parents = dict(batch)
        pending = await self._fetch_all([fileid for fileid, _ in batch])
        good = await self._decode_all(pending, embedding_model)

        if not good:
            return

        try:
            vectors = await embedding_model.embed_pil_images_async([image for _, _, image in good])
        except Exception as exc:  # pylint: disable=broad-exception-caught
            for fileid, _, _ in good:
                log.exception("index failed for %d: %s", fileid, exc)
                self.done(fileid, ok=False)
            return

        try:
            await self._ensure_places(good, parents, sentence_model, store)
        except Exception as exc:  # pylint: disable=broad-exception-caught
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
            await store.upsert_many(points)
        except Exception as exc:  # pylint: disable=broad-exception-caught
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

    async def _ensure_places(self, good, parents, sentence_model, store):
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

        vectors = await sentence_model.embed_passages_async([p.full_address for p in points])

        await store.upsert_places([
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

    async def _decode_all(self, pending, embedding_model):
        """Decode each fetch once, off the event loop; decode failures count immediately."""

        images = await asyncio.gather(
            *(asyncio.to_thread(embedding_model.decode_image, res.data) for _, res in pending),
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
