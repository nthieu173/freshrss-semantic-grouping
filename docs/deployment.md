# Deployment

The worker build follows the latest patch image of the Python minor version in
`packaging/Containerfile`, and the latest uv release.
Application dependencies remain exact versions in `worker/pyproject.toml` and
`worker/uv.lock`; the final worker image and extension are released together.

## Shared storage

Create the host directory before starting either container:

```sh
install -d -m 2770 -o 33 -g 33 /var/lib/freshrss/semantic
```

Mount it at `/semantic-data` in FreshRSS and the worker with `rw,z`. Both
processes need directory write permission for SQLite journal files. Do not mount
FreshRSS application data into the worker. Add supplementary GID 33 to the
FreshRSS container when its web/maintenance user has a different primary group;
the setgid directory then gives both containers group-owned `0660` files.

The worker image runs as UID 10001 with primary GID 33, has no network listener,
and expects a read-only root filesystem plus a small writable `/tmp` tmpfs. The
default model is baked into `/opt/models/potion-base-8M`, so normal runs need no
outbound network. Release builds pin that model to Hugging Face revision
`MODEL_REVISION` in `packaging/Containerfile`; override that build argument only
as part of a tested paired release.

A non-default model is resolved through Hugging Face into `/tmp/huggingface`.
Such a configuration therefore needs outbound access and a `/tmp` allocation
large enough for that model (or an explicitly managed cache mount); the bundled
default does not.

Example Podman invocation:

```sh
podman run --rm \
  --read-only \
  --tmpfs /tmp:rw,size=32m \
  --memory 400m \
  --volume /var/lib/freshrss/semantic:/semantic-data:rw,z \
  ghcr.io/OWNER/freshrss-semantic-grouping:VERSION run
```

Schedule that command every 10 minutes with a stable, randomized per-host offset
of up to 10 minutes. For example, a systemd timer can use:

```ini
[Timer]
OnCalendar=*:0/10
RandomizedDelaySec=10m
FixedRandomDelay=yes
AccuracySec=1s
Persistent=true
```

`FixedRandomDelay=yes` keeps that host's offset identical for every firing. For
example, an 8-minute, 42-second offset produces `:08:42`, `:18:42`, and
`:28:42`, rather than varying the gap between runs.

`run` exits successfully without child processes when the semantic database
does not exist yet, the pipeline is disabled, the producer lease is expired,
the active generation has no pending work, or another worker owns the advisory
lock. An absent database is logged at informational level as not configured.

## PostgreSQL storage

To keep the semantic database in PostgreSQL instead of the shared directory,
give both containers the same connection URL through an environment file
rather than the command line. The password can sit in the URL's user-info part
or, as here, in libpq's `PGPASSWORD`:

```sh
SEMANTIC_DATABASE_URL=postgresql://semantic@postgres:5432/semantic
PGPASSWORD=...
```

The FreshRSS image already includes `pdo_pgsql`. Create the schema once with
`php extensions/xExtension-SemanticGrouping/cli/migrate.php` as the FreshRSS
user (enabling the extension also does it). The worker then needs a network
that reaches PostgreSQL instead of `--network none`, and no `/semantic-data`
mount:

```sh
podman run --rm \
  --read-only \
  --tmpfs /tmp:rw,size=32m \
  --memory 400m \
  --network DATABASE-NETWORK \
  --env-file /run/semantic-worker.env \
  ghcr.io/OWNER/freshrss-semantic-grouping:VERSION run
```

The run is still a successful no-op until the extension has created the schema.
See [the database contract](database.md#postgresql) for locking and reset.

## Failure behavior

- A semantic storage or saved-query error is logged and swallowed by the
  FreshRSS maintenance hook.
- Database lock contention is bounded and deferred to a later timer run.
- Committed embedding batches remain reusable after a later batch fails.
- Partial or stale vectors prevent group replacement.
- A deleted FreshRSS representative or a personal-label name conflict skips the
  affected group, uses **Single articles** for its available candidates, and
  records a bounded synchronization error.
- Incomplete or failed worker publication leaves all prior native semantic
  labels in place.

FreshRSS invokes user maintenance before the current feed actualization.
Newly fetched articles are therefore normally included on the following
scheduled actualization; this one-cycle lag is intentional in the first
release.
