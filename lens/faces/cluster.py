"""Grouping phase: attach unassigned faces to clusters, mint new ones, write back.

Grouping never detects: it only reads unassigned faces and their vectors, then
writes assignments through the PHP callback. Missing vectors are skipped for the
single pipeline to repair; no detection or embed code runs here.
"""

import asyncio
import logging
import secrets
from dataclasses import dataclass

import numpy as np

from nextcloud import get_faces_batch, post_face_clusters

log = logging.getLogger("lens.faces")

# Bounded batch per run: 2,048 faces or 512 whole files, whichever comes first.
GROUP_FACES = 2048
GROUP_FILES = 512
# Assigned neighbors consulted per face, and member vectors sampled per cluster.
SEARCH_TOP_K = 16
SAMPLE_SIZE = 8
# New clusters need faces from at least this many distinct files.
MIN_GROUP_FILES = 5

# PROVISIONAL cosine-distance gates: step-1 album calibration has not run yet, so
# these favor extra groups and unassigned faces over false identity merges.
# Do not tune them by feel; replace them with calibrated values.
ATTACH_MAX_DISTANCE = 0.35
MINT_MAX_DISTANCE = 0.35


@dataclass(frozen=True)
class BatchFace:
    """One due unassigned face row from the PHP batch callback."""

    id: int
    fileid: int
    owner: str
    embed_version: int


def mint_cluster_id() -> int:
    """Fresh nonzero random uint63 for a minted cluster; person starts null."""

    while not (cluster_id := secrets.randbits(63)):
        pass

    return cluster_id


def select_cluster(scored: list[tuple[int, float]]) -> int | None:
    """Best cluster passing the absolute gate."""

    if not scored:
        return None

    ranked = sorted(scored, key=lambda candidate: candidate[1])
    cluster, best = ranked[0]

    if best > ATTACH_MAX_DISTANCE:
        return None

    return cluster


def complete_linkage(fileids: list[int], matrix: np.ndarray, threshold: float) -> list[list[int]]:
    """Greedy dense complete linkage; same-file pairs never merge.

    Returns index groups in batch order. Every member joins only while its worst
    distance to the group stays within the threshold.
    """

    dist = np.array(matrix, dtype=np.float64)
    dist[np.equal.outer(fileids, fileids)] = np.inf
    fileids_arr = np.asarray(fileids)

    remaining = np.ones(len(fileids), dtype=bool)
    groups = []

    while remaining.any():
        seed = int(np.flatnonzero(remaining)[0])
        remaining[seed] = False
        group = [seed]
        group_files = {fileids[seed]}
        d_to_group = dist[:, seed].copy()

        while True:
            eligible = remaining & (d_to_group <= threshold) & ~np.isin(fileids_arr, list(group_files))

            if not eligible.any():
                break

            best = int(np.flatnonzero(eligible)[np.argmin(d_to_group[eligible])])
            group.append(best)
            group_files.add(fileids[best])
            remaining[best] = False
            d_to_group = np.maximum(d_to_group, dist[:, best])

        groups.append(group)

    return groups


class FaceGrouper:
    """Run one bounded grouping pass; detection/embed code never runs here."""

    def __init__(self, faces_store, batch_fn=None, clusters_fn=None):
        self.faces = faces_store
        self.batch_fn = batch_fn or get_faces_batch
        self.clusters_fn = clusters_fn or post_face_clusters

    async def run_once(self) -> dict:
        """Process due batches until none remain; returns total work counters.

        Faces seen in an earlier pass of this run are not reprocessed: assigned
        and attempted faces leave the due set through PHP, and skipped faces
        (missing vectors) wait for the next run instead of spinning here.
        """

        stats = {"fetched": 0, "owners": 0, "passes": 0}
        seen: set[int] = set()

        while True:
            rows = await asyncio.to_thread(self.batch_fn, GROUP_FACES)
            faces = [
                face for face in _cap_files(_parse_batch(rows), GROUP_FILES)
                if face.id not in seen
            ]

            if not faces:
                break

            stats["passes"] += 1
            stats["fetched"] += len(faces)
            seen.update(face.id for face in faces)

            by_owner: dict[str, list[BatchFace]] = {}

            for face in faces:
                by_owner.setdefault(face.owner, []).append(face)

            for owner, group in by_owner.items():
                stats["owners"] += 1

                for key, value in (await self._run_owner(owner, group)).items():
                    stats[key] = stats.get(key, 0) + value

        log.info("faces grouped: %s", stats)

        return stats

    async def _run_owner(self, owner: str, faces: list[BatchFace]) -> dict:
        """Attach, mint and write back within one storage scope."""

        stats = {"attached": 0, "minted_groups": 0, "minted_faces": 0, "attempted": 0,
                 "assigned": 0, "misses": 0, "skipped": 0}
        vectors = await self.faces.get_batch_vectors(faces)
        stats["skipped"] = len(faces) - len(vectors)

        if not vectors:
            return stats

        samples: dict[int, dict[int, list[float]]] = {}
        tentative: dict[int, set[int]] = {}
        assignments: dict[int, int] = {}

        order = [face for face in faces if face.id in vectors]

        if order:
            searches = await self.faces.search_assigned_batch(
                [vectors[face.id] for face in order], owner, faces[0].embed_version, SEARCH_TOP_K,
            )
            clusters = []

            for hits in searches:
                for hit in hits:
                    if hit.get("cluster_id") not in clusters:
                        clusters.append(hit.get("cluster_id"))

            samples = await self.faces.sample_clusters(clusters, owner, faces[0].embed_version, SAMPLE_SIZE)

            for face, hits in zip(order, searches):
                cluster = self._attach(face, vectors[face.id], hits, samples, tentative)

                if cluster is not None:
                    assignments[face.id] = cluster
                    tentative.setdefault(face.fileid, set()).add(cluster)

        stats["attached"] = len(assignments)

        remaining = [face for face in faces if face.id in vectors and face.id not in assignments]

        for group in self._mint(remaining, vectors):
            cluster = mint_cluster_id()

            for face in group:
                assignments[face.id] = cluster

            stats["minted_groups"] += 1
            stats["minted_faces"] += len(group)

        attempted = [face.id for face in remaining if face.id not in assignments]
        stats["attempted"] = len(attempted)

        if not assignments and not attempted:
            return stats

        outcome = await asyncio.to_thread(
            self.clusters_fn,
            owner,
            [{"id": face_id, "cluster": cluster} for face_id, cluster in assignments.items()],
            attempted,
        )
        stats["assigned"] = outcome["assigned"]
        stats["misses"] = outcome["misses"]

        if assignments:
            await self.faces.set_face_clusters(assignments)

        return stats

    @staticmethod
    def _attach(
        face: BatchFace,
        vector: list[float],
        hits: list[dict],
        samples: dict[int, dict[int, list[float]]],
        tentative: dict[int, set[int]],
    ) -> int | None:
        """Match one face against the prefetched cluster samples; ambiguous faces stay free."""

        claimed = tentative.get(face.fileid, set())
        best: dict[int, float] = {}

        for hit in hits:
            cluster = hit.get("cluster_id")

            if cluster in claimed or cluster in best:
                continue

            sample = samples.get(cluster)

            if sample:
                best[cluster] = _nearest(vector, sample.values())

        return select_cluster(list(best.items()))

    @staticmethod
    def _mint(faces: list[BatchFace], vectors: dict[int, list[float]]) -> list[list[BatchFace]]:
        """Group leftovers by dense complete linkage, keeping only quorate groups."""

        if not faces:
            return []

        matrix = _distances([vectors[face.id] for face in faces])
        fileids = [face.fileid for face in faces]
        groups = []

        for idxs in complete_linkage(fileids, matrix, MINT_MAX_DISTANCE):
            if len({fileids[i] for i in idxs}) < MIN_GROUP_FILES:
                continue

            if matrix[np.ix_(idxs, idxs)].max() > MINT_MAX_DISTANCE:
                log.warning("dropping oversized group of %d faces", len(idxs))
                continue

            groups.append([faces[i] for i in idxs])

        return groups


async def run_periodically(grouper: FaceGrouper, lock, interval: int):
    """Timer trigger for grouping; the caller serializes it with indexing."""

    while True:
        await asyncio.sleep(interval)

        async with lock:
            try:
                await grouper.run_once()
            except Exception:
                log.exception("faces grouping failed; retrying on schedule")


def _parse_batch(rows: list[dict]) -> list[BatchFace]:
    """Keep well-formed due rows; malformed rows never stall the pass."""

    faces = []

    for row in rows:
        try:
            face = BatchFace(
                id=int(row["id"]),
                fileid=int(row["fileid"]),
                owner=str(row["owner"]),
                embed_version=int(row["embed_version"]),
            )
        except (KeyError, TypeError, ValueError):
            continue

        if face.id > 0 and face.fileid > 0 and face.owner:
            faces.append(face)

    if len(faces) != len(rows):
        log.warning("faces batch dropped %d malformed rows", len(rows) - len(faces))

    return faces


def _cap_files(faces: list[BatchFace], max_files: int) -> list[BatchFace]:
    """Keep whole files up to the file cap, preserving batch order."""

    seen: set[int] = set()
    capped = []

    for face in faces:
        if face.fileid not in seen:
            if len(seen) >= max_files:
                break

            seen.add(face.fileid)

        capped.append(face)

    return capped


def _nearest(vector: list[float], samples) -> float:
    """Closest cosine distance from one vector to a cluster sample."""

    target = np.asarray(vector, dtype=np.float64)
    norm = np.linalg.norm(target)

    if norm == 0:
        return float("inf")

    sample = np.asarray(list(samples), dtype=np.float64)
    norms = np.linalg.norm(sample, axis=1)
    norms[norms == 0] = 1

    return float(1 - (sample @ target / (norms * norm)).max())


def _distances(vectors: list[list[float]]) -> np.ndarray:
    """Exact pairwise cosine distances for the bounded mint batch."""

    mat = np.asarray(vectors, dtype=np.float64)
    norms = np.linalg.norm(mat, axis=1, keepdims=True)
    norms[norms == 0] = 1

    return 1 - (mat / norms) @ (mat / norms).T
