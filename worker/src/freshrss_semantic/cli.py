"""Command-line orchestration for separate embedding and grouping processes."""

from __future__ import annotations

import argparse
import fcntl
import logging
import os
import re
import signal
import sqlite3
import subprocess
import sys
import time
from collections.abc import Iterator, Sequence
from contextlib import contextmanager, suppress
from pathlib import Path

import psycopg

from .config import ConfigurationError, ConfigurationMissing
from .embeddings import embed
from .grouping import group
from .semantic_store import (
    WORKER_LOCK,
    DatabaseMissing,
    SemanticStore,
    StoreError,
    is_postgres,
    lock_key,
    open_connection,
)

DEFAULT_DATABASE = "/semantic-data/semantic.sqlite"
DATABASE_URL_ENV = "SEMANTIC_DATABASE_URL"
DATABASE_PATH_ENV = "SEMANTIC_DATABASE"
CHILD_PROCESS_ENV = "FRESHRSS_SEMANTIC_CHILD_PROCESS"
LOGGER = logging.getLogger(__name__)
_URL = re.compile(r"[A-Za-z][A-Za-z0-9+.-]*://")


class AlreadyRunning(RuntimeError):
    pass


@contextmanager
def worker_lock(database: str | Path) -> Iterator[None]:
    """Take the worker-only advisory lock without waiting."""
    if is_postgres(database):
        # A session lock on its own connection, released if this process dies.
        connection = open_connection(database)
        try:
            row = connection.execute(
                "SELECT pg_try_advisory_lock(?)", (lock_key(WORKER_LOCK),)
            ).fetchone()
            if not row[0]:
                raise AlreadyRunning("another semantic worker run is active")
            yield
        finally:
            connection.close()
        return

    lock_path = Path(database).parent / f".{WORKER_LOCK}.lock"
    descriptor = os.open(lock_path, os.O_CREAT | os.O_RDWR, 0o660)
    try:
        # FreshRSS may have created the shared lock first under another UID.
        # Opening it read/write already proves the shared GID permissions are
        # sufficient; a non-owner chmod is expected to fail on Linux.
        with suppress(PermissionError):
            os.fchmod(descriptor, 0o660)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise AlreadyRunning("another semantic worker run is active") from error
        yield
    finally:
        os.close(descriptor)


def resolve_database(argument: str | None) -> str:
    """The database to use, matching the extension's reading of the URL.

    A non-blank ``SEMANTIC_DATABASE_URL`` always selects PostgreSQL, so any
    scheme other than ``postgres``/``postgresql`` (in any letter case) is an
    error rather than a SQLite path that would never exist.
    """
    if argument is None:
        url = os.environ.get(DATABASE_URL_ENV, "").strip()
        if url:
            if not is_postgres(url):
                raise ConfigurationError(f"{DATABASE_URL_ENV} must be a postgresql:// URL")
            return url
        argument = os.environ.get(DATABASE_PATH_ENV) or DEFAULT_DATABASE
    if is_postgres(argument.strip()):
        return argument.strip()
    if _URL.match(argument.strip()):
        raise ConfigurationError("the database must be a SQLite path or a postgresql:// URL")
    return argument


def _run_due(store: SemanticStore, now: int) -> bool:
    with store.session():
        snapshot = store.load_snapshot()
        if not snapshot.config.enabled or snapshot.producer_lease_until <= now:
            return False

        state = store.state_dict()
        try:
            published_generation = int(state.get("last_published_generation", "0"))
        except ValueError:
            published_generation = 0
        if published_generation != snapshot.active_generation:
            return True
        if state.get("current_grouping_fingerprint", "") != snapshot.config.grouping_fingerprint:
            return True
        with store.connection() as db:
            return store.pending_count(db, snapshot) > 0


def _phase_error(store: SemanticStore, command: str, started: int) -> str:
    """The error this phase run recorded, ignoring any left by an earlier run."""
    try:
        state = store.state_dict()
        recorded_at = int(state.get("latest_error_at", "0"))
    except (sqlite3.Error, psycopg.Error, StoreError, ValueError):
        return "no error was recorded"
    if state.get("latest_error_phase") != command or recorded_at < started:
        return "no error was recorded"
    return state.get("latest_error", "") or "no error was recorded"


def _child(command: str, store: SemanticStore) -> None:
    database = str(store.database)
    environment = os.environ.copy()
    environment[CHILD_PROCESS_ENV] = "1"
    # Through the environment, not argv: a URL may carry a password.
    environment.pop(DATABASE_URL_ENV, None)
    environment.pop(DATABASE_PATH_ENV, None)
    environment[DATABASE_URL_ENV if is_postgres(database) else DATABASE_PATH_ENV] = database
    started = int(time.time())
    completed = subprocess.run(
        [sys.executable, "-m", "freshrss_semantic.cli", command],
        check=False,
        env=environment,
    )
    status = completed.returncode
    if status == 0:
        return
    if status < 0:
        try:
            name = signal.Signals(-status).name
        except ValueError:
            name = f"signal {-status}"
        # A killed child (for example by the out-of-memory killer) records nothing.
        raise RuntimeError(f"{command} phase was killed by {name}")
    raise RuntimeError(
        f"{command} phase exited with status {status}: {_phase_error(store, command, started)}"
    )


def run(store: SemanticStore) -> bool:
    """Run embedding and grouping in separate processes when logically due."""
    now = int(time.time())
    with worker_lock(store.database):
        # Closed before the phases start, so no connection idles through them.
        with store.session():
            if not _run_due(store, now):
                return False
            store.set_state("last_attempted_run", str(now))
            try:
                last_cleanup = int(store.get_state("last_cleanup", "0"))
            except ValueError:
                last_cleanup = 0
        if now - last_cleanup >= 24 * 60 * 60:
            _child("cleanup", store)
        _child("embed", store)
        _child("group", store)
        with store.connection() as db, store.transaction(db):
            store.set_states({"last_successful_run": str(int(time.time())), "latest_error": ""}, db)
        return True


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="freshrss-semantic")
    parser.add_argument(
        "--database",
        default=None,
        help=(
            "SQLite database path, or a postgresql:// URL (defaults to"
            f" ${DATABASE_URL_ENV}, then ${DATABASE_PATH_ENV}, then the fixed container path)"
        ),
    )
    parser.add_argument("--verbose", action="store_true")
    actions = parser.add_subparsers(dest="command", required=True)
    actions.add_parser("validate-config")
    for command in ("embed", "group", "cleanup", "run", "rebuild"):
        actions.add_parser(command)
    return parser


def _execute(command: str, store: SemanticStore) -> None:
    if command == "validate-config":
        store.load_snapshot()
    elif command == "embed":
        embed(store)
    elif command == "group":
        group(store)
    elif command == "cleanup":
        removed = store.cleanup_orphan_embeddings()
        LOGGER.info("removed orphan embeddings=%d", removed)
    elif command == "run":
        run(store)
    elif command == "rebuild":
        with worker_lock(store.database):
            store.rebuild_worker_state()


def main(argv: Sequence[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    try:
        database = resolve_database(args.database)
    except ConfigurationError as error:
        LOGGER.error("%s", error)
        return 2
    store = SemanticStore(database)
    try:
        if not store.exists():
            LOGGER.info("semantic worker is not configured: semantic database does not exist")
            return 0
        if (
            args.command in {"embed", "group", "cleanup"}
            and os.environ.get(CHILD_PROCESS_ENV) != "1"
        ):
            with worker_lock(store.database):
                _execute(args.command, store)
        else:
            _execute(args.command, store)
        return 0
    except AlreadyRunning as error:
        LOGGER.info("%s", error)
        return 0
    except (DatabaseMissing, ConfigurationMissing) as error:
        # The extension creates the schema first and publishes a configuration
        # at its next maintenance pass; until then there is nothing to do.
        LOGGER.info("semantic worker is not configured: %s", error)
        return 0
    except (
        ConfigurationError,
        ImportError,
        OSError,
        RuntimeError,
        sqlite3.Error,
        psycopg.Error,
        StoreError,
        ValueError,
    ) as error:
        store.record_error(args.command, error)
        LOGGER.error("%s", error)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
