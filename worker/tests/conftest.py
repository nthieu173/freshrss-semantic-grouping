from __future__ import annotations

import json
import sqlite3
import time
from collections.abc import Callable
from pathlib import Path

import pytest

from freshrss_semantic.config import WorkerConfig
from freshrss_semantic.semantic_store import SemanticStore


def worker_config(**overrides: object) -> dict[str, object]:
    config: dict[str, object] = {
        "enabled": True,
        "embedding_model": "test/model",
        "similarity_threshold": 0.9,
        "window_hours": 72,
        "candidate_export_interval_minutes": 30,
        "worker_interval_minutes": 60,
        "minimum_group_size": 1,
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


@pytest.fixture
def database(tmp_path: Path) -> Path:
    path = tmp_path / "semantic.sqlite"
    schema = Path(__file__).parents[2] / "fixtures" / "semantic-schema-v1.sql"
    with sqlite3.connect(path) as db:
        db.executescript(schema.read_text())
    return path


@pytest.fixture
def populate(database: Path) -> Callable[..., SemanticStore]:
    def create(*, config: dict[str, object] | None = None, entries: int = 3) -> SemanticStore:
        raw = worker_config() if config is None else config
        now = int(time.time())
        with sqlite3.connect(database) as db:
            db.execute(
                "INSERT INTO candidate_generations VALUES (1, 'query-v1', ?, ?, ?)",
                (now, now, entries),
            )
            for index in range(entries):
                entry_id = str((now + index) * 1_000_000)
                source_hash = f"source-{index}"
                db.execute(
                    "INSERT INTO article_inputs VALUES (?, ?, ?, ?, ?, ?)",
                    (entry_id, "1", now + index, f"entry {index}", source_hash, now),
                )
                db.execute(
                    "INSERT INTO candidate_members VALUES (1, ?, ?)",
                    (entry_id, source_hash),
                )
            db.execute(
                "INSERT INTO pipeline_config VALUES (1, 1, 'revision-v1', 1, ?, ?, ?)",
                (now + 7200, json.dumps(raw, sort_keys=True), now),
            )
        # Exercise config validation here so fixture failures remain obvious.
        WorkerConfig.from_mapping(raw)
        return SemanticStore(database)

    return create
