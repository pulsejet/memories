"""Bounded index queue with queued-only dedupe and stats."""

import asyncio
import io
import logging

import pillow_heif
from PIL import Image

from nextcloud import fetch_file
from store import FileMeta

pillow_heif.register_heif_opener()

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

    def run(self, embedding_model, store):
        """Spawn the background index loop; cancel the task to stop."""

        return asyncio.create_task(self._worker(embedding_model=embedding_model, store=store))

    async def _worker(self, embedding_model, store):
        """Index loop: fetch → decode size → embed → upsert; failures count, never retry."""

        while True:
            fileid, parent_id = await self.next()

            try:
                # Fetch raw bytes plus validators from Nextcloud.
                result = await asyncio.to_thread(fetch_file, fileid)

                # Decode dimensions; header read is ms next to the model embed.
                with Image.open(io.BytesIO(result.data)) as image:
                    w, h = image.size

                # Embed under the shared inference semaphore.
                vector = await embedding_model.embed_image_async(result.data)

                # Store vector with display metadata (re-index overwrites).
                await store.upsert(
                    fileid=fileid,
                    vector=vector,
                    parent_id=parent_id,
                    meta=FileMeta(
                        w=w,
                        h=h,
                        etag=result.etag,
                        mimetype=result.mimetype,
                    ),
                )
            except Exception as exc:  # pylint: disable=broad-exception-caught
                log.exception("index failed for %d: %s", fileid, exc)
                self.done(fileid, ok=False)
            else:
                log.info("indexed %d (%dx%d %s)", fileid, w, h, result.mimetype)
                self.done(fileid, ok=True)
