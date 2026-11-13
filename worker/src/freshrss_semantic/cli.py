"""Command-line orchestration for separate embedding and grouping processes."""

from __future__ import annotations

import argparse
import fcntl
import logging
import os
import sqlite3
import subprocess
import sys
import time
from collections.abc import Iterator, Sequence
from contextlib import contextmanager, suppress
from pathlib import Path

import psycopg

from .config import ConfigurationError
from .embeddings import embed
from .grouping import group
from .semantic_store import (
    WORKER_LOCK,
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


def _run_due(store: SemanticStore, now: int) -> bool:
    snapshot = store.load_snapshot()
    if not snapshot.config.enabled or snapshot.producer_lease_until <= now:
        return False

    try:
        published_generation = int(store.get_state("last_published_generation", "0"))
    except ValueError:
        published_generation = 0
    if published_generation != snapshot.active_generation:
        return True
    if store.get_state("current_grouping_fingerprint") != snapshot.config.grouping_fingerprint:
        return True
    with store.connection() as db:
        return store.pending_count(db, snapshot) > 0


def _child(command: str, database: str) -> None:
    environment = os.environ.copy()
    environment[CHILD_PROCESS_ENV] = "1"
    # Through the environment, not argv: a URL may carry a password.
    environment.pop(DATABASE_URL_ENV, None)
    environment.pop(DATABASE_PATH_ENV, None)
    environment[DATABASE_URL_ENV if is_postgres(database) else DATABASE_PATH_ENV] = database
    completed = subprocess.run(
        [sys.executable, "-m", "freshrss_semantic.cli", command],
        check=False,
        env=environment,
    )
    if completed.returncode != 0:
        detail = SemanticStore(database).get_state(
            "latest_error", f"{command} phase exited with status {completed.returncode}"
        )
        raise RuntimeError(f"{command} phase exited with status {completed.returncode}: {detail}")


def run(store: SemanticStore) -> bool:
    """Run embedding and grouping in separate processes when logically due."""
    now = int(time.time())
    with worker_lock(store.database):
        if not _run_due(store, now):
            return False
        store.set_state("last_attempted_run", str(now))
        try:
            last_cleanup = int(store.get_state("last_cleanup", "0"))
        except ValueError:
            last_cleanup = 0
        if now - last_cleanup >= 24 * 60 * 60:
            _child("cleanup", str(store.database))
        _child("embed", str(store.database))
        _child("group", str(store.database))
        store.set_state("last_successful_run", str(int(time.time())))
        store.set_state("latest_error", "")
        return True


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="freshrss-semantic")
    parser.add_argument(
        "--database",
        default=(
            os.environ.get(DATABASE_URL_ENV)
            or os.environ.get(DATABASE_PATH_ENV)
            or DEFAULT_DATABASE
        ),
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
    store = SemanticStore(args.database)
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
