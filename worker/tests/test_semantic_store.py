from __future__ import annotations

import json
import sqlite3
from pathlib import Path

import numpy as np
import pytest

from freshrss_semantic.semantic_store import (
    IncompleteEmbeddings,
    PublishedGroup,
    SchemaVersionError,
    SemanticStore,
    SnapshotChanged,
    StoreError,
)


def test_missing_and_stale_embeddings_are_discovered_and_idempotent(populate) -> None:
    store = populate(entries=2)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 128)
    assert len(pending) == 2

    store.store_embeddings(snapshot, pending, np.array([[1.0, 0.0], [0.0, 1.0]]))
    with store.connection() as db:
        assert store.pending_count(db, snapshot) == 0

    with sqlite3.connect(store.database) as db:
        db.execute(
            "UPDATE embeddings SET source_hash='stale' WHERE entry_id=?",
            (pending[0].entry_id,),
        )
    with store.connection() as db:
        stale_ids = [item.entry_id for item in store.pending_inputs(db, snapshot, 128)]
        assert stale_ids == [pending[0].entry_id]


def test_snapshot_change_aborts_embedding_commit(populate) -> None:
    store = populate(entries=1)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 1)
    with sqlite3.connect(store.database) as db:
        db.execute("UPDATE pipeline_config SET config_revision='revision-v2'")
    with pytest.raises(SnapshotChanged):
        store.store_embeddings(snapshot, pending, np.ones((1, 2), dtype=np.float32))
    with sqlite3.connect(store.database) as db:
        assert db.execute("SELECT COUNT(*) FROM embeddings").fetchone()[0] == 0


def test_grouping_refuses_partial_or_malformed_vectors(populate) -> None:
    store = populate(entries=2)
    snapshot = store.load_snapshot()
    with store.connection() as db, pytest.raises(IncompleteEmbeddings):
        store.load_grouping_inputs(db, snapshot)

    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 2)
    store.store_embeddings(snapshot, pending, np.ones((2, 2), dtype=np.float32))
    with sqlite3.connect(store.database) as db:
        db.execute(
            "UPDATE embeddings SET embedding=x'0000' WHERE entry_id=?",
            (pending[0].entry_id,),
        )
    with store.connection() as db, pytest.raises(StoreError, match="invalid embedding BLOB"):
        store.load_grouping_inputs(db, snapshot)


def test_group_publication_rolls_back_without_losing_previous_groups(populate) -> None:
    store = populate(entries=2)
    snapshot = store.load_snapshot()
    old = PublishedGroup("old", "first", ("first", "second"))
    store.publish_groups(snapshot, [old])
    with sqlite3.connect(store.database) as db:
        assert db.execute("SELECT similarity FROM group_members").fetchall() == [
            (None,),
            (None,),
        ]
    invalid = PublishedGroup("new", "first", ("first", "first"))
    with pytest.raises(sqlite3.IntegrityError):
        store.publish_groups(snapshot, [invalid])
    with sqlite3.connect(store.database) as db:
        assert db.execute("SELECT group_id FROM groups").fetchall() == [("old",)]
        assert db.execute("SELECT COUNT(*) FROM group_members").fetchone()[0] == 2


def test_cleanup_removes_only_embeddings_without_any_source_input(populate) -> None:
    store = populate(entries=1)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 1)
    store.store_embeddings(snapshot, pending, np.ones((1, 2), dtype=np.float32))
    with sqlite3.connect(store.database) as db:
        db.execute("PRAGMA foreign_keys=ON")
        db.execute("DELETE FROM candidate_members")
        db.execute("DELETE FROM article_inputs")
    assert store.cleanup_orphan_embeddings() == 1


def test_cleanup_uses_the_exact_source_version(populate) -> None:
    store = populate(entries=1)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 1)
    store.store_embeddings(snapshot, pending, np.ones((1, 2), dtype=np.float32))
    with sqlite3.connect(store.database) as db:
        db.execute(
            "UPDATE embeddings SET source_hash='removed-version' WHERE entry_id=?",
            (pending[0].entry_id,),
        )
    assert store.cleanup_orphan_embeddings() == 1


def test_worker_refuses_to_migrate_or_replace_unknown_schema(database: Path) -> None:
    with sqlite3.connect(database) as db:
        db.execute("PRAGMA user_version=99")
    with pytest.raises(SchemaVersionError):
        SemanticStore(database).connect()
    with sqlite3.connect(database) as db:
        assert db.execute("PRAGMA user_version").fetchone()[0] == 99


def test_invalid_json_does_not_change_existing_groups(populate) -> None:
    store = populate(entries=0)
    snapshot = store.load_snapshot()
    store.publish_groups(snapshot, [])
    with sqlite3.connect(store.database) as db:
        db.execute("UPDATE pipeline_config SET config_json=?", (json.dumps({"enabled": True}),))
    with pytest.raises(ValueError):
        store.load_snapshot()
