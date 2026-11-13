-- PostgreSQL form of semantic-schema-v3.sql. Tables, columns and constraints
-- match the SQLite contract; integers are BIGINT, BLOB is BYTEA, and the
-- schema_version row replaces SQLite's PRAGMA user_version.
CREATE TABLE schema_version (
    singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
    version INTEGER NOT NULL
);
CREATE TABLE pipeline_config (
    singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
    database_schema_version INTEGER NOT NULL,
    config_revision TEXT NOT NULL,
    active_generation BIGINT NOT NULL,
    producer_lease_until BIGINT NOT NULL,
    config_json TEXT NOT NULL,
    updated_at BIGINT NOT NULL
);
CREATE TABLE candidate_generations (
    generation BIGINT PRIMARY KEY,
    query_fingerprint TEXT NOT NULL,
    started_at BIGINT NOT NULL,
    completed_at BIGINT,
    candidate_count BIGINT
);
CREATE TABLE article_inputs (
    entry_id TEXT NOT NULL,
    feed_id TEXT NOT NULL,
    received_at BIGINT NOT NULL,
    embedding_text TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    exported_at BIGINT NOT NULL,
    PRIMARY KEY (entry_id, source_hash)
);
CREATE TABLE candidate_members (
    generation BIGINT NOT NULL,
    entry_id TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    normalized_title TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (generation, entry_id),
    FOREIGN KEY (generation) REFERENCES candidate_generations(generation) ON DELETE CASCADE,
    FOREIGN KEY (entry_id, source_hash) REFERENCES article_inputs(entry_id, source_hash)
);
CREATE INDEX candidate_members_article ON candidate_members (entry_id, source_hash);
CREATE TABLE embeddings (
    entry_id TEXT PRIMARY KEY,
    source_hash TEXT NOT NULL,
    embedding_fingerprint TEXT NOT NULL,
    model_id TEXT NOT NULL,
    dimensions INTEGER NOT NULL CHECK (dimensions > 0),
    embedding BYTEA NOT NULL,
    embedded_at BIGINT NOT NULL
);
CREATE TABLE groups (
    group_id TEXT PRIMARY KEY,
    representative_entry_id TEXT NOT NULL,
    selection_generation BIGINT NOT NULL,
    grouping_fingerprint TEXT NOT NULL,
    generated_at BIGINT NOT NULL
);
CREATE TABLE group_members (
    group_id TEXT NOT NULL,
    entry_id TEXT NOT NULL UNIQUE,
    similarity DOUBLE PRECISION,
    PRIMARY KEY (group_id, entry_id),
    FOREIGN KEY (group_id) REFERENCES groups(group_id) ON DELETE CASCADE
);
CREATE TABLE worker_state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE export_state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE managed_labels (
    semantic_key TEXT PRIMARY KEY,
    label_id BIGINT NOT NULL UNIQUE,
    label_name TEXT NOT NULL,
    selection_generation BIGINT NOT NULL,
    updated_at BIGINT NOT NULL
);
CREATE TABLE label_sync_state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
INSERT INTO schema_version (singleton, version) VALUES (1, 3);
