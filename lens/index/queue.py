"""Bounded index queue with queued-only dedupe and stats."""

import asyncio

from config import config


class IndexQueue:
    """FIFO of fileids; re-enqueue refreshes parent_id without jumping the queue."""

    def __init__(self, maxsize):
        self._queue = asyncio.Queue(maxsize)
        self._queued = {}
        self.in_flight = {}
        self._space = asyncio.Event()
        self.mutation_lock = asyncio.Lock()
        self.indexed_total = 0
        self.failed_total = 0
        self.deferred_total = 0

    def enqueue(self, fileid, parent_id):
        """Refresh queued entry or append; raise asyncio.QueueFull when full."""

        if fileid in self._queued:
            self._queued[fileid] = parent_id
            return

        self._queue.put_nowait(fileid)
        self._queued[fileid] = parent_id

    async def enqueue_wait(self, fileid, parent_id):
        """Wait for capacity without dropping the scanner's current batch."""

        while True:
            try:
                self.enqueue(fileid, parent_id)
                return
            except asyncio.QueueFull:
                self._space.clear()
                await self._space.wait()

    async def wait_idle(self):
        """Wake when queued and in-flight work finishes, including newly enqueued jobs."""

        while True:
            await self._queue.join()
            if self._queue.empty() and not self.in_flight:
                return

    async def next(self):
        """Pop next queued item; skip entries dropped while queued."""

        while True:
            fileid = await self._queue.get()
            self._space.set()

            try:
                parent_id = self._queued.pop(fileid)
            except KeyError:
                self._queue.task_done()
                continue

            self.in_flight[fileid] = parent_id
            return fileid, parent_id

    async def next_batch(self, max_items):
        """Block for the first item, then drain queued extras without blocking."""

        batch = [await self.next()]

        while len(batch) < max_items:
            try:
                fileid = self._queue.get_nowait()
                self._space.set()
            except asyncio.QueueEmpty:
                break

            try:
                parent_id = self._queued.pop(fileid)
            except KeyError:
                self._queue.task_done()
                continue

            self.in_flight[fileid] = parent_id
            batch.append((fileid, parent_id))

        return batch

    def done(self, fileid, ok):
        """Record completion; None means deferred without attempting to index."""

        self.in_flight.pop(fileid, None)

        if ok is None:
            self.deferred_total += 1
        elif ok:
            self.indexed_total += 1
        else:
            self.failed_total += 1

        self._queue.task_done()

    def drop(self, fileid):
        """Drop queued or selected work; callers hold mutation_lock to exclude active writes."""

        self._queued.pop(fileid, None)
        self.in_flight.pop(fileid, None)

    def refresh_parent(self, fileid, parent_id):
        """Update pending work so it cannot undo a scanner's folder repair."""

        for pending in (self._queued, self.in_flight):
            if fileid in pending:
                pending[fileid] = parent_id

    def fileids_in_range(self, start, end):
        """Pending fileids in a scan range, including files without stored embeddings."""

        pending = self._queued.keys() | self.in_flight.keys()

        return {
            fileid for fileid in pending
            if fileid >= start and (end is None or fileid <= end)
        }

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

            # The single index worker and scanner must not overwrite each other's mutations.
            async with self.mutation_lock:
                active = []
                for fileid, _ in batch:
                    if fileid in self.in_flight:
                        active.append((fileid, self.in_flight[fileid]))
                    else:
                        self._queue.task_done()

                if active:
                    await handle_batch(active)
