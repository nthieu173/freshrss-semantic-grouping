#!/bin/sh
set -eu

runtime=${CONTAINER_RUNTIME:-docker}
fresh_image=${FRESHRSS_IMAGE:-docker.io/freshrss/freshrss:${FRESHRSS_VERSION:?Set FRESHRSS_VERSION or FRESHRSS_IMAGE, or run make test/integration}-alpine}
worker_image=${WORKER_IMAGE:-freshrss-semantic-grouping:integration}
postgres_image=${POSTGRES_IMAGE:-docker.io/library/postgres:18-alpine}
# sqlite (the default) or postgres: where the extension and worker share data.
backend=${SEMANTIC_BACKEND:-sqlite}
container_name="freshrss-semantic-integration-$$"
postgres_name="freshrss-semantic-integration-postgres-$$"
network_name="freshrss-semantic-integration-$$"
integration_dir=$(mktemp -d "${TMPDIR:-/tmp}/freshrss-semantic-integration.XXXXXX")

cleanup() {
	status=$?
	trap - EXIT INT TERM
	if [ "$status" -ne 0 ]; then
		"$runtime" logs "$container_name" 2>/dev/null || true
	fi
	"$runtime" rm --force "$container_name" "$postgres_name" >/dev/null 2>&1 || true
	"$runtime" network rm "$network_name" >/dev/null 2>&1 || true
	"$runtime" run --rm --user 0 \
		--volume "$integration_dir:/integration-cleanup:rw,z" \
		--entrypoint /bin/sh "$fresh_image" \
		-c 'chmod -R a+rwX /integration-cleanup' \
		>/dev/null 2>&1 || true
	rm -rf "$integration_dir"
	exit "$status"
}
trap cleanup EXIT INT TERM

mkdir -p "$integration_dir/data" "$integration_dir/extensions/xExtension-SemanticGrouping" "$integration_dir/semantic-data"
cp -a extension/xExtension-SemanticGrouping/. "$integration_dir/extensions/xExtension-SemanticGrouping/"
cp tests/integration/freshrss.php "$integration_dir/extensions/xExtension-SemanticGrouping/freshrss-integration.php"
chmod -R a+rX "$integration_dir/extensions"
chmod 0777 "$integration_dir/data" "$integration_dir/extensions"
chmod 0777 "$integration_dir/semantic-data"

"$runtime" build --tag "$worker_image" --file packaging/Containerfile .

# Perform ownership inside the container user namespace so this test works
# with both rootful Docker in CI and rootless Podman during development.
"$runtime" run --rm --user 0 \
	--volume "$integration_dir/semantic-data:/semantic-data:rw,z" \
	--entrypoint /bin/sh "$fresh_image" \
	-c 'chown 33:33 /semantic-data && chmod 2770 /semantic-data'

case "$backend" in
sqlite)
	database_url=''
	fresh_network=bridge
	worker_network=none
	;;
postgres)
	# The worker reaches PostgreSQL over a private network instead of the
	# shared volume, which keeps only the test's own fixture file.
	"$runtime" network create "$network_name" >/dev/null
	postgres_password=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	"$runtime" run --detach --name "$postgres_name" \
		--network "$network_name" \
		--network-alias postgres \
		--env POSTGRES_USER=semantic \
		--env POSTGRES_DB=semantic \
		--env "POSTGRES_PASSWORD=$postgres_password" \
		"$postgres_image" >/dev/null
	attempt=0
	until "$runtime" exec "$postgres_name" pg_isready --quiet --host 127.0.0.1 --username semantic >/dev/null 2>&1; do
		attempt=$((attempt + 1))
		if [ "$attempt" -ge 60 ]; then
			echo 'PostgreSQL did not accept connections' >&2
			exit 1
		fi
		sleep 1
	done
	database_url="postgresql://semantic:$postgres_password@postgres:5432/semantic"
	fresh_network=$network_name
	worker_network=$network_name
	;;
*)
	echo "Unknown SEMANTIC_BACKEND: $backend" >&2
	exit 2
	;;
esac

"$runtime" run --detach --name "$container_name" \
	--network "$fresh_network" \
	--env "SEMANTIC_DATABASE_URL=$database_url" \
	--group-add 33 \
	--volume "$integration_dir/data:/var/www/FreshRSS/data:rw,z" \
	--volume "$integration_dir/extensions:/var/www/FreshRSS/extensions:ro,z" \
	--volume "$integration_dir/semantic-data:/semantic-data:rw,z" \
	--env 'FRESHRSS_INSTALL=--api-enabled --base-url http://localhost --default-user admin --language en' \
	--env 'FRESHRSS_USER=--email admin@example.invalid --language en --password integration-only-password --user admin --no-default-feeds' \
	"$fresh_image" >/dev/null

attempt=0
until "$runtime" exec --workdir /var/www/FreshRSS "$container_name" php -f cli/health.php >/dev/null 2>&1; do
	attempt=$((attempt + 1))
	if [ "$attempt" -ge 30 ]; then
		echo 'FreshRSS did not become healthy' >&2
		exit 1
	fi
	sleep 2
done

fresh_test() {
	"$runtime" exec --user apache --workdir /var/www/FreshRSS "$container_name" \
		php extensions/xExtension-SemanticGrouping/freshrss-integration.php "$1"
}

worker() {
	"$runtime" run --rm \
		--read-only \
		--network "$worker_network" \
		--env "SEMANTIC_DATABASE_URL=$database_url" \
		--tmpfs /tmp:rw,size=32m \
		--memory 400m \
		--volume "$integration_dir/semantic-data:/semantic-data:rw,z" \
		"$worker_image" "$@"
}

fresh_test setup
fresh_test verify-exact-disabled
fresh_test verify-pipeline-disabled-exact
if [ "$backend" = sqlite ]; then
	mode_and_group=$("$runtime" exec "$container_name" stat -c '%a:%g' /semantic-data/semantic.sqlite)
	if [ "$mode_and_group" != '660:33' ]; then
		echo "semantic.sqlite has unexpected mode/group: $mode_and_group" >&2
		exit 1
	fi
elif [ -e "$integration_dir/semantic-data/semantic.sqlite" ]; then
	echo 'The PostgreSQL configuration still created semantic.sqlite' >&2
	exit 1
fi

worker run
fresh_test verify-labels

fresh_test create-conflict
worker embed
worker group
fresh_test verify-conflict
fresh_test resolve-conflict

fresh_test update-entry
worker embed
worker group
fresh_test verify-labels

fresh_test change-query
worker embed
worker group
fresh_test verify-empty-groups

fresh_test disable-pipeline
worker run

if ! worker --database /semantic-data/missing.sqlite validate-config; then
	echo 'Worker did not accept an expected unconfigured state' >&2
	exit 1
fi
if [ -e "$integration_dir/semantic-data/missing.sqlite" ]; then
	echo 'Worker unexpectedly created a missing shared database' >&2
	exit 1
fi
"$runtime" exec --workdir /var/www/FreshRSS "$container_name" php -f cli/health.php

echo "FreshRSS/worker container integration passed ($backend)"
