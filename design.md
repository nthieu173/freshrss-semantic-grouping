# FreshRSS Semantic Grouping Design

## Status and scope

This document describes the architecture implemented by the paired FreshRSS
extension and Python worker in this repository. The version 0.1.0 design targets
FreshRSS 1.29.1, Python 3.12, `linux/arm64`, extension configuration schema 2,
and semantic database schema 1.

The extension owns user interaction, FreshRSS integration, candidate selection,
and candidate publication. The worker owns embeddings and semantic groups. The
deployment infrastructure owns mounts, scheduling, resource limits, image
execution, and public routing.

The initial scope is one configured FreshRSS user and includes:

- exact normalized-title rejection during ingestion;
- native saved-query or explicit all-entry candidate selection;
- bounded, generation-based article snapshot export;
- title and optional truncated-content embedding input;
- cached Model2Vec float32 embeddings;
- SemHash grouping through its USearch backend;
- a separate paginated Semantic Groups page;
- extension-owned full reset and worker-owned derived-data rebuild;
- separate embedding and grouping processes under a 400 MiB worker limit.

Semantic grouping never marks, deletes, or suppresses FreshRSS entries. Exact
title rejection is the only ingestion-time deletion.

## System context

```text
FreshRSS container
  entries + saved queries
          |
          | complete generation of canonical inputs
          v
 /semantic-data/semantic.sqlite
          ^                         |
          | published groups/status | active candidates/configuration
          |                         v
  Semantic Groups page       Python worker
                             Model2Vec -> SemHash/USearch
```

There is no network protocol between the components. The worker receives only
`/semantic-data/semantic.sqlite`; FreshRSS application data is not mounted into
its container. The database path is fixed and cannot be selected by a browser
parameter.

The shared read/write database makes the extension and worker one trust domain.
Table ownership is a code and transaction boundary, not a filesystem security
boundary. A future requirement for write isolation would require separate
candidate and output databases.

## Authority boundaries

FreshRSS remains authoritative for entries, current article state, saved
queries, categories, feeds, and labels. The extension uses FreshRSS model and
DAO APIs rather than reproducing its search grammar or querying its private
database schema.

The extension is authoritative for:

- extension configuration and candidate-source identity;
- effective worker configuration and the renewable producer lease;
- candidate generations and immutable article input versions;
- exact-title admission decisions;
- schema creation, migration, and full reset.

The worker is authoritative for:

- cached embeddings;
- published group and membership rows;
- worker phase status and errors;
- cleanup and rebuild of worker-owned derived rows.

Detailed table ownership and DDL are in [the database contract](docs/database.md).

## Candidate selection

A FreshRSS saved user query is the authoritative semantic candidate selector.
The extension resolves the query to the same source type, source ID, state flags,
and `FreshRSS_BooleanSearch` used by the normal reader. It adds the rolling
received-date cutoff as a separate outer `AND` term, preserving nested `OR` and
negation semantics. Feed, category, text, tag, label, read/favourite state, and
date behavior therefore remain FreshRSS behavior.

The configured query is identified by numeric key and name. Missing, renamed,
empty, deprecated, or ambiguously named queries fail closed and leave the last
complete generation active. Unfiltered selection is available only through the
explicit **All entries** mode.

The exporter fingerprints the fully expanded effective definition. Query edits
therefore force reconciliation even when the normal export interval has not
elapsed. Selection controls only which canonical snapshots are copied for
semantic work; non-matching entries remain available in FreshRSS.

## Snapshot and publication model

Both producer and consumer publish replaceable snapshots rather than mutating a
visible result incrementally.

For each export, the extension:

1. takes a non-blocking per-user exporter lock;
2. validates configuration and resolves the candidate source;
3. creates an incomplete candidate generation;
4. lazily enumerates the bounded native query;
5. writes immutable `(entry_id, source_hash)` inputs and generation membership
   in batches of 200;
6. completes and activates the generation, effective configuration, revision,
   and lease in one short transaction;
7. prunes inputs not needed by the active or last worker-published generation.

An export failure never changes the active generation. The worker ignores
incomplete generations.

For grouping, the worker snapshots `(active_generation, config_revision)`,
computes outside a write transaction, then checks the snapshot again in the
publication transaction. A changed generation or revision aborts publication.
One transaction replaces all group and membership rows, so readers observe the
previous complete publication or the new complete publication.

## Canonical inputs and invalidation

The extension HTML-decodes, strips markup, normalizes Unicode and whitespace,
and truncates on a character boundary. It stores only the configured canonical
`embedding_text`, not another full article copy. SHA-256 covers a versioned,
length-delimited serialization of the selected fields.

An embedding is current only when both its source hash and embedding fingerprint
match. The embedding fingerprint covers the model, selected fields, content
limit, normalization/input format versions, and explicit rebuild token. The
grouping fingerprint additionally covers the query fingerprint, rolling window,
threshold, and minimum group size.

This split avoids recomputing vectors for grouping-only changes while ensuring
that every text or model change invalidates the appropriate cache. Old vectors
and groups stay usable until a complete replacement can be published.

## Scheduling and liveness

FreshRSS user maintenance performs bounded candidate reconciliation. FreshRSS
1.29.1 runs this hook before the current feed actualization, so newly fetched
entries normally enter the following maintenance export. This one-cycle lag is
an accepted initial-release tradeoff.

Every successful export renews a producer lease for three export intervals with
a 90-minute minimum. The worker performs no new work after expiry or when the
published pipeline is disabled. This makes stale configuration fail safe when
FreshRSS stops or cannot write a final disabled revision.

The host can invoke `freshrss-semantic run` on a fixed ten-minute timer. The
command checks the lease, enabled state, logical worker interval, and advisory
worker lock before spawning separate cleanup, embed, and group phases.

## Consistency and failure invariants

- FreshRSS data is never mounted into or modified by the worker.
- A semantic storage or query error cannot escape the maintenance hook or
  reject an otherwise valid FreshRSS entry.
- A partially exported generation never becomes active.
- A stale embedding cannot satisfy a changed source or embedding configuration.
- Groups are published only when every active candidate has a current,
  well-formed embedding.
- Group publication cannot target a superseded generation or revision.
- A failed batch, process, lock attempt, or out-of-memory termination leaves the
  last complete groups intact.
- The worker refuses an unknown schema and never migrates, renames, replaces, or
  resets the shared database.
- The grouped page is query-only and tolerates a missing database, lock
  contention, stale references, and FreshRSS retention.
- Disablement or lease expiry stops new semantic work without deleting the last
  publication.

SQLite uses rollback journaling, foreign keys, a five-second busy timeout, and
short transactions. Foreign keys cross tables only when those tables have the
same writer; cross-owner references are checked in application logic and tests.

## Identity and presentation

SemHash duplicate relationships are converted to connected components. The
earliest `(received_at, entry_id)` member is the representative. The group ID is
a versioned SHA-256-derived value based on that representative, and member
similarity is cosine similarity to it.

The grouped page reads semantic mappings and status in query-only mode, then
resolves current titles, URLs, state, and content through FreshRSS. Missing
entries are skipped, groups below the configured minimum are hidden, URLs are
restricted to HTTP(S), and feed-provided content is escaped.

## Security, privacy, and recovery

The semantic directory is shared through GID 33 with directory mode `2770` and
database/lock mode `0660`. Both processes need directory write permission for
SQLite journal files. The worker runs as a dedicated non-root UID, has no
listener, uses a read-only root filesystem, and needs only `/semantic-data` plus
a small `/tmp` filesystem.

The database contains bounded title/content-derived text and must be protected
like FreshRSS data. It is reproducible and can be excluded from backups. A full
reset is initiated by the extension while holding exporter and worker locks and
recreates schema inside the existing file. Worker `rebuild` deletes only
worker-owned derived rows.

## Detailed component designs

- [FreshRSS extension](docs/extension.md)
- [Worker](docs/worker.md)
- [Configuration and fingerprints](docs/configuration.md)
- [Database contract](docs/database.md)
- [Deployment and failure behavior](docs/deployment.md)
- [Testing and releases](docs/testing.md)

## Deferred capabilities

The initial design deliberately excludes sqlite-vec, an HTTP API, a queue,
direct worker access to FreshRSS storage, ingestion-hook candidate export,
push-driven updates, worker triggering from web requests, cross-user groups,
distributed workers, a replacement for all native reading interactions, and
semantic deletion or automatic article suppression.

sqlite-vec is unnecessary because SemHash already constructs and searches its
own USearch index; ordinary SQLite is sufficient for fixed-size embedding BLOBs
and published mappings.

## External references

- [FreshRSS extension documentation](https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html)
- [FreshRSS article filtering](https://freshrss.github.io/FreshRSS/en/users/10_filter.html)
- [FreshRSS saved user queries](https://freshrss.github.io/FreshRSS/en/users/user_queries.html)
- [FreshRSS 1.29.1 maintenance-hook timing](https://github.com/FreshRSS/FreshRSS/blob/1.29.1/app/Controllers/feedController.php#L891-L938)
- [SemHash](https://github.com/MinishLab/semhash)
- [Model2Vec](https://github.com/MinishLab/model2vec)

