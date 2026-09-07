from __future__ import annotations

import numpy as np

from freshrss_semantic.embeddings import embed


class FakeEncoder:
    def __init__(self) -> None:
        self.batches: list[list[str]] = []

    def encode(self, sentences, **_kwargs):
        self.batches.append(list(sentences))
        return np.array([[float(len(text)), 1.0] for text in sentences], dtype=np.float32)


def test_embed_batches_and_repeat_run_is_idempotent(populate) -> None:
    store = populate(entries=5)
    encoder = FakeEncoder()
    assert embed(store, lambda _model: encoder) == 5
    assert [len(batch) for batch in encoder.batches] == [2, 2, 1]
    assert embed(store, lambda _model: (_ for _ in ()).throw(AssertionError("model loaded"))) == 0
    assert store.get_state("pending_embedding_count") == "0"

