"""Schema extraction backend: pinned GLiNER2 snapshot, load, extract."""

# Mirrors sentence.py provisioning; keep the three in sync by hand.

import asyncio
import logging
import os
import time
from dataclasses import dataclass

import torch
from huggingface_hub import snapshot_download

from config import config
from models.common import inference_sem

log = logging.getLogger("lens.schema")

LABELS = {
    "city": "Proper noun naming a city, town or village, e.g. Paris, Berlin, Nice, Kyoto.",
    "country": "Proper noun naming a country, e.g. France, Germany, Japan.",
    "region": "Proper noun naming a state, province, county, region or island, "
    "e.g. California, Bavaria, Normandy, Sicily.",
}


@dataclass(frozen=True)
class Span:
    """One extracted proper-noun span with char offsets and confidence."""

    text: str
    label: str
    start: int
    end: int
    score: float = 0.0


class SchemaModel:
    """One pinned extraction checkpoint: snapshot on disk, loaded extractor."""

    def __init__(self):
        """Empty state; call ensure_snapshot + load before extracting."""

        self._model = None
        self._device = "pending"

    def snapshot_dir(self) -> str:
        """Local snapshot dir, namespaced by model id for multi-model caches."""

        name = config.schema_model.model_id.replace("/", "--")

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

        log.info("resolving snapshot %s @ %s", config.schema_model.model_id, config.schema_model.model_revision)

        for attempt in range(1, 4):
            try:
                snapshot_download(
                    repo_id=config.schema_model.model_id,
                    revision=config.schema_model.model_revision,
                    local_dir=path,
                )
                log.info("snapshot ready at %s", path)

                return path
            except Exception as exc:  # noqa: BLE001
                log.warning("snapshot download attempt %d/3 failed: %s", attempt, exc)
                time.sleep(2**attempt)

        if self._snapshot_complete(path):
            log.warning("download failed; serving from cached snapshot at %s", path)

            return path

        raise RuntimeError(f"snapshot unreachable and no cached snapshot at {path}")

    def load(self):
        """Load extractor from the local snapshot only."""

        from gliner2 import AutoExtractor  # noqa: PLC0415

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

        log.info("loading %s from %s on %s", config.schema_model.model_id, path, self._device)

        self._model = AutoExtractor.from_pretrained(
            path,
            map_location=self._device,
            local_files_only=True,
        )

        log.info("schema model loaded")

    def extract(self, text: str) -> list[Span]:
        """Extract geo spans; empty text yields none. Raw model order, NMS is the caller's job."""

        if not text.strip():
            return []

        with torch.inference_mode():
            res = self._model.extract_entities(
                text,
                LABELS,
                threshold=config.schema_model.threshold,
                include_confidence=True,
                include_spans=True,
            )

        spans = []

        for label, items in (res.get("entities") or {}).items():
            for item in items or []:
                spans.append(Span(
                    text=item["text"],
                    label=label,
                    start=item["start"],
                    end=item["end"],
                    score=item.get("confidence", 0.0),
                ))

        return spans

    async def extract_async(self, text: str) -> list[Span]:
        """Serialize extraction through the shared semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.extract, text)

    def device(self) -> str:
        """Resolved device, or 'pending' before load."""

        return self._device
