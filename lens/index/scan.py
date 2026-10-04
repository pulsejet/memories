"""Periodic catalog reconciliation across all embedding collections."""

import asyncio
import logging
import time

from config import config
from nextcloud import ScanBatch, fetch_scan_batch
from store import FAILURE_KIND

log = logging.getLogger("lens.scan")

RETRY_DELAY = 5
MAX_RETRY_DELAY = 300


class Scanner:
    """Reconcile catalog batches and queue files whose embeddings need refreshing."""

    def __init__(self, store, queue):
        """Share collection handles and mutation scheduling with the index worker."""

        self.store = store
        self.queue = queue
        self.stats = {
            "status": "idle",
            "start": None,
            "end": None,
            "batches_total": 0,
            "sweeps_total": 0,
            "files_total": 0,
            "enqueued_total": 0,
            "deferred_total": 0,
            "deleted_total": 0,
            "repaired_total": 0,
            "errors_total": 0,
            "last_error": None,
        }

    async def run(self):
        """Scan only while indexing is idle; retain failed batches and pause between sweeps."""

        batch = None
        delay = RETRY_DELAY
        log.info("catalog scanner started; sweep interval=%ds", config.scan_interval)

        try:
            while True:
                try:
                    self.stats["status"] = "waiting_queue"
                    await self.queue.wait_idle()

                    if batch is None:
                        self.stats["status"] = "scanning"
                        batch = await fetch_scan_batch()

                        # Uploads may have queued work while the PHP request was in flight.
                        self.stats["status"] = "waiting_queue"
                        await self.queue.wait_idle()

                    self.stats.update(status="scanning", start=batch.start, end=batch.end)
                    await self.reconcile(batch)
                except Exception as exc:
                    self.stats["errors_total"] += 1
                    self.stats.update(status="backoff", last_error=str(exc))
                    log.exception("scan failed; retrying in %ds", delay)
                    await asyncio.sleep(delay)
                    delay = min(delay * 2, MAX_RETRY_DELAY)
                    continue

                self.stats["batches_total"] += 1
                self.stats["files_total"] += len(batch.files)
                self.stats["last_error"] = None
                delay = RETRY_DELAY
                done = batch.done
                batch = None

                if done:
                    self.stats["sweeps_total"] += 1
                    self.stats["status"] = "idle"
                    log.info("scan sweep completed: %s", self.stats)
                    await asyncio.sleep(config.scan_interval)
        finally:
            self.stats["status"] = "stopped"
            log.info("catalog scanner stopped")

    async def reconcile(self, batch: ScanBatch):
        """Read and repair a stable range, then enqueue outside the mutation lock."""

        started = time.monotonic()
        files = {file.fileid: file for file in batch.files if not file.isvideo}
        log.info(
            "scan batch: range=[%d,%s] received=%d images=%d videos=%d done=%s",
            batch.start,
            batch.end,
            len(batch.files),
            len(files),
            len(batch.files) - len(files),
            batch.done,
        )

        async with self.queue.mutation_lock:
            now = int(time.time())
            found = set()
            indexed = set()
            stale = set()
            image_stale = set()
            repairs = set()
            deferred = set()
            places_deferred = set()

            for collection in self.store.collections:
                async for point in collection.scroll_range(batch.start, batch.end):
                    payload = point.payload
                    fileid = payload["fileid"]
                    found.add(fileid)
                    file = files.get(fileid)

                    if file is None:
                        continue

                    if payload.get("kind") == FAILURE_KIND:
                        if payload["retry_at"] > now:
                            if payload["stage"] == "places":
                                places_deferred.add(fileid)
                            else:
                                deferred.add(fileid)
                        else:
                            stale.add(fileid)
                        continue

                    # Places and faces are optional; only an image proves the file was indexed.
                    if collection is self.store.embedding:
                        indexed.add(fileid)
                    if payload.get("mtime") != file.mtime:
                        stale.add(fileid)
                        if collection is self.store.embedding:
                            image_stale.add(fileid)
                    if payload.get("parent_id") != file.parentid or payload.get("etag") != file.etag:
                        repairs.add((collection, fileid))

            pending = self.queue.fileids_in_range(batch.start, batch.end)
            obsolete = found - files.keys()
            missing = files.keys() - indexed

            # Marker-less files reindex fully, even with a fresh image.
            markers = await self.store.faces.get_markers(list(files))

            for fileid in files:
                if fileid not in markers:
                    stale.add(fileid)

            for fileid in (found | pending) - files.keys():
                self.queue.drop(fileid)
                if fileid in found:
                    for collection in self.store.collections:
                        await collection.delete_fileid(fileid)
                    self.stats["deleted_total"] += 1

            for file in files.values():
                self.queue.refresh_parent(file.fileid, file.parentid)

            for collection, fileid in repairs:
                file = files[fileid]
                await collection.update_file_metadata(fileid, file.parentid, file.etag)
                self.stats["repaired_total"] += 1

            # Places backoff must not prevent a changed or missing image from being indexed.
            deferred.update(places_deferred - (image_stale | missing))
            reindex = sorted((stale | missing) - deferred)

            # Drop work enqueued during the awaited cleanup/repair requests as well.
            for fileid in self.queue.fileids_in_range(batch.start, batch.end) - files.keys():
                self.queue.drop(fileid)

        # A full queue must be allowed to drain while this batch waits for capacity.
        for fileid in reindex:
            await self.queue.enqueue_wait(fileid, files[fileid].parentid)
            self.stats["enqueued_total"] += 1

        self.stats["deferred_total"] += len(deferred)
        repaired_files = {fileid for _, fileid in repairs}

        log.info(
            "scan batch completed: range=[%d,%s] stored_files=%d missing_images=%d stale=%d "
            "enqueued=%d deferred=%d deleted=%d dropped_pending=%d repaired_files=%d repair_updates=%d "
            "queue_depth=%d elapsed=%.2fs",
            batch.start,
            batch.end,
            len(found),
            len(missing),
            len(stale),
            len(reindex),
            len(deferred),
            len(obsolete),
            len(pending - files.keys()),
            len(repaired_files),
            len(repairs),
            self.queue.depth,
            time.monotonic() - started,
        )
