"""Embedding backend: pinned snapshot provisioning, load, encode."""

import asyncio
import io
import logging
import os
import time

import pillow_heif
import torch
from huggingface_hub import snapshot_download
from PIL import Image

from config import config

pillow_heif.register_heif_opener()

log = logging.getLogger("lens.embedding")

inference_sem = asyncio.Semaphore(1)

REQUIRED_FILES = ("config.json", "preprocessor_config.json", "model.safetensors")
TOKENIZER_FILES = ("tokenizer.json", "tokenizer.model")


class EmbeddingModel:
    """One pinned embedding checkpoint: snapshot on disk, loaded towers, encode."""

    def __init__(self):
        """Empty state; call ensure_snapshot + load before encoding."""

        self._model = None
        self._processor = None
        self._dim = 0
        self._device = "pending"

    def snapshot_dir(self) -> str:
        """Local snapshot dir, namespaced by model id for multi-model caches."""

        name = config.embedding.model_id.replace("/", "--")
        return os.path.join(config.model_cache_dir, name)

    def _snapshot_complete(self, path: str) -> bool:
        """True when the dir holds weights, config and a tokenizer."""

        for name in REQUIRED_FILES:
            if not os.path.isfile(os.path.join(path, name)):
                return False

        for name in TOKENIZER_FILES:
            if os.path.isfile(os.path.join(path, name)):
                return True

        return False

    def ensure_snapshot(self) -> str:
        """Download the pinned snapshot (retries + backoff); fatal if unreachable and uncached."""

        path = self.snapshot_dir()
        offline = os.environ.get("HF_HUB_OFFLINE") == "1"

        if offline:
            if self._snapshot_complete(path):
                log.warning("HF_HUB_OFFLINE=1: serving from cached snapshot at %s", path)
                return path

            raise RuntimeError(f"HF_HUB_OFFLINE=1 but no complete snapshot at {path}")

        log.info("resolving snapshot %s @ %s", config.embedding.model_id, config.embedding.model_revision)

        for attempt in range(1, 4):
            try:
                snapshot_download(
                    repo_id=config.embedding.model_id,
                    revision=config.embedding.model_revision,
                    local_dir=path,
                )
                log.info("snapshot ready at %s", path)
                return path
            except Exception as exc:  # pylint: disable=broad-exception-caught
                log.warning("snapshot download attempt %d/3 failed: %s", attempt, exc)
                time.sleep(2**attempt)

        if self._snapshot_complete(path):
            log.warning("download failed; serving from cached snapshot at %s", path)
            return path

        raise RuntimeError(f"snapshot unreachable and no cached snapshot at {path}")

    def load(self) -> int:
        """Load processor + model from the local snapshot only; return dim D."""

        from transformers import AutoModel, AutoProcessor  # pylint: disable=import-outside-toplevel

        if config.torch_num_threads:
            torch.set_num_threads(config.torch_num_threads)

        if config.device == "cuda" or (config.device == "auto" and torch.cuda.is_available()):
            self._device = "cuda"
        else:
            self._device = "cpu"

        dtype = torch.float16 if self._device == "cuda" else torch.float32
        path = self.snapshot_dir()

        log.info("loading %s from %s on %s", config.embedding.model_id, path, self._device)

        self._processor = AutoProcessor.from_pretrained(
            pretrained_model_name_or_path=path,
            local_files_only=True,
            trust_remote_code=False,
        )
        self._model = AutoModel.from_pretrained(
            pretrained_model_name_or_path=path,
            dtype=dtype,
            local_files_only=True,
            trust_remote_code=False,
        )
        self._model.to(self._device).eval()

        text_config = getattr(self._model.config, "text_config", self._model.config)
        self._dim = getattr(text_config, "projection_dim", None) or text_config.hidden_size

        log.info("model loaded, dim D=%d", self._dim)

        return self._dim

    def _encode(self, get_features, inputs) -> list[float]:
        """Run one tower under inference mode and return an L2-normed vector."""

        with torch.inference_mode():
            outputs = get_features(**{k: v.to(self._device) for k, v in inputs.items()})

        vector = outputs.pooler_output[0].float()

        return (vector / vector.norm()).tolist()

    def embed_image(self, data: bytes) -> list[float]:
        """Decode image bytes (any PIL format incl. HEIC) to an L2-normed vector."""

        image = Image.open(io.BytesIO(data)).convert("RGB")
        inputs = self._processor(images=image, return_tensors="pt")

        return self._encode(self._model.get_image_features, inputs)

    def embed_text(self, text: str) -> list[float]:
        """Encode a query string to an L2-normed vector (trained padding: max_length)."""

        inputs = self._processor(
            text=[text],
            padding="max_length",
            truncation=True,
            return_tensors="pt",
        )

        return self._encode(self._model.get_text_features, inputs)

    async def embed_image_async(self, data: bytes) -> list[float]:
        """Serialize image inference through the global semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.embed_image, data)

    async def embed_text_async(self, text: str) -> list[float]:
        """Serialize text inference through the global semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.embed_text, text)

    def dim(self) -> int:
        """Embedding dim D (0 until loaded)."""

        return self._dim

    def device(self) -> str:
        """Resolved device, or 'pending' before load."""

        return self._device
