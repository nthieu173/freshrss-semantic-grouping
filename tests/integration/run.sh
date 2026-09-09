#!/bin/sh
set -eu

runtime=${CONTAINER_RUNTIME:-docker}
fresh_image=${FRESHRSS_IMAGE:-docker.io/freshrss/freshrss:1.29.1-alpine}
worker_image=${WORKER_IMAGE:-freshrss-semantic-grouping:integration}
container_name="freshrss-semantic-integration-$$"
integration_dir=$(mktemp -d "${TMPDIR:-/tmp}/freshrss-semantic-integration.XXXXXX")

cleanup() {
	status=$?
	trap - EXIT INT TERM
	if [ "$status" -ne 0 ]; then
		"$runtime" logs "$container_name" 2>/dev/null || true
	fi
	"$runtime" rm --force "$container_name" >/dev/null 2>&1 || true
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

"$runtime" run --detach --name "$container_name" \
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
		--network none \
		--tmpfs /tmp:rw,size=32m \
		--memory 400m \
		--volume "$integration_dir/semantic-data:/semantic-data:rw,z" \
		"$worker_image" "$@"
}

fresh_test setup
fresh_test verify-exact-disabled
fresh_test verify-pipeline-disabled-exact
mode_and_group=$("$runtime" exec "$container_name" stat -c '%a:%g' /semantic-data/semantic.sqlite)
if [ "$mode_and_group" != '660:33' ]; then
	echo "semantic.sqlite has unexpected mode/group: $mode_and_group" >&2
	exit 1
fi

worker run
fresh_test verify-groups

fresh_test update-entry
worker embed
worker group
fresh_test verify-groups

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

echo 'FreshRSS/worker container integration passed'
