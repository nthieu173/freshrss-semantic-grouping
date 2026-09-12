#!/bin/sh
set -eu

freshrss_version=${FRESHRSS_VERSION:-1.30.0}
freshrss_repository=${FRESHRSS_REPOSITORY:-https://github.com/FreshRSS/FreshRSS.git}
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repository_dir=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
checkout_dir=$(mktemp -d "${TMPDIR:-/tmp}/freshrss-compatibility.XXXXXX")

cleanup() {
	status=$?
	trap - EXIT INT TERM
	rm -rf "$checkout_dir"
	exit "$status"
}
trap cleanup EXIT INT TERM

git -c advice.detachedHead=false clone --depth 1 --branch "$freshrss_version" \
	"$freshrss_repository" "$checkout_dir/FreshRSS"
cp -a "$repository_dir/extension/xExtension-SemanticGrouping" "$checkout_dir/FreshRSS/extensions/"
php -l "$checkout_dir/FreshRSS/extensions/xExtension-SemanticGrouping/extension.php"

echo "FreshRSS $freshrss_version API compatibility passed"
