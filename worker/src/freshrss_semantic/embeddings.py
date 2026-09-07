"""Bounded Model2Vec embedding phase."""

from __future__ import annotations

import logging
import os
import resource
import time
from collections.abc import Callable, Sequence
from pathlib import Path
from typing import Any, Protocol

import numpy as np
from numpy.typing import NDArray

from .semantic_store import SemanticStore

LOGGER = logging.getLogger(__name__)
DEFAULT_MODEL_ID = "minishlab/potion-base-8M"
DEFAULT_BUNDLED_MODEL = Path("/opt/models/potion-base-8M")


class Encoder(Protocol):
    def encode(self, sentences: Sequence[str], **kwargs: Any) -> NDArray[np.float32]: ...


def load_model(model_id: str) -> Encoder:
    """Load the configured model, preferring the image-bundled default copy."""
    os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")
    from model2vec import StaticModel

    location = (
        str(DEFAULT_BUNDLED_MODEL)
        if model_id == DEFAULT_MODEL_ID and DEFAULT_BUNDLED_MODEL.is_dir()
        else model_id
    )
    return StaticModel.from_pretrained(location)


def embed(
    store: SemanticStore,
    encoder_factory: Callable[[str], Encoder] = load_model,
) -> int:
    """Embed every missing or stale active input in short committed batches."""
    started = time.monotonic()
    snapshot = store.load_snapshot()
    store.require_live(snapshot)
    with store.connection() as db:
        pending = store.pending_count(db, snapshot)
    store.set_state("pending_embedding_count", str(pending))
    store.set_state("last_attempted_embedding", str(int(time.time())))
    if pending == 0:
        store.set_state("last_successful_embedding", str(int(time.time())))
        return 0

    encoder = encoder_factory(snapshot.config.embedding_model)
    completed = 0
    while True:
        with store.connection() as db:
            store.assert_snapshot(db, snapshot)
            batch = store.pending_inputs(db, snapshot, snapshot.config.embedding_batch_size)
        if not batch:
            break
        texts = [item.embedding_text for item in batch]
        # Model2Vec encoding is single-process; batching here is the memory bound.
        vectors = np.asarray(
            encoder.encode(
                texts,
                batch_size=len(texts),
                use_multiprocessing=False,
                show_progress_bar=False,
            ),
            dtype="<f4",
        )
        store.store_embeddings(snapshot, batch, vectors)
        completed += len(batch)
        store.set_state("pending_embedding_count", str(max(pending - completed, 0)))

    with store.connection() as db:
        store.assert_snapshot(db, snapshot)
    store.set_state("last_successful_embedding", str(int(time.time())))
    store.set_state("pending_embedding_count", "0")
    LOGGER.info(
        "embedded entries=%d model=%s duration_seconds=%.3f peak_rss_mib=%.1f",
        completed,
        snapshot.config.embedding_model,
        time.monotonic() - started,
        resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024,
    )
    return completed
