"""Per-file detection phase: match, replace SQL, replace vectors, stamp complete.

Detection never clusters: it reuses old face IDs through one-to-one geometry
matching (echoing their clusters verbatim) and mints fresh IDs for the rest.
A file is face-complete only when its image point carries fresh face state;
the image upsert clears it, so anything else goes through a full reindex,
never a face-only run.
"""

import asyncio
import logging
import secrets

from config import config
from nextcloud import FetchResult, NotFoundError, fetch_file, post_faces
from store import FacePoint, face_point_id

log = logging.getLogger("lens.faces")

# Center drift allowed per axis, relative to the smaller old side.
CENTER_TOLERANCE = 0.25
# Box sides must agree within this factor in both dimensions.
SIZE_TOLERANCE = 2.0


class FaceError(RuntimeError):
    """Face branch failed; the committed image points are unaffected."""


def mint_face_id() -> int:
    """Fresh nonzero random uint63 for a new detection."""

    while not (face_id := secrets.randbits(63)):
        pass

    return face_id


def match_faces(detections: list[dict], old_faces: list, version: int) -> list[dict]:
    """Match new detections one-to-one against current SQL rows.

    Only same-version rows participate; anything ambiguous or unmatched gets a
    fresh ID with a null cluster. Restored faces keep their ID and echo the
    stored cluster verbatim. No next_try/retries are sent here.
    """

    eligible = [face for face in old_faces if face.embed_version == version and face.w > 0 and face.h > 0]
    candidates: list[list[int]] = [[] for _ in detections]

    for new_idx, det in enumerate(detections):
        for old_idx, old in enumerate(eligible):
            if _matches(det, old):
                candidates[new_idx].append(old_idx)

    claimed = [idx for choices in candidates for idx in choices]
    contested = {idx for idx in claimed if claimed.count(idx) > 1}

    matched = []

    for det, choices in zip(detections, candidates):
        choices = [idx for idx in choices if idx not in contested]

        if len(choices) == 1:
            old = eligible[choices[0]]
            matched.append({**_geometry(det), "id": old.id, "cluster_id": old.cluster_id})
        else:
            matched.append({**_geometry(det), "id": mint_face_id(), "cluster_id": None})

    return matched


def _matches(det: dict, old) -> bool:
    """Conservative center/size gates between a detection and a stored row."""

    tolerance = CENTER_TOLERANCE * min(old.w, old.h)

    return (
        abs(_center(det["x"], det["w"]) - _center(old.x, old.w)) <= tolerance
        and abs(_center(det["y"], det["h"]) - _center(old.y, old.h)) <= tolerance
        and max(det["w"] / old.w, old.w / det["w"]) <= SIZE_TOLERANCE
        and max(det["h"] / old.h, old.h / det["h"]) <= SIZE_TOLERANCE
    )


def _center(offset: float, size: float) -> float:
    """Box center along one axis."""

    return offset + size / 2


def _geometry(det: dict) -> dict:
    """Geometry plus score carried into the writeback, without any identity."""

    return {"x": det["x"], "y": det["y"], "w": det["w"], "h": det["h"], "det_score": det.get("det_score", 0)}


class FaceIndexer:
    """Run the detection phase for one file; inference failures keep old state."""

    def __init__(self, face_model, faces_store, post_fn=None, head_fn=None):
        self.face_model = face_model
        self.faces = faces_store
        self.post_fn = post_fn or post_faces
        self.head_fn = head_fn or (lambda fileid: fetch_file(fileid, metadata_only=True).metadata)

    async def process_file(self, fileid: int, res: FetchResult, parent_id: int) -> tuple[str, int]:
        """Detect and replace SQL/vectors; returns the outcome with the face count.

        "stale" means the source moved mid-flight; the caller stamps the image
        point with the count only on "complete".
        """

        owner = res.metadata.owner

        if not owner:
            raise FaceError(f"missing storage scope for {fileid}")

        image = await asyncio.to_thread(self.face_model.decode_image, res.data)
        detections = await self.face_model.detect_async(image)
        vectors = await self.face_model.embed_async(image, detections)
        matched = match_faces(detections, res.metadata.faces, config.face.version)
        known = {face.id for face in res.metadata.faces if face.embed_version == config.face.version}
        reused = sum(1 for face in matched if face["id"] in known)
        log.info("faces matched for %d: %d reused, %d new", fileid, reused, len(matched) - reused)

        try:
            committed = await asyncio.to_thread(self.post_fn, fileid, owner, matched)
        except Exception as exc:
            raise FaceError(f"face writeback failed for {fileid}: {exc}") from exc

        by_id = {face["id"]: (face, vector) for face, vector in zip(matched, vectors)}
        committed_ids = []

        for row in committed:
            if row.get("id") in by_id:
                committed_ids.append(row["id"])
            else:
                log.warning("face commit mismatch for %d: %s", fileid, row)

        points = []

        for face_id in committed_ids:
            face, vector = by_id[face_id]
            points.append(FacePoint(
                face_id=face_id,
                fileid=fileid,
                parent_id=parent_id,
                owner_id=owner,
                embed_version=config.face.version,
                vector=vector,
                etag=res.metadata.etag,
                mtime=res.metadata.mtime,
                x=face["x"],
                y=face["y"],
                w=face["w"],
                h=face["h"],
                det_score=face["det_score"],
                cluster_id=face["cluster_id"],
            ))

        await self.faces.upsert_faces(points)

        existing = await self.faces.get_file_face_ids(fileid)
        await self.faces.delete_points([
            face_point_id(face_id) for face_id in existing if face_id not in committed_ids
        ])

        if await self._source_moved(fileid, res):
            log.info("faces deferred for %d: source changed mid-flight", fileid)

            return "stale", 0

        log.info("faces indexed for %d (%d faces)", fileid, len(committed_ids))

        return "complete", len(committed_ids)

    async def _source_moved(self, fileid: int, res: FetchResult) -> bool:
        """Whether the source changed between download and writeback."""

        try:
            head = await asyncio.to_thread(self.head_fn, fileid)
        except NotFoundError:
            # Deleted mid-flight: let the caller discard, never retry.
            raise
        except Exception as exc:
            raise FaceError(f"face freshness check failed for {fileid}: {exc}") from exc

        return head.etag != res.metadata.etag or (
            head.mtime is not None and res.metadata.mtime is not None and head.mtime != res.metadata.mtime
        )
