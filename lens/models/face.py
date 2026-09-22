"""Face detection + recognition backend: pinned YuNet/SFace ONNX, load, detect, embed."""

# Provisioning mirrors sentence.py (URL+SHA files instead of an HF snapshot).
# YuNet decode ports OpenCV's FaceDetectorYN postprocess; SFace alignment ports
# FaceRecognizerSF's similarity warp. Both verified to parity against the cv2
# wrappers (cosine 1.0) in the Phase 0 spike.
# pylint: disable=no-member

import asyncio
import hashlib
import io
import logging
import os
import time
import urllib.request

import cv2
import numpy as np
import onnxruntime as ort
from PIL import Image

from config import config
from models.common import inference_sem

log = logging.getLogger("lens.face")

STRIDES = (8, 16, 32)
DIVISOR = 32
NMS_THRESHOLD = 0.3
TOP_K = 5000
ALIGN_SIZE = 112

DST_TEMPLATE = np.array(
    [
        [38.2946, 51.6963],
        [73.5318, 51.5014],
        [56.0252, 71.7366],
        [41.5493, 92.3655],
        [70.7299, 92.2041],
    ],
    dtype=np.float64,
)
DST_MEAN = np.array([56.0262, 71.9008], dtype=np.float64)


class FaceModel:
    """Pinned YuNet + SFace checkpoints: files on disk, loaded ORT sessions."""

    def __init__(self):
        """Empty state; call ensure_snapshot + load before detecting."""

        self._det = None
        self._rec = None
        self._det_input = ""
        self._rec_input = ""
        self._dim = 0
        self._device = "pending"

    def snapshot_path(self, name: str) -> str:
        """Local weight path, namespaced under the shared model cache dir."""

        return os.path.join(config.model_cache_dir, f"faces--{name}.onnx")

    def _snapshot_complete(self, path: str, sha: str) -> bool:
        """True when the file exists and matches its pinned SHA256."""

        if not os.path.isfile(path):
            return False

        digest = hashlib.sha256()

        with open(path, "rb") as handle:
            for chunk in iter(lambda: handle.read(1 << 20), b""):
                digest.update(chunk)

        return digest.hexdigest() == sha

    def _fetch(self, url: str, path: str, sha: str) -> None:
        """Download a weight file (retries + backoff); fatal on SHA mismatch."""

        for attempt in range(1, 4):
            try:
                with urllib.request.urlopen(url, timeout=60) as res, open(path, "wb") as handle:
                    while True:
                        chunk = res.read(1 << 20)

                        if not chunk:
                            break

                        handle.write(chunk)

                if self._snapshot_complete(path, sha):
                    log.info("face weights ready at %s", path)

                    return

                raise RuntimeError(f"SHA256 mismatch for {url}")
            except Exception as exc:  # pylint: disable=broad-exception-caught
                log.warning("face download attempt %d/3 failed: %s", attempt, exc)
                time.sleep(2**attempt)

        if self._snapshot_complete(path, sha):
            log.warning("download failed; serving from cached weights at %s", path)

            return

        raise RuntimeError(f"face weights unreachable and no cached weights at {path}")

    def ensure_snapshot(self) -> str:
        """Download both pinned weight files; fatal if unreachable and uncached."""

        offline = os.environ.get("HF_HUB_OFFLINE") == "1"
        paths = {}

        for name, url, sha in (
            ("yunet", config.face.det_url, config.face.det_sha),
            ("sface", config.face.rec_url, config.face.rec_sha),
        ):
            path = self.snapshot_path(name)

            if offline:
                if not self._snapshot_complete(path, sha):
                    raise RuntimeError(f"HF_HUB_OFFLINE=1 but no complete weights at {path}")

                log.warning("HF_HUB_OFFLINE=1: serving from cached weights at %s", path)
            elif not self._snapshot_complete(path, sha):
                log.info("resolving face weights %s", url)
                self._fetch(url, path, sha)

            paths[name] = path

        return paths["yunet"] + "," + paths["sface"]

    def load(self) -> int:
        """Load both ORT sessions (CUDA preferred, CPU fallback); return dim D."""

        det_path = self.snapshot_path("yunet")
        rec_path = self.snapshot_path("sface")
        providers = ["CUDAExecutionProvider", "CPUExecutionProvider"]

        log.info("loading face models from %s and %s", det_path, rec_path)

        self._det = ort.InferenceSession(det_path, providers=providers)
        self._rec = ort.InferenceSession(rec_path, providers=providers)
        self._det_input = self._det.get_inputs()[0].name
        self._rec_input = self._rec.get_inputs()[0].name
        self._dim = int(self._rec.get_outputs()[0].shape[1])
        used = self._det.get_providers()[0]
        self._device = "cuda" if used == "CUDAExecutionProvider" else "cpu"

        log.info("face models loaded, dim D=%d on %s", self._dim, self._device)

        return self._dim

    @staticmethod
    def decode_image(data: bytes) -> np.ndarray:
        """Decode image bytes (any PIL format incl. HEIC) to BGR pixels."""

        return cv2.cvtColor(np.array(Image.open(io.BytesIO(data)).convert("RGB")), cv2.COLOR_RGB2BGR)

    def detect_image(self, image: np.ndarray) -> list[dict]:
        """Detect faces in BGR pixels; boxes/landmarks as fractions, sorted by (x, y)."""

        blob, det_w, det_h, pad_w = self._preprocess(image)
        outputs = self._det.run(None, {self._det_input: blob})
        faces = self._decode(outputs, pad_w, det_w, det_h)
        faces.sort(key=lambda face: (face["x"], face["y"]))

        return faces

    @staticmethod
    def _preprocess(image: np.ndarray) -> tuple[np.ndarray, int, int, int]:
        """Downscale to the working cap, pad to a multiple of 32, build the BGR blob."""

        height, width = image.shape[:2]
        scale = min(1.0, config.face.det_max_side / max(height, width))

        if scale < 1.0:
            image = cv2.resize(image, (int(width * scale), int(height * scale)))

        det_h, det_w = image.shape[:2]
        pad_w = ((det_w - 1) // DIVISOR + 1) * DIVISOR
        pad_h = ((det_h - 1) // DIVISOR + 1) * DIVISOR
        padded = cv2.copyMakeBorder(image, 0, pad_h - det_h, 0, pad_w - det_w, cv2.BORDER_CONSTANT)

        return cv2.dnn.blobFromImage(padded), det_w, det_h, pad_w

    def _decode(self, outputs: list[np.ndarray], pad_w: int, det_w: int, det_h: int) -> list[dict]:
        """Port of OpenCV's YuNet postprocess (sqrt cls*obj score, exp boxes, NMS)."""

        boxes, scores, landmarks = [], [], []

        for i, stride in enumerate(STRIDES):
            decoded = _decode_stride(outputs, i, stride, pad_w)

            if decoded is not None:
                boxes.extend(decoded[0])
                scores.extend(decoded[1])
                landmarks.extend(decoded[2])

        if not boxes:
            return []

        kept = cv2.dnn.NMSBoxes(boxes, scores, config.face.det_threshold, NMS_THRESHOLD, 1.0, TOP_K)
        kept = np.array(kept).reshape(-1)

        return [_format_face(boxes[k], scores[k], landmarks[k], det_w, det_h) for k in kept]

    def embed_image_faces(self, image: np.ndarray, faces: list[dict]) -> list[list[float]]:
        """Align each face to 112x112 and embed in one session run; L2-normed 128-d."""

        return self.embed_crops(self.align_faces(image, faces))

    @staticmethod
    def align_faces(image: np.ndarray, faces: list[dict]) -> list[np.ndarray]:
        """Warp each face's landmarks to a 112x112 BGR crop (SFace reference geometry)."""

        height, width = image.shape[:2]

        return [_align_crop(image, face, width, height) for face in faces]

    def embed_crops(self, crops: list[np.ndarray]) -> list[list[float]]:
        """Embed 112x112 BGR crops in one session run; L2-normed 128-d vectors."""

        batch = np.stack([cv2.cvtColor(crop, cv2.COLOR_BGR2RGB).astype(np.float32) for crop in crops])
        batch = batch.transpose(0, 3, 1, 2)
        vectors = self._rec.run(None, {self._rec_input: batch})[0].astype(np.float64)
        vectors /= np.linalg.norm(vectors, axis=1, keepdims=True)

        return vectors.tolist()

    async def detect_async(self, image: np.ndarray) -> list[dict]:
        """Serialize YuNet inference through the shared semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.detect_image, image)

    async def embed_async(self, image: np.ndarray, faces: list[dict]) -> list[list[float]]:
        """Serialize one batched SFace inference through the shared semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.embed_image_faces, image, faces)

    def dim(self) -> int:
        """Face embedding dim D (0 until loaded)."""

        return self._dim

    def device(self) -> str:
        """Resolved device, or 'pending' before load."""

        return self._device


def _decode_stride(  # pylint: disable=too-many-locals
    outputs: list[np.ndarray],
    level: int,
    stride: int,
    pad_w: int,
) -> tuple | None:
    """Decode one YuNet stride level; None when nothing passes the threshold."""

    cls = outputs[level].reshape(-1)
    obj = outputs[level + 3].reshape(-1)
    score = np.sqrt(np.clip(cls, 0, 1) * np.clip(obj, 0, 1))
    keep = np.where(score >= config.face.det_threshold)[0]

    if keep.size == 0:
        return None

    cols = pad_w // stride
    row, col = keep // cols, keep % cols
    bbox = outputs[level + 6].reshape(-1, 4)[keep]
    kps = outputs[level + 9].reshape(-1, 10)[keep]
    width = np.exp(bbox[:, 2]) * stride
    height = np.exp(bbox[:, 3]) * stride
    boxes = np.stack([(col + bbox[:, 0]) * stride - width / 2, (row + bbox[:, 1]) * stride - height / 2,
                      width, height], axis=1).tolist()
    landmarks = (np.stack([kps[:, 0::2] + col[:, None], kps[:, 1::2] + row[:, None]], axis=2) * stride).tolist()

    return boxes, score[keep].tolist(), landmarks


def _format_face(box: list[float], score: float, landmarks: list, det_w: int, det_h: int) -> dict:
    """One decoded detection as clamped fractions with landmark fractions."""

    return {
        "x": min(max(box[0] / det_w, 0.0), 1.0),
        "y": min(max(box[1] / det_h, 0.0), 1.0),
        "w": min(max(box[2] / det_w, 0.0), 1.0),
        "h": min(max(box[3] / det_h, 0.0), 1.0),
        "landmarks5": [[px / det_w, py / det_h] for px, py in landmarks],
        "det_score": score,
    }


def _align_crop(image: np.ndarray, face: dict, width: int, height: int) -> np.ndarray:
    """Warp one face's landmarks to a 112x112 BGR crop via the SFace similarity fit."""

    matrix = _similarity_matrix(np.array(face["landmarks5"]) * [width, height])

    return cv2.warpAffine(image, matrix, (ALIGN_SIZE, ALIGN_SIZE), flags=cv2.INTER_LINEAR)


def _similarity_matrix(src: np.ndarray) -> np.ndarray:
    """Umeyama similarity src->DST_TEMPLATE; port of FaceRecognizerSF's warp fit."""

    src_mean = src.mean(axis=0)
    src_demean = src - src_mean
    dst_demean = DST_TEMPLATE - DST_MEAN
    mat = np.einsum("ij,ik->jk", dst_demean, src_demean) / 5
    adjust = np.array([1.0, -1.0 if np.linalg.det(mat) < 0 else 1.0])
    mat_u, singular, vt = np.linalg.svd(mat)
    rotation = mat_u @ np.diag(adjust) @ vt
    scale = float((singular * adjust).sum() / ((src_demean**2).sum() / 5))
    shift = DST_MEAN - scale * (rotation @ src_mean)

    return np.hstack([rotation * scale, shift[:, None]])
