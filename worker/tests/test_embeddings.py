from __future__ import annotations

from pathlib import Path

import numpy as np

from freshrss_semantic.embeddings import embed
from freshrss_semantic.semantic_store import SemanticStore, open_connection


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


def assert_database_is_free(database: str | Path) -> None:
    """No lock or transaction of the worker's survives into computation."""
    # A short timeout: a held shared or write lock fails this quickly.
    writer = open_connection(database, busy_timeout_ms=100)
    try:
        writer.begin_write()
        writer.execute("UPDATE pipeline_config SET updated_at = updated_at")
        writer.commit()
        if isinstance(database, str):
            idle = writer.execute(
                "SELECT COUNT(*) FROM pg_stat_activity"
                " WHERE datname = current_database() AND state LIKE 'idle in transaction%'"
            ).fetchone()[0]
            assert idle == 0
    finally:
        writer.close()


def test_embed_phase_uses_one_connection_and_encodes_outside_transactions(
    populate, database, monkeypatch
) -> None:
    store = populate(entries=5)
    connections = 0
    real_connect = SemanticStore.connect

    def counting_connect(self):
        nonlocal connections
        connections += 1
        return real_connect(self)

    class CheckingEncoder(FakeEncoder):
        def encode(self, sentences, **kwargs):
            assert_database_is_free(database)
            return super().encode(sentences, **kwargs)

    monkeypatch.setattr(SemanticStore, "connect", counting_connect)
    assert embed(store, lambda _model: CheckingEncoder()) == 5
    assert connections == 1


def test_load_model_prefers_bundled_copies_and_falls_back_to_hub(tmp_path, monkeypatch) -> None:
    import model2vec

    from freshrss_semantic import embeddings

    bundled_32m, bundled_8m = tmp_path / "potion-base-32M", tmp_path / "potion-base-8M"
    bundled_32m.mkdir()
    bundled_8m.mkdir()
    monkeypatch.setattr(
        embeddings,
        "BUNDLED_MODELS",
        {
            "minishlab/potion-base-32M": bundled_32m,
            "minishlab/potion-base-8M": bundled_8m,
            "minishlab/not-built-in": tmp_path / "absent",
        },
    )
    loaded: list[str] = []

    def record(_cls: type, path: str) -> None:
        loaded.append(path)

    monkeypatch.setattr(model2vec.StaticModel, "from_pretrained", classmethod(record))
    for model_id in (
        "minishlab/potion-base-32M",
        "minishlab/potion-base-8M",
        "minishlab/not-built-in",
        "someone/other-model",
    ):
        embeddings.load_model(model_id)
    assert loaded == [
        str(bundled_32m),
        str(bundled_8m),
        "minishlab/not-built-in",
        "someone/other-model",
    ]
