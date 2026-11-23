# Semantic database contract

By default the database is SQLite at the fixed path
`/semantic-data/semantic.sqlite`. Setting `SEMANTIC_DATABASE_URL` to a
`postgresql://` URL in both the FreshRSS and worker environments moves it to
PostgreSQL instead (see [PostgreSQL](#postgresql)). Any value that is not blank
after trimming whitespace selects PostgreSQL; its scheme must be `postgresql`
or `postgres` (in any letter case), and any other scheme is an error rather than
a fallback to SQLite. No browser parameter can change either. The extension
creates and migrates the database; the worker validates schema version 4 and
refuses to migrate, rename, delete, or replace an unknown database.

All connections enable foreign keys, use rollback journaling, wait at most five
seconds for a lock, and keep write transactions short. Encoding and SemHash
index construction happen outside write transactions.

## Ownership

| Owner | Tables |
|---|---|
| Extension | `pipeline_config`, `candidate_generations`, `article_inputs`, `candidate_members`, `export_state`, `managed_labels`, `label_sync_state` |
| Worker | `embeddings`, `groups`, `group_members`, `topics`, `topic_members`, `worker_state` |

Foreign keys exist only within a single writer's table set. Cross-owner
references are validated during loading and cleanup rather than by cascades.
The complete DDL is checked in at `fixtures/semantic-schema-v4.sql`. The v1 to
v3 fixtures are retained to verify additive migrations. The nullable
`group_members.similarity` column remains for additive compatibility but new
workers leave it null. Schema v3 adds the normalized title to each generation's
membership row. Schema v4 adds `topics` and `topic_members`: a topic gathers
related groups and articles in no group, and its members are articles, so a
topic's stories are the groups its members belong to. Every migration expires
the producer lease and clears the last-export timestamp so the worker waits for
the next maintenance pass to republish candidates immediately.

## Generation publication

The exporter inserts an incomplete `candidate_generations` row, streams
immutable `(entry_id, source_hash)` inputs and memberships with normalized titles
in batches, then marks the generation complete and switches
`pipeline_config.active_generation` in one transaction. A failed batch never
changes the active generation.

The worker snapshots `(active_generation, config_revision)`. Embedding batch
commits and group publication recheck both values inside their write
transaction. Group and topic results are fully computed before one transaction
replaces `groups`, `group_members`, `topics` and `topic_members`, so an
exception or out-of-memory termination leaves the last publication intact.

Embeddings are contiguous little-endian float32 BLOBs. Readers reject a BLOB
whose byte length is not `dimensions * 4`, non-finite vectors, and mixed
dimensions in one active generation.

`managed_labels` durably maps semantic keys to native FreshRSS label IDs and
names. `label_sync_state` records the last attempted and completed generation,
fingerprint, and bounded error. Native label attributes are the cleanup
authority if this reproducible database is reset or unavailable.

## Upgrade and recovery

For a schema upgrade, stop the worker, install the paired extension/worker
release, enable or install the extension so its transactional migration runs,
then start the new worker. The worker never performs this migration. The
migration reads the schema version only after taking the write lock
(`BEGIN IMMEDIATE`, or the PostgreSQL write lock), so enabling the extension
while `cli/migrate.php` runs is safe: the second migrator waits and then finds
the schema current.

Worker `rebuild` takes the worker advisory lock and deletes only embeddings,
groups, topics, their members, and worker state. The extension's administrator-
only full reset takes that same lock, transactionally recreates the schema in
the existing database file, and republishes the candidate generation. Neither
action renames or replaces the live SQLite file.

## PostgreSQL

`SEMANTIC_DATABASE_URL` takes a libpq connection URL, for example
`postgresql://semantic@postgres:5432/semantic`. Anything the URL leaves out
comes from libpq's own variables, so the password may be in the URL's user-info
part, `PGPASSWORD`, or a `PGPASSFILE`. Query parameters such as `sslmode` or
`options=-csearch_path%3Dsemantic` pass through to libpq; the tables live in
the connection's current schema. Both components must use the same database,
schema and role (or roles with full rights on the tables the extension creates).

The schema is the v4 contract with PostgreSQL types: integers are `BIGINT`,
embeddings are `BYTEA`, and a one-row `schema_version` table stands in for
SQLite's `PRAGMA user_version`. The complete DDL is
`fixtures/semantic-schema-v4.postgres.sql`. A PostgreSQL database is created at
the current version. The oldest PostgreSQL schema is v3
(`fixtures/semantic-schema-v3.postgres.sql`), which upgrades to v4 in place;
the v1 and v2 migrations apply only to SQLite.
The extension refuses to create its tables in a schema that already holds
unversioned tables of the same names.

SQLite's locking is reproduced rather than relaxed:

- Every write transaction first takes the transaction-level advisory lock
  `semantic-write`, so writers run one at a time as with `BEGIN IMMEDIATE`, and
  the generation/revision rechecks stay valid until commit. Readers are not
  blocked. `lock_timeout` is five seconds, matching SQLite's busy timeout.
- Label reconciliation reads its publication in one `REPEATABLE READ, READ ONLY`
  transaction.
- The exporter, label and worker locks are session-level advisory locks on a
  dedicated connection instead of files under `/semantic-data`; a crashed
  holder releases them when its connection closes. Both components derive the
  same 60-bit key from each lock name.

Full reset drops and recreates only the tables listed above; anything else in
the schema is left alone. Switching an existing deployment between SQLite and
PostgreSQL does not copy data: the extension creates an empty PostgreSQL schema
(on enable, or through `cli/migrate.php`) and republishes candidates at the next
maintenance pass, and the worker recomputes embeddings. Native label attributes
remain the ownership authority, so existing semantic labels are reconciled
rather than duplicated.
