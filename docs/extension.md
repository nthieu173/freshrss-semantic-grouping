# FreshRSS extension design

## Responsibilities and hooks

`xExtension-SemanticGrouping` is a user extension requiring FreshRSS 1.28 or
newer and tested against 1.29.1. Its entry point registers:

- `EntryBeforeAdd` for exact-title rejection;
- `FreshrssUserMaintenance` for candidate reconciliation;
- install/upgrade schema creation and migration;
- a disable/uninstall path that attempts to publish a disabled revision without
  deleting shared data;
- configuration and Semantic Groups controllers, views, navigation, and styles.

The extension is the only user-facing configuration surface. It stores schema-2
settings through FreshRSS's per-user extension configuration API. A successful
export publishes the effective worker configuration to `pipeline_config`; the
worker never reads FreshRSS configuration files.

## Exact-title admission

Exact-title filtering is synchronous and independent of the semantic pipeline
switch. On the first hook invocation in a refresh process, the filter loads all
currently retained titles through FreshRSS's DAO and builds a normalized hash
set. For each incoming entry it:

1. HTML-decodes and Unicode-normalizes the title;
2. uses PHP Intl's ICU transliterator to canonicalize Unicode quotation marks;
3. lowercases it with Unicode support;
4. trims and collapses whitespace;
5. accepts blank normalized titles as non-deduplicable;
6. rejects a title already in the set;
7. adds each accepted title to the set before returning the entry.

The in-process update rejects duplicates in the same refresh batch as well as
duplicates of stored entries. Punctuation and source names remain significant
to avoid over-aggressive ingestion deletion, while visual variants of quotation
marks compare equally. The filter temporarily mirrors and restores FreshRSS
request search state where required by the 1.29.1 DAO.

## Candidate-source resolution

The selector stores a saved query's numeric identity and current name. Resolution
constructs a `FreshRSS_UserQuery`, rejects deprecated or unfiltered definitions,
maps its native source type and ID, preserves its state flags, and clones its
`FreshRSS_BooleanSearch`. Both FreshRSS 1.29.1 encodings of unfiltered “all” state
are recognized when deciding whether a saved query is empty.

A rolling UTC date constraint is added as a separate Boolean-search child. The
definition fingerprint includes the serialized query, expanded search, native
source, state, and window. Renames and duplicate names are rejected so a stale
configuration cannot silently select a different query. **All entries** is an
explicit mode with its own fingerprint.

Entry enumeration is lazy. For the iterator lifetime, the exporter mirrors the
search into `FreshRSS_Context::$search`, because the pinned EntryDAO consults
that global even when `listWhere()` receives the same filter. The prior request
state is restored in a `finally` block.

## Input construction

The configured title and/or content fields are converted into one canonical
embedding string. HTML entities are decoded, markup is removed, Unicode and
whitespace are normalized, and content is bounded on a character boundary. The
source hash uses SHA-256 over a versioned, length-delimited encoding of the
selected fields, avoiding ambiguous concatenation.

Only canonical embedding input is copied. Feed HTML, the complete article body,
and other FreshRSS fields remain in FreshRSS.

## Candidate reconciliation

Maintenance acquires a non-blocking per-user file lock. It returns when another
exporter is active or when the configured interval has not elapsed and neither
the settings nor expanded query fingerprint changed. Saving changed settings
or changing the query definition bypasses the interval.

The exporter allocates a new incomplete generation, streams immutable article
versions and memberships in batches of 200, and activates the completed
generation with its configuration revision and lease in one transaction. An
article version is keyed by `(entry_id, source_hash)`, so constructing a new
generation cannot change inputs referenced by the active one.

After activation, best-effort pruning retains the active generation and the last
worker-published generation. Enumeration, batch, activation, or cleanup errors
are sanitized and recorded where possible. They are always swallowed at the
maintenance-hook boundary.

## Disable, rebuild, and reset

Turning off the pipeline publishes a disabled revision under the same exporter
lock. This path remains fail-safe even if an older stored configuration no longer
passes current validation. If publication is impossible, producer-lease expiry
eventually stops worker work.

Changing the rebuild token invalidates embedding and grouping fingerprints
without deleting producer data. Full reset is an administrator action: it takes
the exporter lock and worker lock, transactionally drops and recreates schema in
the existing database file, and immediately republishes candidates. It can
recover an unknown schema version without renaming or replacing the file.

## Grouped page and status

The Semantic Groups page opens the shared database in query-only mode and reads
only group mappings, pipeline/export state, and worker state. It resolves current
articles through FreshRSS's EntryDAO, paginates groups, skips deleted entries,
and hides groups that fall below the configured minimum after retention.

Only HTTP and HTTPS article URLs are linked. All feed-provided titles and content
are escaped. Database absence, bounded lock contention, stale mappings, and
worker failure render as no/old groups plus a safe status message; they do not
break normal FreshRSS reading.

Status includes the selected source and current definition fingerprint, export
attempt/success, active generation and candidate count, worker attempt/success,
pending embedding/group work, model/window/threshold, producer lease, and the
latest bounded error summary. It never exposes traces or host paths.
