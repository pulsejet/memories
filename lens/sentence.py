"""Sentence embedding backend: pinned e5 snapshot, load, encode."""

# Mirrors embedding.py provisioning; keep the two in sync by hand.
# pylint: disable=duplicate-code

import asyncio
import logging
import os
import time

import torch
from huggingface_hub import snapshot_download

from config import config
from embedding import inference_sem

log = logging.getLogger("lens.sentence")


class SentenceModel:
    """One pinned sentence checkpoint: snapshot on disk, loaded encoder."""

    def __init__(self):
        """Empty state; call ensure_snapshot + load before encoding."""

        self._model = None
        self._dim = 0
        self._device = "pending"

    def snapshot_dir(self) -> str:
        """Local snapshot dir, namespaced by model id for multi-model caches."""

        name = config.sentence_model.model_id.replace("/", "--")

        return os.path.join(config.model_cache_dir, name)

    def _snapshot_complete(self, path: str) -> bool:
        """True when the dir holds config, tokenizer and weights."""

        if not os.path.isfile(os.path.join(path, "config.json")):
            return False

        if not os.path.isfile(os.path.join(path, "tokenizer.json")):
            return False

        if os.path.isfile(os.path.join(path, "model.safetensors")):
            return True

        return os.path.isfile(os.path.join(path, "pytorch_model.bin"))

    def ensure_snapshot(self) -> str:
        """Download the pinned snapshot (retries + backoff); fatal if unreachable and uncached."""

        path = self.snapshot_dir()
        offline = os.environ.get("HF_HUB_OFFLINE") == "1"

        if offline:
            if self._snapshot_complete(path):
                log.warning("HF_HUB_OFFLINE=1: serving from cached snapshot at %s", path)

                return path

            raise RuntimeError(f"HF_HUB_OFFLINE=1 but no complete snapshot at {path}")

        log.info("resolving snapshot %s @ %s", config.sentence_model.model_id, config.sentence_model.model_revision)

        for attempt in range(1, 4):
            try:
                snapshot_download(
                    repo_id=config.sentence_model.model_id,
                    revision=config.sentence_model.model_revision,
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
        """Load sentence model from the local snapshot only; return dim D."""

        from sentence_transformers import SentenceTransformer  # pylint: disable=import-outside-toplevel

        if config.torch_num_threads:
            torch.set_num_threads(config.torch_num_threads)

        try:
            torch.set_num_interop_threads(1)
        except RuntimeError:
            log.debug("interop threads already set, keeping current value")

        if config.device == "cuda" or (config.device == "auto" and torch.cuda.is_available()):
            self._device = "cuda"
        else:
            self._device = "cpu"

        path = self.snapshot_dir()

        log.info("loading %s from %s on %s", config.sentence_model.model_id, path, self._device)

        self._model = SentenceTransformer(
            model_name_or_path=path,
            device=self._device,
            trust_remote_code=False,
            local_files_only=True,
        )
        self._dim = self._model.get_embedding_dimension()

        log.info("sentence model loaded, dim D=%d", self._dim)

        return self._dim

    def embed_passages(self, texts: list[str]) -> list[list[float]]:
        """Encode passage texts with passage prefix to L2-normed vectors."""

        prefixed = [f"passage: {t}" for t in texts]

        with torch.inference_mode():
            arr = self._model.encode(
                inputs=prefixed,
                normalize_embeddings=True,
                show_progress_bar=False,
            )

        return arr.tolist()

    def embed_query(self, text: str) -> list[float]:
        """Encode a query string with query prefix to an L2-normed vector."""

        with torch.inference_mode():
            arr = self._model.encode(
                inputs=[f"query: {text}"],
                normalize_embeddings=True,
                show_progress_bar=False,
            )

        return arr.tolist()[0]

    async def embed_passages_async(self, texts: list[str]) -> list[list[float]]:
        """Serialize passage inference through the shared semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.embed_passages, texts)

    async def embed_query_async(self, text: str) -> list[float]:
        """Serialize query inference through the shared semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.embed_query, text)

    def dim(self) -> int:
        """Sentence dim D (0 until loaded)."""

        return self._dim

    def device(self) -> str:
        """Resolved device, or 'pending' before load."""

        return self._device
