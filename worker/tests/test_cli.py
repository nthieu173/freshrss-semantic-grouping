from __future__ import annotations

import json
import logging
import os
from pathlib import Path

import pytest
from conftest import raw

from freshrss_semantic import cli
from freshrss_semantic.cli import AlreadyRunning, _run_due, main, resolve_database, worker_lock
from freshrss_semantic.config import ConfigurationError
from freshrss_semantic.semantic_store import SemanticStore, is_postgres


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
    cli._child("embed", SemanticStore(url))
    # An upper-case scheme is still PostgreSQL, passed on in libpq's spelling.
    cli._child("embed", SemanticStore(url.replace("postgresql", "POSTGRESQL")))
    for argv, env in calls:
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


def test_schema_without_a_published_configuration_is_an_unconfigured_success(
    database, caplog: pytest.LogCaptureFixture
) -> None:
    # migrate.php runs before every worker run, so this is the normal state
    # until FreshRSS's first export.
    for command in ("run", "embed", "group", "validate-config"):
        caplog.clear()
        with caplog.at_level(logging.INFO):
            assert main(["--database", str(database), command]) == 0
        assert "not configured" in caplog.text
        assert "ERROR" not in caplog.text
    assert SemanticStore(database).state_dict() == {}


def test_invalid_configuration_still_fails_and_is_recorded(populate) -> None:
    store = populate(entries=0)
    raw(store.database, "UPDATE pipeline_config SET config_json='{}'")
    assert main(["--database", str(store.database), "run"]) == 1
    state = store.state_dict()
    assert state["latest_error_phase"] == "run"
    assert state["latest_error"].startswith("ConfigurationError")


def test_database_url_matches_the_extension(monkeypatch, tmp_path: Path) -> None:
    monkeypatch.delenv("SEMANTIC_DATABASE_URL", raising=False)
    monkeypatch.setenv("SEMANTIC_DATABASE", str(tmp_path / "semantic.sqlite"))
    assert resolve_database(None) == str(tmp_path / "semantic.sqlite")

    # A blank URL is unset, as the extension trims it.
    monkeypatch.setenv("SEMANTIC_DATABASE_URL", "  \n")
    assert resolve_database(None) == str(tmp_path / "semantic.sqlite")

    for url in ("POSTGRESQL://u@h/db", " Postgres://u@h/db\n", "postgresql:///db"):
        monkeypatch.setenv("SEMANTIC_DATABASE_URL", url)
        resolved = resolve_database(None)
        assert resolved == url.strip()
        assert is_postgres(resolved)
        assert SemanticStore(resolved).postgres
        assert str(SemanticStore(resolved).database).startswith(("postgres://", "postgresql://"))

    # Any other non-blank value is never mistaken for a SQLite path.
    for url in ("mysql://u@h/db", "/semantic-data/semantic.sqlite"):
        monkeypatch.setenv("SEMANTIC_DATABASE_URL", url)
        with pytest.raises(ConfigurationError):
            resolve_database(None)
    with pytest.raises(ConfigurationError):
        resolve_database("MySQL://u@h/db")
    assert resolve_database("POSTGRESQL://u@h/db") == "POSTGRESQL://u@h/db"


def test_unusable_database_url_fails_loudly(
    monkeypatch, tmp_path: Path, caplog: pytest.LogCaptureFixture
) -> None:
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv("SEMANTIC_DATABASE_URL", "mysql://u@h/db")
    with caplog.at_level(logging.INFO):
        assert main(["run"]) != 0
    assert "SEMANTIC_DATABASE_URL must be a postgresql:// URL" in caplog.text
    assert "not configured" not in caplog.text
    assert list(tmp_path.iterdir()) == []


def test_upper_case_postgres_scheme_reaches_the_server(empty_postgres: str) -> None:
    store = SemanticStore(empty_postgres.replace("postgresql://", "POSTGRESQL://", 1))
    assert store.postgres
    assert not store.exists()


def _fake_child(returncode: int, effect=None):
    class Completed:
        pass

    def fake_run(_argv, *, check, env):
        if effect is not None:
            effect()
        completed = Completed()
        completed.returncode = returncode
        return completed

    return fake_run


def test_failed_phase_reports_only_its_own_error(populate, monkeypatch) -> None:
    store = populate(entries=0)
    store.record_error("embed", RuntimeError("stale failure from an earlier run"))
    raw(store.database, "UPDATE worker_state SET value='1' WHERE key='latest_error_at'")

    monkeypatch.setattr(cli.subprocess, "run", _fake_child(1))
    with pytest.raises(RuntimeError) as failure:
        cli._child("embed", store)
    assert "stale failure" not in str(failure.value)
    assert "exited with status 1: no error was recorded" in str(failure.value)

    # Another phase's error from this run is not this phase's either.
    monkeypatch.setattr(
        cli.subprocess,
        "run",
        _fake_child(1, lambda: store.record_error("cleanup", RuntimeError("cleanup broke"))),
    )
    with pytest.raises(RuntimeError, match="no error was recorded"):
        cli._child("embed", store)

    monkeypatch.setattr(
        cli.subprocess,
        "run",
        _fake_child(1, lambda: store.record_error("embed", RuntimeError("encoder broke"))),
    )
    with pytest.raises(RuntimeError, match="status 1: RuntimeError: encoder broke"):
        cli._child("embed", store)


def test_phase_killed_by_a_signal_names_the_signal(populate, monkeypatch) -> None:
    store = populate(entries=0)
    store.record_error("group", RuntimeError("stale failure"))
    monkeypatch.setattr(cli.subprocess, "run", _fake_child(-9))
    with pytest.raises(RuntimeError) as failure:
        cli._child("group", store)
    assert str(failure.value) == "group phase was killed by SIGKILL"


def test_run_checks_due_work_on_one_connection(populate, monkeypatch) -> None:
    store = populate(entries=1)
    connections = 0
    real_connect = SemanticStore.connect

    def counting_connect(self):
        nonlocal connections
        connections += 1
        return real_connect(self)

    phases: list[str] = []
    monkeypatch.setattr(SemanticStore, "connect", counting_connect)
    monkeypatch.setattr(cli, "_child", lambda command, _store: phases.append(command))
    assert cli.run(store)
    assert phases == ["cleanup", "embed", "group"]
    # The due check and run bookkeeping, then the final success record.
    assert connections == 2
    assert store.get_state("latest_error", "missing") == ""
