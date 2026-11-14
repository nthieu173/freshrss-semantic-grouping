from __future__ import annotations

import json
import sqlite3
from pathlib import Path

import numpy as np
import psycopg
import pytest
from conftest import get_schema_version, raw, set_schema_version

from freshrss_semantic.semantic_store import (
    Connection,
    IncompleteEmbeddings,
    PublishedGroup,
    SchemaVersionError,
    SemanticStore,
    SnapshotChanged,
    StoreError,
    open_connection,
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


def _switch_generation(database: str | Path, busy_timeout_ms: int = 5000) -> None:
    """What the exporter does: activate a new generation, then prune the old one."""
    connection = open_connection(database, busy_timeout_ms)
    try:
        connection.begin_write()
        try:
            connection.execute("INSERT INTO candidate_generations VALUES (2, 'query-v1', 1, 1, 0)")
            connection.execute("UPDATE pipeline_config SET active_generation = 2")
            connection.execute("DELETE FROM candidate_generations WHERE generation = 1")
        except BaseException:
            connection.rollback()
            raise
        connection.commit()
    finally:
        connection.close()


class _ProducerBetweenReads:
    """A connection that lets the producer commit right after the candidate count."""

    def __init__(self, connection: Connection, database: str | Path) -> None:
        self.connection = connection
        self.database = database
        self.producer_error: BaseException | None = None

    def __getattr__(self, name: str):
        return getattr(self.connection, name)

    def execute(self, sql: str, parameters=()):
        cursor = self.connection.execute(sql, parameters)
        if sql.startswith("SELECT COUNT(*) FROM candidate_members"):
            try:
                _switch_generation(self.database, busy_timeout_ms=100)
            except (sqlite3.OperationalError, psycopg.errors.LockNotAvailable) as error:
                self.producer_error = error
        return cursor


def test_grouping_inputs_are_one_consistent_read(populate, database) -> None:
    store = populate(entries=2)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 2)
    store.store_embeddings(snapshot, pending, np.ones((2, 2), dtype=np.float32))

    with store.connection() as db:
        racing = _ProducerBetweenReads(db, database)
        # Never a false "still need embeddings" from mixing two states.
        inputs = store.load_grouping_inputs(racing, snapshot)
    assert len(inputs) == 2
    if isinstance(database, Path):
        # SQLite: the read's shared lock holds the producer's commit back.
        assert isinstance(racing.producer_error, sqlite3.OperationalError)
        _switch_generation(database)
    else:
        # PostgreSQL: the producer committed, unseen by the snapshot read.
        assert racing.producer_error is None
    with store.connection() as db, pytest.raises(SnapshotChanged):
        store.load_grouping_inputs(db, snapshot)


def test_publication_writes_are_batched(populate) -> None:
    store = populate(entries=2)
    snapshot = store.load_snapshot()
    statements: list[tuple[str, str, int]] = []

    with store.session():
        real = store._session

        class Recording:
            def __getattr__(self, name: str):
                return getattr(real, name)

            def execute(self, sql: str, parameters=()):
                statements.append(("execute", " ".join(sql.split())[:30], 1))
                return real.execute(sql, parameters)

            def executemany(self, sql: str, rows):
                statements.append(("executemany", " ".join(sql.split())[:30], len(rows)))
                return real.executemany(sql, rows)

        store._session = Recording()
        with store.connection() as db:
            pending = store.pending_inputs(db, snapshot, 2)
        store.store_embeddings(snapshot, pending, np.ones((2, 2), dtype=np.float32))
        store.publish_groups(
            snapshot,
            [
                PublishedGroup("a", "1", ("1", "2", "3")),
                PublishedGroup("b", "4", ("4", "5")),
            ],
        )
        store._session = real

    inserts = [entry for entry in statements if "INSERT" in entry[1]]
    assert inserts == [
        ("executemany", "INSERT INTO embeddings( entry_", 2),
        ("executemany", "INSERT INTO groups( group_id, ", 2),
        ("executemany", "INSERT INTO group_members(grou", 5),
        ("executemany", "INSERT INTO worker_state(key, ", 5),
    ]
    assert raw(store.database, "SELECT group_id, entry_id FROM group_members ORDER BY 1, 2") == [
        ("a", "1"),
        ("a", "2"),
        ("a", "3"),
        ("b", "4"),
        ("b", "5"),
    ]
    assert store.state_dict()["current_group_count"] == "2"


def test_session_reuses_one_validated_connection(populate, monkeypatch) -> None:
    store = populate(entries=1)
    opened: list[Connection] = []
    real_connect = SemanticStore.connect

    def counting_connect(self):
        opened.append(real_connect(self))
        return opened[-1]

    monkeypatch.setattr(SemanticStore, "connect", counting_connect)
    with store.session():
        snapshot = store.load_snapshot()
        store.set_state("key", "value")
        assert store.get_state("key") == "value"
        with store.session(), store.connection() as db:
            assert store.pending_count(db, snapshot) == 1
    assert len(opened) == 1
    # Closed with the session.
    with pytest.raises((sqlite3.ProgrammingError, psycopg.OperationalError)):
        opened[0].execute("SELECT 1")
    assert store.get_state("key") == "value"
    assert len(opened) == 2
