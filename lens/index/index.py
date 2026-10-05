"""Batch indexing: fetch → decode → embed → upsert, with persistent failure backoff."""

import asyncio
import logging
import time
from dataclasses import replace

from config import config
from faces import FaceIndexer
from nextcloud import NotFoundError, delete_faces, fetch_file
from store import FAILURE_KIND, FileMeta, PlacePoint, UpsertPoint

log = logging.getLogger("lens.queue")


class Indexer:
    """Index one batch at a time; completion is reported via the done callback."""

    def __init__(self, embedding_model, sentence_model, store, done, face_model):
        self.embedding_model = embedding_model
        self.sentence_model = sentence_model
        self.store = store
        self.done = done
        self.face_indexer = FaceIndexer(face_model, store.faces)

    async def handle_batch(self, batch):
        """Check current metadata first, then download and embed only files needing work."""

        batch_start = time.monotonic()
        t0 = time.monotonic()
        try:
            failures = await self.store.embedding.get_failures([fileid for fileid, _ in batch])
        except Exception:
            log.exception("cannot read retry state; deferring index batch")
            for fileid, _ in batch:
                self.done(fileid, ok=None)
            return
        dt_failures = time.monotonic() - t0

        parents = dict(batch)
        now = int(time.time())
        for fileid, failure in failures.items():
            if failure["stage"] in ("image", "faces") and failure["retry_at"] > now:
                log.info("index deferred for %d until %d", fileid, failure["retry_at"])
                del parents[fileid]
                self.done(fileid, ok=None)

        t0 = time.monotonic()
        heads = await self._fetch_all(list(parents), metadata_only=True)
        dt_head = time.monotonic() - t0
        t0 = time.monotonic()
        try:
            reindex, places_only = await self._refresh_metadata(heads, failures)
        except Exception as exc:  # noqa: BLE001
            for fileid, _ in heads:
                await self._fail(fileid, exc)
            return
        dt_refresh = time.monotonic() - t0

        for fileid, res in heads:
            parents[fileid] = res.metadata.parent_id
            if fileid not in reindex and fileid not in places_only:
                log.info(
                    "index skipped %d (mtime=%s mime=%s)",
                    fileid,
                    res.metadata.mtime,
                    res.metadata.mimetype,
                )
                self.done(fileid, ok=True)

        t0 = time.monotonic()
        await self._finish_places(
            files=[(fileid, res) for fileid, res in heads if fileid in places_only and fileid not in reindex],
            parents=parents,
            failures=failures,
        )
        dt_places_only = time.monotonic() - t0

        t0 = time.monotonic()
        download_ids = [fileid for fileid, _ in heads if fileid in reindex]
        pending = await self._fetch_all(download_ids)
        dt_fetch = time.monotonic() - t0
        for fileid, res in pending:
            if res.metadata.parent_id is not None:
                parents[fileid] = res.metadata.parent_id

        t0 = time.monotonic()
        good = await self._decode_all(pending)
        dt_decode = time.monotonic() - t0

        if not good:
            log.info(
                "index batch perf n=%d failures=%.2fs head=%.2fs refresh=%.2fs "
                "places_only=%.2fs fetch=%.2fs decode=%.2fs total=%.2fs (nothing to embed)",
                len(batch),
                dt_failures,
                dt_head,
                dt_refresh,
                dt_places_only,
                dt_fetch,
                dt_decode,
                time.monotonic() - batch_start,
            )
            return

        try:
            images = [image for _, _, image in good]
            t0 = time.monotonic()
            vectors = await self.embedding_model.embed_pil_images_async(images)
            dt_infer = time.monotonic() - t0
        except Exception as exc:  # noqa: BLE001
            for fileid, _, _ in good:
                await self._fail(fileid, exc)
            return

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
            t0 = time.monotonic()
            await self.store.embedding.upsert_many(points)
            dt_upsert = time.monotonic() - t0
        except Exception as exc:  # noqa: BLE001
            for fileid, _, _ in good:
                await self._fail(fileid, exc)
            return

        for fileid, res, image in good:
            log.info(
                "indexed %d (%dx%d %s epoch=%s dayid=%s places=%d)",
                fileid,
                image.width,
                image.height,
                res.metadata.mimetype,
                res.metadata.epoch,
                res.metadata.dayid,
                len(res.metadata.places),
            )

        t0 = time.monotonic()
        faced = await self._finish_faces(
            files=[(fileid, res) for fileid, res, _ in good],
            parents=parents,
        )
        dt_faces = time.monotonic() - t0

        t0 = time.monotonic()
        await self._finish_places(
            files=faced,
            parents=parents,
            failures=failures,
        )
        dt_places = time.monotonic() - t0

        log.info(
            "index batch perf n=%d images=%d failures=%.2fs head=%.2fs refresh=%.2fs "
            "places_only=%.2fs fetch=%.2fs decode=%.2fs infer=%.2fs upsert=%.2fs "
            "faces=%.2fs places=%.2fs total=%.2fs",
            len(batch),
            len(good),
            dt_failures,
            dt_head,
            dt_refresh,
            dt_places_only,
            dt_fetch,
            dt_decode,
            dt_infer,
            dt_upsert,
            dt_faces,
            dt_places,
            time.monotonic() - batch_start,
        )

    async def _refresh_metadata(self, heads, failures):
        """Repair parent/etag and distinguish image work from metadata-only places work."""

        files = {}
        for fileid, res in heads:
            if res.metadata.mimetype.startswith("video/"):
                for collection in self.store.collections:
                    await collection.delete_fileid(fileid)
            else:
                files[fileid] = res.metadata

        if not files:
            return set(), set()

        missing = set(files)
        stale = {fileid for fileid in failures.keys() & files.keys() if failures[fileid]["stage"] in ("image", "faces")}
        places = {fileid for fileid in failures.keys() & files.keys() if failures[fileid]["stage"] == "places"}
        repairs = set()
        face_state = {}

        for collection in self.store.collections:
            try:
                async for point in collection.scroll_files(list(files)):
                    payload = point.payload
                    if payload.get("kind") == FAILURE_KIND:
                        continue

                    fileid = payload["fileid"]
                    meta = files[fileid]
                    if collection is self.store.embedding:
                        missing.discard(fileid)
                        face_state[fileid] = (
                            payload.get("face_count"),
                            payload.get("face_version"),
                            payload.get("face_owner"),
                        )
                    if payload.get("mtime") != meta.mtime:
                        if collection is self.store.places:
                            places.add(fileid)
                        else:
                            stale.add(fileid)
                    if payload.get("parent_id") != meta.parent_id or payload.get("etag") != meta.etag:
                        repairs.add((collection, fileid))
            except Exception:
                if collection is not self.store.places:
                    raise
                log.exception("places metadata lookup failed; scheduling places refresh")
                places.update(files)

        for collection, fileid in repairs:
            meta = files[fileid]
            try:
                await collection.update_file_metadata(fileid, meta.parent_id, meta.etag)
            except Exception:
                if collection is not self.store.places:
                    raise
                log.exception("places metadata repair failed for %d; scheduling places refresh", fileid)
                places.add(fileid)
                continue

            log.info("index metadata repaired %d in %s", fileid, collection.collection)

        # Face completeness rides on the image point: its upsert clears face
        # state and only the face phase restamps it. Anything unstamped,
        # version-old or scope-moved means a full reindex, never a face-only run.
        for fileid, meta in files.items():
            count, version, owner = face_state.get(fileid, (None, None, None))

            if count is None or version != config.face.version or owner != meta.owner:
                stale.add(fileid)

        return stale | missing, places

    async def _finish_faces(self, files, parents):
        """Run the detection phase after the image commit; failures keep old face state."""

        started = time.monotonic()
        complete = []

        for fileid, res in files:
            try:
                outcome, count = await self.face_indexer.process_file(fileid, res, parents[fileid])
            except NotFoundError:
                await self._discard_missing(fileid)
                continue
            except Exception as exc:  # noqa: BLE001
                await self._fail(fileid, exc, stage="faces")
                continue

            if outcome == "stale":
                self.done(fileid, ok=None)
                continue

            try:
                await self.store.embedding.set_face_state(
                    fileid, res.metadata.owner or "", config.face.version, count,
                )
            except Exception as exc:  # noqa: BLE001
                await self._fail(fileid, exc, stage="faces")
                continue

            complete.append((fileid, res))

        log.info("faces batch n=%d elapsed=%.2fs", len(files), time.monotonic() - started)

        return complete

    async def _fail(self, fileid, error, stage="image"):
        """Persist retry state before releasing the file from the indexing queue."""

        log.error("%s indexing failed for %d: %s", stage, fileid, error, exc_info=error)

        try:
            failure = await self.store.embedding.record_failure(fileid, stage)
            log.warning(
                "index retry scheduled: fileid=%d stage=%s attempts=%d retry_at=%d",
                fileid,
                stage,
                failure["attempts"],
                failure["retry_at"],
            )
        except Exception:
            log.exception("could not persist retry state for %d", fileid)
        finally:
            self.done(fileid, ok=False)

    async def _finish_places(self, files, parents, failures):
        """Retry places from metadata, isolating failures without invalidating image vectors."""

        for fileid, res in files:
            failure = failures.get(fileid)
            if failure and failure["stage"] == "places" and failure["retry_at"] > time.time():
                log.info("places deferred for %d until %d", fileid, failure["retry_at"])
                self.done(fileid, ok=None)
                continue

            started = time.monotonic()
            try:
                await self._ensure_places(fileid, res.metadata, parents[fileid])
                if failure:
                    await self.store.embedding.clear_failures([fileid])
            except Exception as exc:  # noqa: BLE001
                await self._fail(fileid, exc, stage="places")
                continue

            log.info(
                "indexed places for %d (%d places) elapsed=%.2fs",
                fileid,
                len(res.metadata.places),
                time.monotonic() - started,
            )
            self.done(fileid, ok=True)

    async def _ensure_places(self, fileid, metadata, parent_id):
        """Embed one file's address hierarchy and remove its obsolete place points."""

        points = []
        names = [place.name for place in metadata.places]

        for idx, place in enumerate(metadata.places):
            points.append(PlacePoint(
                fileid=fileid,
                parent_id=parent_id,
                mtime=metadata.mtime,
                etag=metadata.etag,
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
            fileids=[fileid],
            points=points,
        )

    async def _discard_missing(self, fileid):
        """A late enqueue for a deleted file must not recreate a failure marker."""

        try:
            for collection in self.store.collections:
                await collection.delete_fileid(fileid)
            log.info("removed missing file %d from embeddings", fileid)
        except Exception:
            log.exception("failed to clean up missing file %d; scanner will retry", fileid)
        finally:
            self.done(fileid, ok=None)

        try:
            await asyncio.to_thread(delete_faces, fileid)
            log.info("removed missing file %d from SQL faces", fileid)
        except Exception:
            log.exception("failed to clean up SQL faces for %d; file hooks cover new deletions", fileid)

    async def _fetch_all(self, fileids, *, metadata_only=False):
        """Fetch one batch concurrently; metadata checks use HEAD instead of downloading."""

        started = time.monotonic()
        fetches = [
            asyncio.to_thread(fetch_file, fileid, metadata_only=metadata_only)
            for fileid in fileids
        ]
        results = await asyncio.gather(*fetches, return_exceptions=True)

        pending = []
        for fileid, res in zip(fileids, results):
            if isinstance(res, NotFoundError):
                await self._discard_missing(fileid)
            elif isinstance(res, Exception):
                await self._fail(fileid, res)
            else:
                pending.append((fileid, res))

        log.info(
            "fetch batch n=%d ok=%d metadata_only=%s bytes=%d elapsed=%.2fs",
            len(fileids),
            len(pending),
            metadata_only,
            sum(len(res.data) for _, res in pending),
            time.monotonic() - started,
        )

        return pending

    async def _decode_all(self, pending):
        """Decode each fetch once, off the event loop; decode failures count immediately."""

        started = time.monotonic()
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

        log.info("decode batch n=%d good=%d elapsed=%.2fs", len(pending), len(good), time.monotonic() - started)

        return good
