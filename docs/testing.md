# Testing and release design

## Fast checks

`make test/fast` is the local and pull-request baseline. It runs:

- PHP contract tests for normalization, duplicate filtering, configuration,
  query resolution, generation publication, native label reconciliation,
  ownership conflicts, retirement, migration, and error handling;
- PHP syntax checks over every extension PHP and PHTML file;
- Python unit tests for configuration fingerprints, stale-vector discovery,
  float32 serialization, snapshot races, SemHash conversion, atomic publication,
  cleanup, locking, intervals, leases, and CLI behavior;
- Ruff and strict mypy checks.

Worker unit tests use deterministic fake encoders for most cases and separately
exercise SemHash's real precomputed-embedding USearch API. The dependency set is
also checked to ensure sqlite-vec is not required.

`make test/compatibility` clones the pinned FreshRSS release into a temporary
directory and checks the extension entry point against that source tree. This is
the same compatibility command used by CI. `make test` aliases `make test/all`
and runs every `test/*` suite, including the image smoke test; its smoke image
must already exist.

## Worker image smoke test

`make test/smoke` runs an already-built worker image against a temporary schema-v3
database with networking disabled, a read-only root, a 32 MiB `/tmp`, and a
400 MiB memory limit. It defaults to the CI image and platform; local runs can
override them together with the container runtime:

```sh
CONTAINER_RUNTIME=podman WORKER_IMAGE=freshrss-semantic:dev \
  WORKER_PLATFORM=linux/arm64 make test/smoke
```

## Clean-room integration

`make test/integration` creates a clean pinned FreshRSS 1.30.0 environment, installs
and enables the extension, initializes real FreshRSS SQLite data, and creates
feeds, entries, a label, and saved queries. It exercises native feed, category,
title, content, tag, label, unread/favourite, date, nested `OR`, and negation
selection plus the independent rolling cutoff.

The integration scenario verifies:

- missing, empty, renamed, and ambiguous saved queries fail closed;
- exact-title rejection covers retained, staged, and same-process duplicates
  and remains independently configurable;
- an export activates only a complete native-query result;
- the worker sees only `/semantic-data`, embeds with the real bundled Model2Vec
  model, and groups through SemHash/USearch;
- native semantic labels render in the authenticated FreshRSS **My labels**
  sidebar and use the representative title;
- an article source change replaces only its stale embedding;
- a query edit retains old labels until an atomic replacement is ready;
- membership changes and group retirement update only extension-owned labels;
- a personal-label name conflict is reported without commandeering the label;
- disablement and lease expiry stop new worker processing;
- worker/database failures leave FreshRSS healthy.

The worker container is run with networking disabled, a read-only root, a 32 MiB
`/tmp`, only the semantic-data mount, and a 400 MiB memory limit. This validates
the deployed permission, offline-model, filesystem, and memory contracts rather
than only testing Python functions on the host.

## Continuous integration

Pull-request and main-branch checks have separate extension, worker, integration,
and ARM64 image jobs. The extension job checks compatibility against the pinned
FreshRSS source. The image job uses QEMU/buildx to build `linux/arm64`, then runs
a real offline embedding/grouping smoke test under the production filesystem and
memory constraints.

FreshRSS and its extension API are pinned because candidate export intentionally
uses application model/DAO classes whose stability is not guaranteed across
untested releases. Changing the pin requires the native-query and full
integration suites to pass first.

## Paired releases

The extension and worker share a release version. A `v*` tag must match both
components and their packaged version declarations before release proceeds.
`make ci/verify-release-version` performs the local consistency check; setting
`TAG=vX.Y.Z` also checks the intended release tag. `make ci/check` combines that
verification with `make test/fast`. `make ci` aliases `make ci/all` and runs every
test suite and every `ci/*` target, so it additionally requires the prebuilt
smoke image and release inputs such as `TAG` and `image-metadata.json`. Release
CI invokes the narrower targets in dependency order and also runs
`make test/integration`, then:

1. builds and pushes the ARM64 worker image with version and commit tags;
2. creates a deterministic extension tarball;
3. publishes its SHA-256 checksum;
4. creates release notes containing the supported FreshRSS version, database
   schema version, and OCI digest;
5. advances the `stable` image tag only after every prior step succeeds.

Infrastructure consumers pin the paired extension artifact/checksum and worker
image digest. Schema upgrades stop the worker, install the paired release, let
the extension migrate transactionally, and start the new worker afterward.

`make ci/extension-artifact` builds the same deterministic extension archive and
checksum used by release CI. It accepts `TAG` or `RELEASE_VERSION`, plus optional
`REVISION`, `SOURCE_DATE_EPOCH`, and `OUTPUT_DIR` overrides. `make ci/release-notes`
reads the database schema and OCI digest metadata instead of duplicating them in
the release workflow.
