from __future__ import annotations

import json
import sqlite3

import pytest

from freshrss_semantic.cli import AlreadyRunning, _run_due, main, worker_lock


def test_worker_lock_is_non_overlapping(database) -> None:
    with worker_lock(database), pytest.raises(AlreadyRunning), worker_lock(database):
        pass


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


def test_direct_worker_phase_respects_the_shared_worker_lock(populate) -> None:
    store = populate(entries=1)
    with worker_lock(store.database):
        assert main(["--database", str(store.database), "embed"]) == 0
    with sqlite3.connect(store.database) as db:
        assert db.execute("SELECT COUNT(*) FROM embeddings").fetchone()[0] == 0
