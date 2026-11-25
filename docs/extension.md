# FreshRSS extension design

## Responsibilities and hooks

`xExtension-SemanticGrouping` is a user extension. `install()` refuses FreshRSS
releases older than it supports, and the integration tests run it against the
release pinned in the `Makefile`. Its entry point registers:

- `EntryBeforeAdd` for exact-title rejection;
- `FreshrssUserMaintenance` for candidate and native-label reconciliation;
- install/upgrade schema creation and migration;
- a pipeline-disable path that publishes a disabled revision and removes only
  extension-owned labels;
- uninstall cleanup based on native ownership attributes;
- configuration and pipeline/label-sync status;
- the Stories view: a `stories` controller and views, a `ViewModes` entry so it
  can be the default view, and a `NavReadingModes` toolbar button.

The extension is the only user-facing configuration surface. It stores schema-3
settings through FreshRSS's per-user extension configuration API. A successful
export publishes the effective worker configuration to `pipeline_config`; the
worker never reads FreshRSS configuration files.

## Exact-title admission

Exact-title filtering is synchronous and independent of the semantic pipeline
switch. On the first hook invocation in a refresh process, the filter loads all
currently retained titles through FreshRSS's DAO and builds a normalized hash
set. Before each admission decision it also reads titles from FreshRSS's
temporary-entry table, where accepted entries wait for the surrounding feed
actualization to commit. It re-reads every staged row rather than only rows
above the highest ID it has seen, because entry IDs come from the clock before
the hook runs and another request can stage a lower ID later. Only staged raw
titles it has not seen before are normalized, so a refresh does not repeat the
normalization for each new entry. For each incoming entry it:

1. HTML-decodes and Unicode-normalizes the title;
2. uses PHP Intl's ICU transliterator to canonicalize Unicode quotation marks;
3. lowercases it with Unicode support;
4. trims and collapses whitespace;
5. accepts blank normalized titles as non-deduplicable;
6. rejects a title already in the set;
7. adds each accepted title to the set before returning the entry.

The in-process update rejects duplicates in the same refresh batch. Refreshing
the temporary titles also covers entries staged by another refresh request that
have not reached the retained-entry table yet. Punctuation and source names
remain significant to avoid over-aggressive ingestion deletion, while visual
variants of quotation marks compare equally.

## Candidate-source resolution

The selector stores a saved query's numeric identity and current name. Resolution
constructs a `FreshRSS_UserQuery`, rejects deprecated or unfiltered definitions,
maps its native source type and ID, preserves its state flags, and clones its
`FreshRSS_BooleanSearch`. Both FreshRSS encodings of unfiltered “all” state
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

Only canonical embedding input and the normalized title used for within-group
deduplication are copied. Feed HTML, the complete article body, and other
FreshRSS fields remain in FreshRSS.

## Candidate reconciliation

Maintenance acquires a non-blocking per-user lock (a file lock, or a PostgreSQL
advisory lock). It returns when another
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
eventually stops worker work. Pipeline disablement and uninstall discover labels
by their native ownership attributes and remove only those labels; entries and
personal labels remain untouched.

Changing the rebuild token invalidates embedding and grouping fingerprints
without deleting producer data. Full reset is an administrator action: it takes
the exporter lock and worker lock, transactionally drops and recreates schema in
the existing database file (or its own PostgreSQL tables), and immediately
republishes candidates. It can
recover an unknown schema version without renaming or replacing the file.

## Native-label reconciliation and status

At the start of enabled maintenance, the hook checks whether the worker has
atomically published the active generation with the expected grouping
fingerprint. If so, it reconciles that publication before candidate export can
advance the active generation. If not, it leaves all existing native labels
unchanged. It also uses the worker advisory lock so a publication cannot change
during a label pass.

For a complete publication, each multi-article component gets one native label
named exactly after its current representative article. All active candidates
outside those components get the fixed **Single articles** label. Assignments
are added before stale managed assignments are removed; obsolete managed labels
are then deleted. Label attributes contain the extension owner and semantic key,
and the shared database stores the key-to-label mapping plus reconciliation
generation, fingerprint, timestamps, and bounded errors.

An existing personal label with a required name causes only that semantic group
to be skipped. Its candidates use **Single articles** so each available
candidate still has exactly one managed semantic label, and the conflict is
reported. Labels without the extension ownership marker are never renamed,
populated, or deleted. A missing representative, unsupported exact label name,
DAO failure, database contention, or incomplete worker generation likewise
cannot affect ordinary FreshRSS maintenance.

The configuration page reports the selected source and definition fingerprint,
export attempt/success, active generation and candidate count, worker
attempt/success, label-sync attempt/success, pending work,
model/window/thresholds, and the latest bounded error summary. It never exposes
traces or host paths. Under **My labels**, article ordering and presentation are
entirely native FreshRSS behavior.

## Stories view

The Stories view (`?c=stories`) shows the worker's last complete publication as
a two-level reading list. Topics come first; each holds its stories and, last,
under **Also in this topic**, its related articles in no story. Stories in no
topic follow. Topics, stories and articles are each ordered by the toolbar's
sort, by default latest received first: a topic or story by its latest article,
which is also when its header says it was updated. Sorting by publication date
or by when the reader last changed an article works the same way with those
times; by title, a topic or story goes by its own title; a random order is
shuffled anew on each visit and kept by its later pages. Sorts by length, link,
feed or category have no meaning for a topic or story, which stay latest
received first; they order its articles only, in the chosen direction. The
toolbar's read-state filter shows topics and stories with an unread article,
those read through, or both, the unread ones first. Each story lists its
articles with source and age, links to its native label, and can be marked read.
A topic can be marked read as a whole. Topics and stories collapse; those with
nothing unread start collapsed, and the ones collapsed by hand are remembered in
the browser's local storage. The view follows the reader's content width and
"auto load more" setting, and links to **Single articles**.

`StoryRepository` reads groups, topics, their members and the label mapping in
one read-only snapshot, holding only entry IDs. FreshRSS supplies the unread IDs
from the window's oldest article on (and, for sorts other than reception time,
the articles' dates or titles), and the page's articles are read in one query,
so a page costs about the same however large the window is. A page is ten topics
or stories. Its cursor is the sort key of the previous page's last item, not a
position, so items marked read in place, which move to the end, never make the
next page skip any; one may come again among the read items, and the page skips
items it already shows. Marking read posts the article IDs to FreshRSS's own
`entry/read` action. The view uses its own classes, so FreshRSS's mark-on-scroll
and paging scripts leave it alone.

Like the configuration page, the view reads the semantic database from the web
server, so FreshRSS needs the shared GID described in the deployment guide. It
shows a notice while the pipeline is disabled or the database cannot be read.
