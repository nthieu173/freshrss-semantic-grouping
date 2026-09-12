PRAGMA foreign_keys = ON;
CREATE TABLE pipeline_config (
    singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
    database_schema_version INTEGER NOT NULL,
    config_revision TEXT NOT NULL,
    active_generation INTEGER NOT NULL,
    producer_lease_until INTEGER NOT NULL,
    config_json TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE candidate_generations (
    generation INTEGER PRIMARY KEY,
    query_fingerprint TEXT NOT NULL,
    started_at INTEGER NOT NULL,
    completed_at INTEGER,
    candidate_count INTEGER
);
CREATE TABLE article_inputs (
    entry_id TEXT NOT NULL,
    feed_id TEXT NOT NULL,
    received_at INTEGER NOT NULL,
    embedding_text TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    exported_at INTEGER NOT NULL,
    PRIMARY KEY (entry_id, source_hash)
) WITHOUT ROWID;
CREATE TABLE candidate_members (
    generation INTEGER NOT NULL,
    entry_id TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    normalized_title TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (generation, entry_id),
    FOREIGN KEY (generation) REFERENCES candidate_generations(generation) ON DELETE CASCADE,
    FOREIGN KEY (entry_id, source_hash) REFERENCES article_inputs(entry_id, source_hash)
) WITHOUT ROWID;
CREATE INDEX candidate_members_article ON candidate_members (entry_id, source_hash);
CREATE TABLE embeddings (
    entry_id TEXT PRIMARY KEY,
    source_hash TEXT NOT NULL,
    embedding_fingerprint TEXT NOT NULL,
    model_id TEXT NOT NULL,
    dimensions INTEGER NOT NULL CHECK (dimensions > 0),
    embedding BLOB NOT NULL,
    embedded_at INTEGER NOT NULL
);
CREATE TABLE groups (
    group_id TEXT PRIMARY KEY,
    representative_entry_id TEXT NOT NULL,
    selection_generation INTEGER NOT NULL,
    grouping_fingerprint TEXT NOT NULL,
    generated_at INTEGER NOT NULL
);
CREATE TABLE group_members (
    group_id TEXT NOT NULL,
    entry_id TEXT NOT NULL UNIQUE,
    similarity REAL,
    PRIMARY KEY (group_id, entry_id),
    FOREIGN KEY (group_id) REFERENCES groups(group_id) ON DELETE CASCADE
) WITHOUT ROWID;
CREATE TABLE worker_state (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID;
CREATE TABLE export_state (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID;
CREATE TABLE managed_labels (
    semantic_key TEXT PRIMARY KEY,
    label_id INTEGER NOT NULL UNIQUE,
    label_name TEXT NOT NULL,
    selection_generation INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
) WITHOUT ROWID;
CREATE TABLE label_sync_state (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID;
PRAGMA user_version = 3;
