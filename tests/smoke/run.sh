#!/bin/sh
set -eu

runtime=${CONTAINER_RUNTIME:-docker}
worker_image=${WORKER_IMAGE:-freshrss-semantic-grouping:check}
worker_platform=${WORKER_PLATFORM:-linux/arm64}
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repository_dir=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
smoke_dir=$(mktemp -d "${TMPDIR:-/tmp}/freshrss-semantic-smoke.XXXXXX")

cleanup() {
	status=$?
	trap - EXIT INT TERM
	rm -rf "$smoke_dir"
	exit "$status"
}
trap cleanup EXIT INT TERM

chmod 0777 "$smoke_dir"
SMOKE_DATABASE="$smoke_dir/semantic.sqlite" \
	SMOKE_SCHEMA="$repository_dir/fixtures/semantic-schema-v3.sql" \
	python3 - <<'PY'
import json
import os
import sqlite3
import time
from pathlib import Path

database = sqlite3.connect(os.environ["SMOKE_DATABASE"])
database.executescript(Path(os.environ["SMOKE_SCHEMA"]).read_text())
now = int(time.time())
config = {
    "enabled": True,
    "embedding_model": "minishlab/potion-base-8M",
    "similarity_threshold": 0.25,
    "window_hours": 72,
    "include_title": True,
    "include_content": False,
    "content_character_limit": 2000,
    "embedding_batch_size": 128,
    "normalization_version": 1,
    "query_fingerprint": "smoke",
    "force_rebuild_token": "",
}
database.execute(
    "INSERT INTO candidate_generations VALUES (1, ?, ?, ?, ?)",
    ("smoke", now, now, 2),
)
inputs = [
    (
        "1000001",
        "1",
        now - 2,
        "Semantic city council approves climate plan",
        "source-1",
        now,
    ),
    (
        "1000002",
        "1",
        now - 1,
        "Semantic climate plan approved by city council",
        "source-2",
        now,
    ),
]
database.executemany("INSERT INTO article_inputs VALUES (?, ?, ?, ?, ?, ?)", inputs)
members = [
    (1, inputs[0][0], inputs[0][4], "semantic city council approves climate plan"),
    (1, inputs[1][0], inputs[1][4], "semantic climate plan approved by city council"),
]
database.executemany("INSERT INTO candidate_members VALUES (?, ?, ?, ?)", members)
database.execute(
    "INSERT INTO pipeline_config VALUES (1, 3, ?, ?, ?, ?, ?)",
    ("smoke", 1, now + 5400, json.dumps(config), now),
)
database.commit()
database.close()
PY
chmod 0666 "$smoke_dir/semantic.sqlite"

"$runtime" run --rm --platform "$worker_platform" --read-only \
	--tmpfs /tmp:rw,size=32m \
	--network none \
	--memory 400m \
	--volume "$smoke_dir:/semantic-data:rw,z" \
	"$worker_image" run

SMOKE_DATABASE="$smoke_dir/semantic.sqlite" python3 - <<'PY'
import os
import sqlite3

database = sqlite3.connect(os.environ["SMOKE_DATABASE"])
assert database.execute("SELECT COUNT(*) FROM embeddings").fetchone()[0] == 2
assert database.execute(
    "SELECT value FROM worker_state WHERE key = 'last_published_generation'"
).fetchone()[0] == "1"
database.close()
PY

echo 'Worker image smoke test passed'
