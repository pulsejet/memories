"""Periodic catalog reconciliation across all embedding collections."""

import asyncio
import logging
import time

from config import config
from nextcloud import ScanBatch, fetch_scan_batch

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
            "deleted_total": 0,
            "repaired_total": 0,
            "errors_total": 0,
            "last_error": None,
        }

    async def run(self):
        """Retain failed batches for retry, and pause between completed sweeps."""

        batch = None
        delay = RETRY_DELAY
        log.info("catalog scanner started; sweep interval=%ds", config.scan_interval)

        try:
            while True:
                try:
                    self.stats["status"] = "scanning"
                    if batch is None:
                        batch = await fetch_scan_batch()

                    self.stats.update(start=batch.start, end=batch.end)
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
            batch.start, batch.end, len(batch.files), len(files), len(batch.files) - len(files), batch.done,
        )

        async with self.queue.mutation_lock:
            found = set()
            indexed = set()
            stale = set()
            repairs = set()

            for collection in self.store.collections:
                async for point in collection.scroll_range(batch.start, batch.end):
                    payload = point.payload
                    fileid = payload["fileid"]
                    found.add(fileid)
                    file = files.get(fileid)

                    if file is None:
                        continue

                    if collection is self.store.embedding:
                        indexed.add(fileid)
                    if payload.get("mtime") != file.mtime:
                        stale.add(fileid)
                    if payload.get("parent_id") != file.parentid or payload.get("etag") != file.etag:
                        repairs.add((collection, fileid))

            pending = self.queue.fileids_in_range(batch.start, batch.end)
            obsolete = found - files.keys()
            missing = files.keys() - indexed
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

            reindex = sorted(stale | missing)

        # A full queue must be allowed to drain while this batch waits for capacity.
        for fileid in reindex:
            await self.queue.enqueue_wait(fileid, files[fileid].parentid)
            self.stats["enqueued_total"] += 1

        log.info(
            "scan batch completed: range=[%d,%s] stored_files=%d missing_images=%d stale=%d "
            "enqueued=%d deleted=%d dropped_pending=%d repaired_files=%d repair_updates=%d "
            "queue_depth=%d elapsed=%.2fs",
            batch.start, batch.end, len(found), len(missing), len(stale), len(reindex), len(obsolete),
            len(pending - files.keys()), len({fileid for _, fileid in repairs}), len(repairs),
            self.queue.depth, time.monotonic() - started,
        )
