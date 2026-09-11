from __future__ import annotations

import json
import logging
import os
import sqlite3
from pathlib import Path

import pytest

from freshrss_semantic.cli import AlreadyRunning, _run_due, main, worker_lock


def test_missing_database_is_an_unconfigured_success(
    tmp_path: Path, caplog: pytest.LogCaptureFixture
) -> None:
    database = tmp_path / "semantic.sqlite"

    with caplog.at_level(logging.INFO):
        assert main(["--database", str(database), "run"]) == 0

    assert "not configured" in caplog.text
    assert not database.exists()


def test_worker_lock_is_non_overlapping(database) -> None:
    with worker_lock(database), pytest.raises(AlreadyRunning), worker_lock(database):
        pass


def test_worker_lock_tolerates_lock_owned_by_the_freshrss_uid(database, monkeypatch) -> None:
    real_fchmod = os.fchmod

    def denied(_descriptor: int, _mode: int) -> None:
        raise PermissionError("owned by FreshRSS")

    monkeypatch.setattr(os, "fchmod", denied)
    with worker_lock(database):
        pass
    monkeypatch.setattr(os, "fchmod", real_fchmod)


def test_disabled_and_expired_pipeline_is_not_due(populate) -> None:
    conftest = __import__("conftest")
    store = populate(config=conftest.worker_config(enabled=False), entries=0)
    assert not _run_due(store, 1)
    with sqlite3.connect(store.database) as db:
        db.execute(
            "UPDATE pipeline_config SET producer_lease_until=1, config_json=?",
            (json.dumps(conftest.worker_config(enabled=True)),),
        )
    assert not _run_due(store, 1)


def test_worker_is_due_only_for_unpublished_or_stale_work(populate) -> None:
    store = populate(entries=0)
    snapshot = store.load_snapshot()
    assert _run_due(store, 1)

    store.set_state("last_published_generation", str(snapshot.active_generation))
    store.set_state("current_grouping_fingerprint", snapshot.config.grouping_fingerprint)
    assert not _run_due(store, 1)

    store.set_state("current_grouping_fingerprint", "stale")
    assert _run_due(store, 1)


def test_worker_is_due_when_an_active_embedding_is_missing(populate) -> None:
    store = populate(entries=1)
    snapshot = store.load_snapshot()
    store.set_state("last_published_generation", str(snapshot.active_generation))
    store.set_state("current_grouping_fingerprint", snapshot.config.grouping_fingerprint)
    assert _run_due(store, 1)


def test_direct_worker_phase_respects_the_shared_worker_lock(populate) -> None:
    store = populate(entries=1)
    with worker_lock(store.database):
        assert main(["--database", str(store.database), "embed"]) == 0
    with sqlite3.connect(store.database) as db:
        assert db.execute("SELECT COUNT(*) FROM embeddings").fetchone()[0] == 0
