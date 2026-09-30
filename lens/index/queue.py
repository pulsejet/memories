"""Bounded index queue with queued-only dedupe and stats."""

import asyncio

from config import config


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

    def run(self, handle_batch):
        """Spawn the background index loop; cancel the task to stop."""

        return asyncio.create_task(self._worker(handle_batch))

    async def _worker(self, handle_batch):
        """Pull batches and hand them to the indexer; the queue owns scheduling only."""

        while True:
            batch = await self.next_batch(config.index_batch_size)
            await handle_batch(batch)
