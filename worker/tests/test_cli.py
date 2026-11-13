from __future__ import annotations

import json
import logging
import os
from pathlib import Path

import pytest
from conftest import raw

from freshrss_semantic import cli
from freshrss_semantic.cli import AlreadyRunning, _run_due, main, worker_lock


def test_missing_database_is_an_unconfigured_success(
    tmp_path: Path, caplog: pytest.LogCaptureFixture
) -> None:
    database = tmp_path / "semantic.sqlite"

    with caplog.at_level(logging.INFO):
        assert main(["--database", str(database), "run"]) == 0

    assert "not configured" in caplog.text
    assert not database.exists()


def test_empty_postgres_schema_is_an_unconfigured_success(
    empty_postgres: str, caplog: pytest.LogCaptureFixture
) -> None:
    with caplog.at_level(logging.INFO):
        assert main(["--database", empty_postgres, "run"]) == 0

    assert "not configured" in caplog.text


def test_child_phases_receive_the_database_outside_argv(monkeypatch) -> None:
    calls: list[tuple[list[str], dict[str, str]]] = []

    class Completed:
        returncode = 0

    def fake_run(argv: list[str], *, check: bool, env: dict[str, str]) -> Completed:
        calls.append((argv, env))
        return Completed()

    url = "postgresql://semantic:secret@db/semantic"  # gitleaks:allow (test fixture)
    monkeypatch.setenv("SEMANTIC_DATABASE", "/stale/semantic.sqlite")
    monkeypatch.setattr(cli.subprocess, "run", fake_run)
    cli._child("embed", url)
    argv, env = calls[0]
    assert "secret" not in " ".join(argv)
    assert env["SEMANTIC_DATABASE_URL"] == url
    assert "SEMANTIC_DATABASE" not in env


def test_worker_lock_is_non_overlapping(database) -> None:
    with worker_lock(database), pytest.raises(AlreadyRunning), worker_lock(database):
        pass


def test_worker_lock_tolerates_lock_owned_by_the_freshrss_uid(database, monkeypatch) -> None:
    if isinstance(database, str):
        pytest.skip("PostgreSQL advisory locks have no lock file")
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
    raw(
        store.database,
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
    assert raw(store.database, "SELECT COUNT(*) FROM embeddings") == [(0,)]


def test_advisory_lock_keys_match_the_extension() -> None:
    # extension/tests/run.php checks the same value.
    assert cli.lock_key("semantic-worker") == 0xDF51F117F5E3DDE
