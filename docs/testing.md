# Testing and release design

## Fast checks

`make check` is the local and pull-request baseline. It runs:

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

## Clean-room integration

`make integration` creates a clean pinned FreshRSS 1.29.1 environment, installs
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
`metadata.json` and `worker/pyproject.toml` before release proceeds. Release CI
reruns PHP, Python, and full integration checks, then:

1. builds and pushes the ARM64 worker image with version and commit tags;
2. creates a deterministic extension tarball;
3. publishes its SHA-256 checksum;
4. creates release notes containing the supported FreshRSS version, database
   schema version, and OCI digest;
5. advances the `stable` image tag only after every prior step succeeds.

Infrastructure consumers pin the paired extension artifact/checksum and worker
image digest. Schema upgrades stop the worker, install the paired release, let
the extension migrate transactionally, and start the new worker afterward.
