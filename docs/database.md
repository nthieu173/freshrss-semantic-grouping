# Semantic database contract

The fixed runtime path is `/semantic-data/semantic.sqlite`. No browser parameter
can change it. The extension creates and migrates the database; the worker
validates schema version 1 and refuses to migrate, rename, delete, or replace an
unknown database.

All connections enable foreign keys, use rollback journaling, wait at most five
seconds for a lock, and keep write transactions short. Encoding and SemHash
index construction happen outside write transactions.

## Ownership

| Owner | Tables |
|---|---|
| Extension | `pipeline_config`, `candidate_generations`, `article_inputs`, `candidate_members`, `export_state` |
| Worker | `embeddings`, `groups`, `group_members`, `worker_state` |

Foreign keys exist only within a single writer's table set. Cross-owner
references are validated during loading and cleanup rather than by cascades.
The complete DDL is checked in at `fixtures/semantic-schema-v1.sql`.

## Generation publication

The exporter inserts an incomplete `candidate_generations` row, streams
immutable `(entry_id, source_hash)` inputs and memberships in batches, then
marks the generation complete and switches `pipeline_config.active_generation`
in one transaction. A failed batch never changes the active generation.

The worker snapshots `(active_generation, config_revision)`. Embedding batch
commits and group publication recheck both values inside their write
transaction. Group results are fully computed before one transaction replaces
`groups` and `group_members`, so an exception or out-of-memory termination
leaves the last publication intact.

Embeddings are contiguous little-endian float32 BLOBs. Readers reject a BLOB
whose byte length is not `dimensions * 4`, non-finite vectors, and mixed
dimensions in one active generation.

## Upgrade and recovery

For a schema upgrade, stop the worker, install the paired extension/worker
release, enable or install the extension so its transactional migration runs,
then start the new worker. Do not migrate from a normal grouped-page request.

Worker `rebuild` takes the worker advisory lock and deletes only embeddings,
groups, group members, and worker state. The extension's administrator-only full
reset takes that same lock, transactionally recreates the schema in the existing
database file, and republishes the candidate generation. Neither action renames
or replaces the live SQLite file.
