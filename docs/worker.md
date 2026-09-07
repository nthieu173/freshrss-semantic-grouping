# Semantic worker design

## Command model

The `freshrss-semantic` CLI exposes:

```text
freshrss-semantic validate-config
freshrss-semantic embed
freshrss-semantic group
freshrss-semantic cleanup
freshrss-semantic run
freshrss-semantic rebuild
```

Every mutating command coordinates through an advisory lock beside the semantic
database. `run` holds that lock and invokes cleanup, embedding, and grouping as
separate child processes. An internal marker lets those children participate in
the already locked run without reacquiring it. Direct concurrent invocations
exit without overlapping mutations.

Before spawning work, `run` validates schema and configuration, then exits
successfully when the pipeline is disabled, the producer lease expired, or the
logical worker interval has not elapsed. A fixed host timer can therefore be
more frequent than the configured interval.

## Configuration snapshot

The worker reads only `pipeline_config` from the fixed semantic database. It
validates database schema 1 and the published configuration, then snapshots the
active generation and configuration revision. It rechecks both inside each
write transaction that could affect a visible result.

An unknown schema, missing database, malformed configuration, or expired lease
is reported safely. The worker never creates, migrates, renames, replaces, or
deletes the shared database file.

## Embedding phase

The embedding phase joins active generation membership to the exact immutable
article input and existing embedding. A row is pending when its vector is absent
or its source/configuration fingerprint is stale.

The phase loads Model2Vec once and processes canonical text in configurable
batches, defaulting to 128 and capped at 256. Multiprocessing is disabled. Text
encoding occurs outside a write transaction; each batch is upserted in a short
transaction after verifying the generation and revision are still current.

Vectors are stored as contiguous little-endian float32 BLOBs with model,
dimension, source hash, embedding fingerprint, and timestamp. Loading rejects a
length other than `dimensions * 4`, non-finite values, or mixed dimensions.

A race with an article update is harmless: the committed vector records the old
source hash, and the next active-generation join marks it stale.

## Grouping phase

Grouping loads only active entry IDs, received times, and current vectors. It
requires a current vector for every candidate; missing or invalid input defers
publication and retains the old groups.

The worker passes a float32 matrix and minimal ID records to
`SemHash.from_embeddings` with the USearch backend. Its protocol adapter raises
if SemHash unexpectedly attempts text encoding, guaranteeing that the Model2Vec
model is not resident during grouping.

SemHash duplicate edges are combined with union-find into connected components.
Components smaller than `minimum_group_size` are dropped. The earliest
`(received_at, entry_id)` is the representative; its versioned SHA-256-derived
ID names the group, and cosine similarity to it is stored for every member.

All groups are computed before a write transaction starts. Publication rechecks
the generation and revision, atomically replaces group and membership tables,
and records the last published generation and successful run.

## Cleanup and rebuild

Cleanup removes embeddings whose exact `(entry_id, source_hash)` input no longer
exists. Producer-side pruning separately retains inputs needed by the active and
last published generations. Cleanup can run less frequently than grouping.

`rebuild` deletes only worker-owned embeddings, groups, memberships, and state
while holding the worker lock. It does not touch candidate generations,
extension state, schema, or the database file.

## Process and container boundaries

Embedding and grouping run in separate processes so model weights, article text,
and the SemHash/USearch index do not occupy memory together. Each phase logs
entry count, dimensions where applicable, duration, and peak RSS. The integration
contract runs the real bundled model and SemHash below a 400 MiB hard limit.

The image uses Python 3.12 with pinned direct and transitive dependencies. It
bundles `minishlab/potion-base-8M` at pinned model revision
`bf8b056651a2c21b8d2565580b8569da283cab23`, runs as UID 10001 and GID 33, has
no network listener, supports a read-only root filesystem, and uses `/tmp` only
for runtime caches. The bundled default model works with networking disabled.

A configured non-default Hugging Face model may require outbound access and a
larger or persistent cache. That is an explicit deployment change, not part of
the default runtime contract.

## Failure semantics

- A locked database is retried only within the bounded busy timeout, then work
  is deferred.
- A failed later embedding batch leaves earlier committed vectors reusable.
- A failed or out-of-memory group phase leaves the prior group transaction
  untouched.
- A generation/revision change aborts the phase before stale publication.
- Disabled or expired configuration is a successful no-op.
- Error state is bounded and sanitized; failures never affect FreshRSS data or
  health.

