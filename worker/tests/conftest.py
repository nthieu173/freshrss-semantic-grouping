from __future__ import annotations

import json
import os
import secrets
import time
from collections.abc import Callable, Iterator, Sequence
from pathlib import Path
from typing import Any
from urllib.parse import quote

import psycopg
import pytest

from freshrss_semantic.config import WorkerConfig
from freshrss_semantic.semantic_store import SemanticStore, open_connection

FIXTURES = Path(__file__).parents[2] / "fixtures"
# A disposable PostgreSQL database (make test/postgres starts one). Without it
# the PostgreSQL variants are skipped.
POSTGRES_URL = os.environ.get("SEMANTIC_TEST_POSTGRES_URL", "")


def worker_config(**overrides: object) -> dict[str, object]:
    config: dict[str, object] = {
        "enabled": True,
        "embedding_model": "test/model",
        "similarity_threshold": 0.9,
        "window_hours": 72,
        "candidate_export_interval_minutes": 30,
        "include_title": True,
        "include_content": False,
        "content_character_limit": 2000,
        "exact_title_enabled": True,
        "embedding_batch_size": 2,
        "normalization_version": 1,
        "query_fingerprint": "query-v1",
        "force_rebuild_token": "",
        "candidate_source": {"mode": "all_entries", "query_name": "All entries"},
    }
    config.update(overrides)
    return config


def raw(database: str | Path, sql: str, parameters: Sequence[object] = ()) -> list[Any]:
    """Run one statement outside the store, as the extension or an operator would."""
    connection = open_connection(database)
    try:
        cursor = connection.execute(sql, parameters)
        try:
            return [tuple(row) for row in cursor.fetchall()]
        except psycopg.ProgrammingError:
            return []
    finally:
        connection.close()


def set_schema_version(database: str | Path, version: int) -> None:
    if isinstance(database, str):
        raw(database, "UPDATE schema_version SET version = ?", (version,))
    else:
        raw(database, f"PRAGMA user_version = {int(version)}")


def get_schema_version(database: str | Path) -> int:
    if isinstance(database, str):
        return int(raw(database, "SELECT version FROM schema_version")[0][0])
    return int(raw(database, "PRAGMA user_version")[0][0])


@pytest.fixture
def empty_postgres() -> Iterator[str]:
    """A URL whose search path is a fresh, empty schema."""
    if not POSTGRES_URL:
        pytest.skip("SEMANTIC_TEST_POSTGRES_URL is not set")
    schema = f"semantic_py_{secrets.token_hex(6)}"
    with psycopg.connect(POSTGRES_URL, autocommit=True) as admin:
        admin.execute(f"CREATE SCHEMA {schema}".encode())
    separator = "&" if "?" in POSTGRES_URL else "?"
    try:
        yield f"{POSTGRES_URL}{separator}options={quote(f'-csearch_path={schema}')}"
    finally:
        with psycopg.connect(POSTGRES_URL, autocommit=True) as admin:
            admin.execute(f"DROP SCHEMA {schema} CASCADE".encode())


@pytest.fixture(params=["sqlite", "postgres"])
def database(request: pytest.FixtureRequest, tmp_path: Path) -> str | Path:
    """The v3 fixture schema in either backend."""
    if request.param == "postgres":
        url: str = request.getfixturevalue("empty_postgres")
        raw(url, (FIXTURES / "semantic-schema-v3.postgres.sql").read_text())
        return url
    path = tmp_path / "semantic.sqlite"
    connection = open_connection(path)
    try:
        connection.raw.executescript((FIXTURES / "semantic-schema-v3.sql").read_text())  # type: ignore[attr-defined]
    finally:
        connection.close()
    return path


@pytest.fixture
def populate(database: str | Path) -> Callable[..., SemanticStore]:
    def create(*, config: dict[str, object] | None = None, entries: int = 3) -> SemanticStore:
        raw_config = worker_config() if config is None else config
        now = int(time.time())
        raw(
            database,
            "INSERT INTO candidate_generations VALUES (1, 'query-v1', ?, ?, ?)",
            (now, now, entries),
        )
        for index in range(entries):
            entry_id = str((now + index) * 1_000_000)
            source_hash = f"source-{index}"
            raw(
                database,
                "INSERT INTO article_inputs VALUES (?, ?, ?, ?, ?, ?)",
                (entry_id, "1", now + index, f"entry {index}", source_hash, now),
            )
            raw(
                database,
                "INSERT INTO candidate_members VALUES (1, ?, ?, ?)",
                (entry_id, source_hash, f"entry {index}"),
            )
        raw(
            database,
            "INSERT INTO pipeline_config VALUES (1, 3, 'revision-v1', 1, ?, ?, ?)",
            (now + 7200, json.dumps(raw_config, sort_keys=True), now),
        )
        # Exercise config validation here so fixture failures remain obvious.
        WorkerConfig.from_mapping(raw_config)
        return SemanticStore(database)

    return create
