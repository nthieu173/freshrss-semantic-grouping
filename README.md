# FreshRSS Semantic Grouping

FreshRSS Semantic Grouping is a versioned pair: a FreshRSS user extension and
an offline Python worker. The extension rejects exact normalized-title
duplicates, exports one complete generation selected by a native FreshRSS saved
query, and reconciles the last published semantic groups into native FreshRSS
labels. The worker sees only the
shared semantic database; it never mounts or queries FreshRSS application data.

The first release targets FreshRSS **1.29.1**, Python **3.14**, and
`linux/arm64`. The shared database schema version is **2** and the extension
configuration schema version is **3**.

## Data flow

```text
FreshRSS entries + saved query
          |
          | complete generation, canonical text
          v
 /semantic-data/semantic.sqlite
          ^                 |
          | labels/status   | active candidates
          |                 v
 FreshRSS My labels  Model2Vec -> SemHash/USearch
```

Only exact-title duplicates are rejected during ingestion. Semantic grouping
adds and removes extension-owned native label assignments; it does not mark or
delete FreshRSS entries and never changes personal labels.

## Repository contents

- `extension/xExtension-SemanticGrouping`: installable FreshRSS extension;
- `worker`: locked Python package and CLI;
- `packaging/Containerfile`: non-root worker image with the default model
  bundled at build time;
- `fixtures`: versioned database contracts used by tests and migration checks;
- `docs`: configuration, database, and deployment details.

## Quick start

1. Copy `extension/xExtension-SemanticGrouping` into the FreshRSS extensions
   directory and enable it for the target user.
2. Mount a directory owned by the shared group (GID 33, mode `2770`) at
   `/semantic-data` in both containers.
3. Keep the default **All entries** candidate source, or select a non-empty
   FreshRSS saved query, in the extension configuration.
4. Run the worker every ten minutes with a stable, randomized per-host offset
   of up to ten minutes. Published generation state and the producer lease make
   no-op timer invocations inexpensive:

   ```sh
   freshrss-semantic run
   ```

5. After the worker publishes, let FreshRSS run user maintenance once. Semantic
   groups and the fixed **Single articles** bucket then appear under **My labels**.

Worker commands are `validate-config`, `embed`, `group`, `cleanup`, `run`, and
`rebuild`. `run` executes embedding and grouping in separate child processes so
their peak memory is not additive.

## Development

```sh
make check
make integration
CONTAINER_RUNTIME=podman make integration
podman build -f packaging/Containerfile -t freshrss-semantic:dev .
```

The fast test suite uses deterministic embedding fakes and separately exercises
SemHash's real precomputed-embedding USearch API. `make integration` requires a
Docker-compatible runtime and tests the pinned FreshRSS container plus the
offline, memory-limited worker image with the bundled model.

See the [system design](design.md) for architectural decisions and invariants.
Component and operational details are split across the linked documents under
`docs/`.

## Privacy and recovery

`semantic.sqlite` contains bounded, normalized title/content-derived text when
those fields are enabled. Protect it like FreshRSS data. It is reproducible and
may be excluded from backups, but it must not be deleted or replaced while
either process is using it. Full reset is extension-owned; worker `rebuild`
deletes only worker-owned derived rows.

Released under the GNU General Public License v3.0.
