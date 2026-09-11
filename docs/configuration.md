# Configuration

The FreshRSS extension is the only user-facing configuration surface. A
successful export copies the effective settings and the expanded saved-query
fingerprint into `pipeline_config`; the worker does not read FreshRSS files.

| Setting | Default | Effect |
|---|---:|---|
| Pipeline enabled | yes | Enables export and renews the producer lease. Turning it off publishes a disabled revision. |
| Candidate source | All entries | Includes every entry in the rolling window. A native FreshRSS saved query can be selected instead. |
| Embedding model | `minishlab/potion-base-8M` | Changes invalidate embeddings and groups. |
| Similarity threshold | `0.90` | Changes groups without invalidating vectors. |
| Rolling window | 72 hours | An outer received-date constraint independent of the saved query. |
| Refresh interval | 30 minutes | Candidate reconciliation interval in 10-minute increments; config/query changes bypass it. |
| Minimum group size | 1 | Smaller groups are not published or displayed. A value of 1 allows single-source stories. |
| Title/content input | title only | Selected fields become canonical embedding text. |
| Content limit | 2000 characters | Applied after HTML decoding, markup removal, Unicode normalization, and whitespace normalization. |
| Embedding batch | 128 | May be raised to 256 after measuring memory. |
| Exact-title filter | yes | Rejects a normalized exact title before insertion. |

The pipeline switch controls candidate export and worker processing. The
exact-title filter remains independently controlled by its own setting while
the extension is enabled; disabling the FreshRSS extension disables all of its
hooks.

## Saved-query behavior

The selector stores both FreshRSS's numeric query identity and its name. A
missing, renamed, empty, deprecated, or ambiguous query fails closed and leaves
the previous complete generation active. Feed, category, text, tag/label,
read/favourite state, date, nested `OR`, and negation semantics remain
FreshRSS's responsibility.

The rolling cutoff is represented as a separate `FreshRSS_BooleanSearch` child
combined with the saved query by an outer `AND`; it is not appended to the query
string. A query edit changes the expanded definition fingerprint and therefore
forces a new export even inside the normal export interval.

## Invalidations and leases

Model, input fields, content limit, normalization version, and the explicit
rebuild token form the embedding fingerprint. Threshold, window, minimum size,
and query fingerprint additionally form the grouping fingerprint. Old groups
remain visible until their replacement is complete.

Every complete export renews the producer lease to three export intervals with
a 90-minute minimum. The worker performs no embedding or grouping after the
lease expires. One missed FreshRSS maintenance run therefore does not stop a
normally active pipeline.

The worker checks for a changed generation every 10 minutes at a stable,
randomized per-host offset of up to 10 minutes. The offset spreads work across
hosts without changing the 10-minute interval between checks. Refresh values
below 10 minutes or between 10-minute increments cannot make new groups visible
sooner, so the configuration accepts only 10-minute increments.
