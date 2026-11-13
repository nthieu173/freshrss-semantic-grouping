#!/bin/sh
set -eu

# Runs the extension and worker suites against a disposable PostgreSQL, in
# addition to the SQLite runs of make test/fast. Each suite works in its own
# schema, so the database is shared without interference.

runtime=${CONTAINER_RUNTIME:-docker}
postgres_image=${POSTGRES_IMAGE:-docker.io/library/postgres:18-alpine}
container_name="freshrss-semantic-postgres-$$"
password=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')

cleanup() {
	status=$?
	trap - EXIT INT TERM
	if [ "$status" -ne 0 ]; then
		"$runtime" logs "$container_name" 2>/dev/null || true
	fi
	"$runtime" rm --force "$container_name" >/dev/null 2>&1 || true
	exit "$status"
}
trap cleanup EXIT INT TERM

"$runtime" run --detach --name "$container_name" \
	--env POSTGRES_USER=semantic \
	--env POSTGRES_DB=semantic \
	--env "POSTGRES_PASSWORD=$password" \
	--publish 127.0.0.1::5432 \
	"$postgres_image" >/dev/null

# TCP, not the socket: while the image initializes a new cluster, its
# temporary server listens on the socket alone.
attempt=0
until "$runtime" exec "$container_name" pg_isready --quiet --host 127.0.0.1 --username semantic >/dev/null 2>&1; do
	attempt=$((attempt + 1))
	if [ "$attempt" -ge 60 ]; then
		echo 'PostgreSQL did not accept connections' >&2
		exit 1
	fi
	sleep 1
done
port=$("$runtime" port "$container_name" 5432/tcp | head -n 1 | sed 's/.*://')

SEMANTIC_TEST_POSTGRES_URL="postgresql://semantic:$password@127.0.0.1:$port/semantic"
export SEMANTIC_TEST_POSTGRES_URL

# Both suites skip PostgreSQL silently without the URL; here a skip is a failure.
php extension/tests/run.php | tee /dev/stderr | grep -q 'extension PostgreSQL tests passed'
cd worker
uv sync --locked --extra dev --quiet
output=$(uv run pytest -rs) || {
	printf '%s\n' "$output"
	exit 1
}
printf '%s\n' "$output"
if printf '%s\n' "$output" | grep -q 'SEMANTIC_TEST_POSTGRES_URL is not set'; then
	echo 'Worker PostgreSQL tests were skipped' >&2
	exit 1
fi
echo 'PostgreSQL storage tests passed'
