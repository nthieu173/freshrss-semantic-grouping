#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repository_dir=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
release_version=${RELEASE_VERSION:-${TAG:-}}
release_version=${release_version#v}
if [ -z "$release_version" ]; then
	echo 'Set RELEASE_VERSION or TAG before building the extension artifact.' >&2
	exit 2
fi
case "$release_version" in
	*[!0-9A-Za-z.-]*)
		echo "Invalid release version: $release_version" >&2
		exit 2
		;;
esac

revision=${REVISION:-HEAD}
source_date_epoch=${SOURCE_DATE_EPOCH:-}
if [ -z "$source_date_epoch" ]; then
	source_date_epoch=$(git -C "$repository_dir" show -s --format=%ct "$revision")
fi
case "$source_date_epoch" in
	''|*[!0-9]*)
		echo "Invalid source date epoch: $source_date_epoch" >&2
		exit 2
		;;
esac

output_dir=${OUTPUT_DIR:-$repository_dir}
mkdir -p "$output_dir"
output_dir=$(CDPATH='' cd -- "$output_dir" && pwd)
artifact_name="xExtension-SemanticGrouping-$release_version.tar.gz"

tar --sort=name --mtime="@$source_date_epoch" --owner=0 --group=0 --numeric-owner \
	-czf "$output_dir/$artifact_name" \
	-C "$repository_dir/extension" xExtension-SemanticGrouping
(
	cd "$output_dir"
	sha256sum "$artifact_name" > "$artifact_name.sha256"
)

echo "Built $output_dir/$artifact_name"
