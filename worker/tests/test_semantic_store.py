from __future__ import annotations

import json
import sqlite3
from pathlib import Path

import numpy as np
import psycopg
import pytest
from conftest import get_schema_version, raw, set_schema_version

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

    raw(
        store.database,
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
    raw(store.database, "UPDATE pipeline_config SET config_revision='revision-v2'")
    with pytest.raises(SnapshotChanged):
        store.store_embeddings(snapshot, pending, np.ones((1, 2), dtype=np.float32))
    assert raw(store.database, "SELECT COUNT(*) FROM embeddings") == [(0,)]


def test_grouping_refuses_partial_or_malformed_vectors(populate) -> None:
    store = populate(entries=2)
    snapshot = store.load_snapshot()
    with store.connection() as db, pytest.raises(IncompleteEmbeddings):
        store.load_grouping_inputs(db, snapshot)

    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 2)
    store.store_embeddings(snapshot, pending, np.ones((2, 2), dtype=np.float32))
    with store.connection() as db:
        inputs = store.load_grouping_inputs(db, snapshot)
    assert [item.normalized_title for item in inputs] == ["entry 0", "entry 1"]

    raw(
        store.database,
        "UPDATE embeddings SET embedding=? WHERE entry_id=?",
        (b"\x00\x00", pending[0].entry_id),
    )
    with store.connection() as db, pytest.raises(StoreError, match="invalid embedding BLOB"):
        store.load_grouping_inputs(db, snapshot)


def test_group_publication_rolls_back_without_losing_previous_groups(populate) -> None:
    store = populate(entries=2)
    snapshot = store.load_snapshot()
    old = PublishedGroup("old", "first", ("first", "second"))
    store.publish_groups(snapshot, [old])
    assert raw(store.database, "SELECT similarity FROM group_members") == [(None,), (None,)]
    invalid = PublishedGroup("new", "first", ("first", "first"))
    with pytest.raises((sqlite3.IntegrityError, psycopg.IntegrityError)):
        store.publish_groups(snapshot, [invalid])
    assert raw(store.database, "SELECT group_id FROM groups") == [("old",)]
    assert raw(store.database, "SELECT COUNT(*) FROM group_members") == [(2,)]


def test_cleanup_removes_only_embeddings_without_any_source_input(populate) -> None:
    store = populate(entries=1)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 1)
    store.store_embeddings(snapshot, pending, np.ones((1, 2), dtype=np.float32))
    raw(store.database, "DELETE FROM candidate_members")
    raw(store.database, "DELETE FROM article_inputs")
    assert store.cleanup_orphan_embeddings() == 1


def test_cleanup_uses_the_exact_source_version(populate) -> None:
    store = populate(entries=1)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 1)
    store.store_embeddings(snapshot, pending, np.ones((1, 2), dtype=np.float32))
    raw(
        store.database,
        "UPDATE embeddings SET source_hash='removed-version' WHERE entry_id=?",
        (pending[0].entry_id,),
    )
    assert store.cleanup_orphan_embeddings() == 1


def test_worker_refuses_to_migrate_or_replace_unknown_schema(database: str | Path) -> None:
    set_schema_version(database, 99)
    with pytest.raises(SchemaVersionError):
        SemanticStore(database).connect()
    assert get_schema_version(database) == 99


def test_worker_waits_for_an_extension_created_postgres_schema(empty_postgres: str) -> None:
    store = SemanticStore(empty_postgres)
    assert not store.exists()
    with pytest.raises(SchemaVersionError, match="does not exist"):
        store.connect()
    raw(empty_postgres, "CREATE TABLE schema_version (singleton INTEGER, version INTEGER)")
    raw(empty_postgres, "INSERT INTO schema_version VALUES (1, 3)")
    assert store.exists()
    with pytest.raises(SchemaVersionError, match="missing required tables"):
        store.connect()


def test_postgres_writers_wait_for_each_other(populate, database: str | Path) -> None:
    if not isinstance(database, str):
        pytest.skip("SQLite serializes writers itself")
    store = populate(entries=0)
    holder = SemanticStore(database).connect()
    try:
        holder.begin_write()
        waiting = SemanticStore(database, busy_timeout_ms=100)
        with pytest.raises(psycopg.errors.LockNotAvailable):
            waiting.rebuild_worker_state()
        holder.rollback()
        waiting.rebuild_worker_state()
    finally:
        holder.close()
    assert store.state_dict() == {}


def test_invalid_json_does_not_change_existing_groups(populate) -> None:
    store = populate(entries=0)
    snapshot = store.load_snapshot()
    store.publish_groups(snapshot, [])
    raw(
        store.database, "UPDATE pipeline_config SET config_json=?", (json.dumps({"enabled": True}),)
    )
    with pytest.raises(ValueError):
        store.load_snapshot()
