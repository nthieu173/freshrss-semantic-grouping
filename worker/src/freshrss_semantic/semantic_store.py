"""SQLite access with generation/revision compare-before-publish semantics."""

from __future__ import annotations

import json
import re
import sqlite3
import time
from collections.abc import Iterator, Sequence
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from numpy.typing import NDArray

from .config import ConfigurationError, PipelineSnapshot, WorkerConfig

DATABASE_SCHEMA_VERSION = 3


class StoreError(RuntimeError):
    """Base class for semantic database errors."""


class SchemaVersionError(StoreError):
    """The database is absent or belongs to another paired release."""


class SnapshotChanged(StoreError):
    """The producer changed generation or configuration during a phase."""


class IncompleteEmbeddings(StoreError):
    """Not every active candidate has a current embedding."""


@dataclass(frozen=True)
class PendingInput:
    entry_id: str
    source_hash: str
    embedding_text: str


@dataclass(frozen=True)
class GroupingInput:
    entry_id: str
    received_at: int
    normalized_title: str
    embedding: NDArray[np.float32]


@dataclass(frozen=True)
class PublishedGroup:
    group_id: str
    representative_entry_id: str
    members: tuple[str, ...]


def _safe_error(error: BaseException) -> str:
    """Keep state useful without publishing traces or host paths to the UI."""
    text = re.sub(r"(?:[A-Za-z]:)?[/\\][^\s:]+", "<path>", str(error))
    return f"{type(error).__name__}: {text}"[:500]


class SemanticStore:
    """Worker-owned operations on the shared extension-created database."""

    def __init__(self, database: str | Path, busy_timeout_ms: int = 5000) -> None:
        self.database = Path(database)
        self.busy_timeout_ms = busy_timeout_ms

    def connect(self) -> sqlite3.Connection:
        """Open an existing database and validate its release schema."""
        if not self.database.is_file():
            raise SchemaVersionError("semantic database does not exist")
        connection = sqlite3.connect(
            self.database,
            timeout=self.busy_timeout_ms / 1000,
            isolation_level=None,
        )
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys = ON")
        connection.execute(f"PRAGMA busy_timeout = {self.busy_timeout_ms}")
        connection.execute("PRAGMA journal_mode = DELETE")
        try:
            version = int(connection.execute("PRAGMA user_version").fetchone()[0])
            if version != DATABASE_SCHEMA_VERSION:
                raise SchemaVersionError(
                    "unsupported semantic database schema "
                    f"{version}; expected {DATABASE_SCHEMA_VERSION}"
                )
            required = {
                "pipeline_config",
                "candidate_generations",
                "article_inputs",
                "candidate_members",
                "embeddings",
                "groups",
                "group_members",
                "worker_state",
                "export_state",
                "managed_labels",
                "label_sync_state",
            }
            actual = {
                str(row[0])
                for row in connection.execute(
                    "SELECT name FROM sqlite_master WHERE type = 'table'"
                )
            }
            if not required <= actual:
                raise SchemaVersionError("semantic database is missing required tables")
        except Exception:
            connection.close()
            raise
        return connection

    @contextmanager
    def connection(self) -> Iterator[sqlite3.Connection]:
        """Yield a validated connection and always release its file handle."""
        connection = self.connect()
        try:
            yield connection
        finally:
            connection.close()

    @contextmanager
    def transaction(self, connection: sqlite3.Connection) -> Iterator[None]:
        connection.execute("BEGIN IMMEDIATE")
        try:
            yield
        except BaseException:
            connection.rollback()
            raise
        else:
            connection.commit()

    def load_snapshot(self, connection: sqlite3.Connection | None = None) -> PipelineSnapshot:
        """Load and validate the active producer configuration."""
        owned = connection is None
        db = connection or self.connect()
        try:
            row = db.execute(
                """
                SELECT database_schema_version, config_revision, active_generation,
                       producer_lease_until, config_json, updated_at
                  FROM pipeline_config WHERE singleton = 1
                """
            ).fetchone()
            if row is None:
                raise ConfigurationError("the extension has not published a configuration")
            if int(row["database_schema_version"]) != DATABASE_SCHEMA_VERSION:
                raise SchemaVersionError(
                    "pipeline configuration has an incompatible schema version"
                )
            try:
                raw = json.loads(str(row["config_json"]))
            except (TypeError, json.JSONDecodeError) as error:
                raise ConfigurationError("config_json is not valid JSON") from error
            config = WorkerConfig.from_mapping(raw)
            return PipelineSnapshot(
                active_generation=int(row["active_generation"]),
                config_revision=str(row["config_revision"]),
                producer_lease_until=int(row["producer_lease_until"]),
                updated_at=int(row["updated_at"]),
                config=config,
            )
        finally:
            if owned:
                db.close()

    @staticmethod
    def require_live(snapshot: PipelineSnapshot, now: int | None = None) -> None:
        """Refuse new work for a disabled pipeline or expired producer lease."""
        current = int(time.time()) if now is None else now
        if not snapshot.config.enabled:
            raise ConfigurationError("pipeline is disabled")
        if snapshot.producer_lease_until <= current:
            raise ConfigurationError("producer lease has expired")

    @staticmethod
    def assert_snapshot(connection: sqlite3.Connection, snapshot: PipelineSnapshot) -> None:
        row = connection.execute(
            "SELECT active_generation, config_revision FROM pipeline_config WHERE singleton = 1"
        ).fetchone()
        if (
            row is None
            or int(row["active_generation"]) != snapshot.active_generation
            or str(row["config_revision"]) != snapshot.config_revision
        ):
            raise SnapshotChanged("active candidate generation or configuration changed")

    def set_state(self, key: str, value: str, connection: sqlite3.Connection | None = None) -> None:
        owned = connection is None
        db = connection or self.connect()
        try:
            db.execute(
                """
                INSERT INTO worker_state(key, value) VALUES (?, ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value
                """,
                (key, value),
            )
        finally:
            if owned:
                db.close()

    def get_state(self, key: str, default: str = "") -> str:
        with self.connection() as db:
            row = db.execute("SELECT value FROM worker_state WHERE key = ?", (key,)).fetchone()
            return default if row is None else str(row[0])

    def record_error(self, phase: str, error: BaseException) -> None:
        try:
            with self.connection() as db, self.transaction(db):
                now = str(int(time.time()))
                self.set_state("latest_error", _safe_error(error), db)
                self.set_state("latest_error_phase", phase, db)
                self.set_state("latest_error_at", now, db)
        except (sqlite3.Error, StoreError, ConfigurationError):
            pass

    def pending_count(self, connection: sqlite3.Connection, snapshot: PipelineSnapshot) -> int:
        row = connection.execute(
            """
            SELECT COUNT(*)
              FROM candidate_members AS cm
              JOIN article_inputs AS ai
                ON ai.entry_id = cm.entry_id AND ai.source_hash = cm.source_hash
              LEFT JOIN embeddings AS e ON e.entry_id = cm.entry_id
             WHERE cm.generation = ?
               AND (e.entry_id IS NULL OR e.source_hash != cm.source_hash
                    OR e.embedding_fingerprint != ?)
            """,
            (snapshot.active_generation, snapshot.config.embedding_fingerprint),
        ).fetchone()
        return int(row[0])

    def pending_inputs(
        self, connection: sqlite3.Connection, snapshot: PipelineSnapshot, limit: int
    ) -> list[PendingInput]:
        rows = connection.execute(
            """
            SELECT cm.entry_id, cm.source_hash, ai.embedding_text
              FROM candidate_members AS cm
              JOIN article_inputs AS ai
                ON ai.entry_id = cm.entry_id AND ai.source_hash = cm.source_hash
              LEFT JOIN embeddings AS e ON e.entry_id = cm.entry_id
             WHERE cm.generation = ?
               AND (e.entry_id IS NULL OR e.source_hash != cm.source_hash
                    OR e.embedding_fingerprint != ?)
             ORDER BY cm.entry_id
             LIMIT ?
            """,
            (
                snapshot.active_generation,
                snapshot.config.embedding_fingerprint,
                limit,
            ),
        ).fetchall()
        return [PendingInput(str(row[0]), str(row[1]), str(row[2])) for row in rows]

    def store_embeddings(
        self,
        snapshot: PipelineSnapshot,
        inputs: Sequence[PendingInput],
        vectors: NDArray[np.float32],
    ) -> None:
        matrix = np.asarray(vectors, dtype="<f4")
        if matrix.ndim != 2 or matrix.shape[0] != len(inputs) or matrix.shape[1] < 1:
            raise ValueError("encoder returned an invalid embedding matrix")
        if not np.isfinite(matrix).all():
            raise ValueError("encoder returned a non-finite embedding")
        now = int(time.time())
        with self.connection() as db, self.transaction(db):
            self.assert_snapshot(db, snapshot)
            for item, vector in zip(inputs, matrix, strict=True):
                db.execute(
                    """
                    INSERT INTO embeddings(
                        entry_id, source_hash, embedding_fingerprint, model_id,
                        dimensions, embedding, embedded_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(entry_id) DO UPDATE SET
                        source_hash = excluded.source_hash,
                        embedding_fingerprint = excluded.embedding_fingerprint,
                        model_id = excluded.model_id,
                        dimensions = excluded.dimensions,
                        embedding = excluded.embedding,
                        embedded_at = excluded.embedded_at
                    """,
                    (
                        item.entry_id,
                        item.source_hash,
                        snapshot.config.embedding_fingerprint,
                        snapshot.config.embedding_model,
                        int(vector.shape[0]),
                        sqlite3.Binary(vector.astype("<f4", copy=False).tobytes(order="C")),
                        now,
                    ),
                )

    def load_grouping_inputs(
        self, connection: sqlite3.Connection, snapshot: PipelineSnapshot
    ) -> list[GroupingInput]:
        candidate_count = int(
            connection.execute(
                "SELECT COUNT(*) FROM candidate_members WHERE generation = ?",
                (snapshot.active_generation,),
            ).fetchone()[0]
        )
        rows = connection.execute(
            """
            SELECT cm.entry_id, ai.received_at, cm.normalized_title,
                   e.dimensions, e.embedding
              FROM candidate_members AS cm
              JOIN article_inputs AS ai
                ON ai.entry_id = cm.entry_id AND ai.source_hash = cm.source_hash
              JOIN embeddings AS e
                ON e.entry_id = cm.entry_id
               AND e.source_hash = cm.source_hash
               AND e.embedding_fingerprint = ?
             WHERE cm.generation = ?
             ORDER BY ai.received_at, cm.entry_id
            """,
            (snapshot.config.embedding_fingerprint, snapshot.active_generation),
        ).fetchall()
        if len(rows) != candidate_count:
            raise IncompleteEmbeddings(
                f"{candidate_count - len(rows)} active candidates still need embeddings"
            )
        dimensions: int | None = None
        output: list[GroupingInput] = []
        for row in rows:
            row_dimensions = int(row[3])
            blob = bytes(row[4])
            if row_dimensions < 1 or len(blob) != row_dimensions * 4:
                raise StoreError(f"invalid embedding BLOB for entry {row[0]}")
            if dimensions is None:
                dimensions = row_dimensions
            elif dimensions != row_dimensions:
                raise StoreError("active embeddings have inconsistent dimensions")
            vector = np.frombuffer(blob, dtype="<f4").copy()
            if not np.isfinite(vector).all():
                raise StoreError(f"non-finite embedding for entry {row[0]}")
            output.append(GroupingInput(str(row[0]), int(row[1]), str(row[2]), vector))
        return output

    def publish_groups(
        self, snapshot: PipelineSnapshot, groups: Sequence[PublishedGroup]
    ) -> None:
        now = int(time.time())
        with self.connection() as db, self.transaction(db):
            self.assert_snapshot(db, snapshot)
            db.execute("DELETE FROM group_members")
            db.execute("DELETE FROM groups")
            for group in groups:
                db.execute(
                    """
                    INSERT INTO groups(
                        group_id, representative_entry_id, selection_generation,
                        grouping_fingerprint, generated_at
                    ) VALUES (?, ?, ?, ?, ?)
                    """,
                    (
                        group.group_id,
                        group.representative_entry_id,
                        snapshot.active_generation,
                        snapshot.config.grouping_fingerprint,
                        now,
                    ),
                )
                db.executemany(
                    "INSERT INTO group_members(group_id, entry_id) VALUES (?, ?)",
                    [(group.group_id, entry_id) for entry_id in group.members],
                )
            self.set_state("last_published_generation", str(snapshot.active_generation), db)
            self.set_state("last_successful_group", str(now), db)
            self.set_state("current_group_count", str(len(groups)), db)
            self.set_state("current_grouping_fingerprint", snapshot.config.grouping_fingerprint, db)
            self.set_state("latest_error", "", db)

    def cleanup_orphan_embeddings(self) -> int:
        with self.connection() as db, self.transaction(db):
            cursor = db.execute(
                """
                DELETE FROM embeddings
                 WHERE NOT EXISTS (
                    SELECT 1 FROM article_inputs AS ai
                     WHERE ai.entry_id = embeddings.entry_id
                       AND ai.source_hash = embeddings.source_hash
                 )
                """
            )
            removed = max(cursor.rowcount, 0)
            self.set_state("last_cleanup", str(int(time.time())), db)
            return removed

    def rebuild_worker_state(self) -> None:
        """Delete worker-owned data only; producer generations remain intact."""
        with self.connection() as db, self.transaction(db):
            db.execute("DELETE FROM group_members")
            db.execute("DELETE FROM groups")
            db.execute("DELETE FROM embeddings")
            db.execute("DELETE FROM worker_state")

    def state_dict(self) -> dict[str, str]:
        with self.connection() as db:
            rows = db.execute("SELECT key, value FROM worker_state")
            return {str(row[0]): str(row[1]) for row in rows}
