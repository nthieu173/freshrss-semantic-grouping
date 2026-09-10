<?php
declare(strict_types=1);

final class SemanticGrouping_SemanticDatabase {
	public const PATH = '/semantic-data/semantic.sqlite';
	public const SCHEMA_VERSION = 1;

	public function __construct(public readonly string $path = self::PATH) {}

	public function open(bool $migrate = false, bool $queryOnly = false): PDO {
		if (!$migrate && !is_file($this->path)) {
			throw new RuntimeException('Semantic database has not been created.');
		}
		$directory = dirname($this->path);
		if ($migrate && !is_dir($directory)) {
			throw new RuntimeException('Semantic data directory is missing.');
		}
		if ($migrate && (!is_writable($directory) || !is_executable($directory))) {
			throw new RuntimeException('Semantic data directory is not writable by FreshRSS.');
		}
		if ($migrate && is_file($this->path) && !is_writable($this->path)) {
			throw new RuntimeException('Semantic database is not writable by FreshRSS.');
		}
		$pdo = $this->connect($queryOnly);
		if ($migrate) {
			$this->migrate($pdo);
		} else {
			$version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
			if ($version !== self::SCHEMA_VERSION) {
				throw new RuntimeException('Semantic database schema is incompatible.');
			}
		}
		return $pdo;
	}

	private function connect(bool $queryOnly): PDO {
		$pdo = new PDO('sqlite:' . $this->path, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_TIMEOUT => 5,
		]);
		$pdo->exec('PRAGMA foreign_keys = ON');
		$pdo->exec('PRAGMA busy_timeout = 5000');
		if ($queryOnly) {
			$pdo->exec('PRAGMA query_only = ON');
		} else {
			$pdo->exec('PRAGMA journal_mode = DELETE');
		}
		return $pdo;
	}

	public function migrate(PDO $pdo): void {
		$current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
		if ($current > self::SCHEMA_VERSION) {
			throw new RuntimeException('Semantic database belongs to a newer release.');
		}
		if ($current === self::SCHEMA_VERSION) {
			return;
		}
		if ($current !== 0) {
			throw new RuntimeException('No migration path exists for this semantic database.');
		}
		$pdo->beginTransaction();
		try {
			foreach (self::schemaStatements() as $sql) {
				$pdo->exec($sql);
			}
			$pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
			$pdo->commit();
			@chmod($this->path, 0660);
		} catch (Throwable $error) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw $error;
		}
	}

	/** @return list<string> */
	private static function schemaStatements(): array {
		return [
			'CREATE TABLE pipeline_config (
				singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
				database_schema_version INTEGER NOT NULL,
				config_revision TEXT NOT NULL,
				active_generation INTEGER NOT NULL,
				producer_lease_until INTEGER NOT NULL,
				config_json TEXT NOT NULL,
				updated_at INTEGER NOT NULL
			)',
			'CREATE TABLE candidate_generations (
				generation INTEGER PRIMARY KEY,
				query_fingerprint TEXT NOT NULL,
				started_at INTEGER NOT NULL,
				completed_at INTEGER,
				candidate_count INTEGER
			)',
			'CREATE TABLE article_inputs (
				entry_id TEXT NOT NULL,
				feed_id TEXT NOT NULL,
				received_at INTEGER NOT NULL,
				embedding_text TEXT NOT NULL,
				source_hash TEXT NOT NULL,
				exported_at INTEGER NOT NULL,
				PRIMARY KEY (entry_id, source_hash)
			) WITHOUT ROWID',
			'CREATE TABLE candidate_members (
				generation INTEGER NOT NULL,
				entry_id TEXT NOT NULL,
				source_hash TEXT NOT NULL,
				PRIMARY KEY (generation, entry_id),
				FOREIGN KEY (generation) REFERENCES candidate_generations(generation) ON DELETE CASCADE,
				FOREIGN KEY (entry_id, source_hash) REFERENCES article_inputs(entry_id, source_hash)
			) WITHOUT ROWID',
			'CREATE INDEX candidate_members_article ON candidate_members (entry_id, source_hash)',
			'CREATE TABLE embeddings (
				entry_id TEXT PRIMARY KEY,
				source_hash TEXT NOT NULL,
				embedding_fingerprint TEXT NOT NULL,
				model_id TEXT NOT NULL,
				dimensions INTEGER NOT NULL CHECK (dimensions > 0),
				embedding BLOB NOT NULL,
				embedded_at INTEGER NOT NULL
			)',
			'CREATE TABLE groups (
				group_id TEXT PRIMARY KEY,
				representative_entry_id TEXT NOT NULL,
				selection_generation INTEGER NOT NULL,
				grouping_fingerprint TEXT NOT NULL,
				generated_at INTEGER NOT NULL
			)',
			'CREATE TABLE group_members (
				group_id TEXT NOT NULL,
				entry_id TEXT NOT NULL UNIQUE,
				similarity REAL,
				PRIMARY KEY (group_id, entry_id),
				FOREIGN KEY (group_id) REFERENCES groups(group_id) ON DELETE CASCADE
			) WITHOUT ROWID',
			'CREATE TABLE worker_state (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID',
			'CREATE TABLE export_state (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID',
		];
	}

	/** @param callable(PDO):mixed $callback @return mixed */
	public static function transaction(PDO $pdo, callable $callback) {
		$pdo->exec('BEGIN IMMEDIATE');
		try {
			$result = $callback($pdo);
			$pdo->exec('COMMIT');
			return $result;
		} catch (Throwable $error) {
			try {
				$pdo->exec('ROLLBACK');
			} catch (Throwable) {
				// Preserve the original error if SQLite already ended the transaction.
			}
			throw $error;
		}
	}

	public function allocateGeneration(PDO $pdo, string $queryFingerprint, int $now): int {
		return self::transaction($pdo, static function (PDO $db) use ($queryFingerprint, $now): int {
			$maximum = (int)$db->query('SELECT COALESCE(MAX(generation), 0) FROM candidate_generations')->fetchColumn();
			$active = (int)$db->query('SELECT COALESCE(MAX(active_generation), 0) FROM pipeline_config')->fetchColumn();
			$generation = max($maximum, $active) + 1;
			$statement = $db->prepare('INSERT INTO candidate_generations(generation, query_fingerprint, started_at) VALUES (?, ?, ?)');
			$statement->execute([$generation, $queryFingerprint, $now]);
			return $generation;
		});
	}

	/** @param list<array{entry_id:string,feed_id:string,received_at:int,embedding_text:string,source_hash:string,exported_at:int}> $rows */
	public function writeCandidateBatch(PDO $pdo, int $generation, array $rows): void {
		self::transaction($pdo, static function (PDO $db) use ($generation, $rows): void {
			$input = $db->prepare('INSERT OR IGNORE INTO article_inputs(entry_id, feed_id, received_at, embedding_text, source_hash, exported_at) VALUES (?, ?, ?, ?, ?, ?)');
			$member = $db->prepare('INSERT INTO candidate_members(generation, entry_id, source_hash) VALUES (?, ?, ?)');
			foreach ($rows as $row) {
				$input->execute([$row['entry_id'], $row['feed_id'], $row['received_at'], $row['embedding_text'], $row['source_hash'], $row['exported_at']]);
				$member->execute([$generation, $row['entry_id'], $row['source_hash']]);
			}
		});
	}

	/** @param array<string,mixed> $effectiveConfig */
	public function activateGeneration(PDO $pdo, int $generation, int $candidateCount, string $revision, array $effectiveConfig, int $leaseUntil, int $now): void {
		self::transaction($pdo, static function (PDO $db) use ($generation, $candidateCount, $revision, $effectiveConfig, $leaseUntil, $now): void {
			$complete = $db->prepare('UPDATE candidate_generations SET completed_at = ?, candidate_count = ? WHERE generation = ? AND completed_at IS NULL');
			$complete->execute([$now, $candidateCount, $generation]);
			if ($complete->rowCount() !== 1) {
				throw new RuntimeException('Candidate generation could not be completed.');
			}
			$config = $db->prepare('INSERT INTO pipeline_config(singleton, database_schema_version, config_revision, active_generation, producer_lease_until, config_json, updated_at)
				VALUES (1, ?, ?, ?, ?, ?, ?)
				ON CONFLICT(singleton) DO UPDATE SET database_schema_version=excluded.database_schema_version, config_revision=excluded.config_revision,
				active_generation=excluded.active_generation, producer_lease_until=excluded.producer_lease_until, config_json=excluded.config_json, updated_at=excluded.updated_at');
			$config->execute([self::SCHEMA_VERSION, $revision, $generation, $leaseUntil, SemanticGrouping_Config::canonicalJson($effectiveConfig), $now]);
			self::setState($db, 'export_state', 'last_export_success', (string)$now);
			self::setState($db, 'export_state', 'last_export_count', (string)$candidateCount);
			self::setState($db, 'export_state', 'last_export_error', '');
		});
	}

	public function recordExportState(PDO $pdo, string $key, string $value): void {
		self::transaction($pdo, static fn(PDO $db) => self::setState($db, 'export_state', $key, $value));
	}

	private static function setState(PDO $pdo, string $table, string $key, string $value): void {
		if (!in_array($table, ['worker_state', 'export_state'], true)) {
			throw new InvalidArgumentException('Invalid state table.');
		}
		$statement = $pdo->prepare("INSERT INTO {$table}(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
		$statement->execute([$key, $value]);
	}

	/** @param array<string,mixed> $config */
	public function publishDisabled(array $config, ?int $now = null): void {
		$timestamp = $now ?? time();
		$effective = SemanticGrouping_Config::effectiveWorkerConfig($config, 'disabled');
		$effective['enabled'] = false;
		$revision = SemanticGrouping_Config::fingerprint($effective);
		$pdo = $this->open(false);
		self::transaction($pdo, static function (PDO $db) use ($effective, $revision, $timestamp): void {
			$active = (int)$db->query('SELECT COALESCE(MAX(active_generation), 0) FROM pipeline_config')->fetchColumn();
			$statement = $db->prepare('INSERT INTO pipeline_config(singleton, database_schema_version, config_revision, active_generation, producer_lease_until, config_json, updated_at)
				VALUES (1, ?, ?, ?, ?, ?, ?)
				ON CONFLICT(singleton) DO UPDATE SET config_revision=excluded.config_revision, producer_lease_until=excluded.producer_lease_until,
				config_json=excluded.config_json, updated_at=excluded.updated_at');
			$statement->execute([self::SCHEMA_VERSION, $revision, $active, $timestamp, SemanticGrouping_Config::canonicalJson($effective), $timestamp]);
		});
	}

	public function prune(PDO $pdo, int $activeGeneration): void {
		self::transaction($pdo, static function (PDO $db) use ($activeGeneration): void {
			$published = (int)($db->query("SELECT value FROM worker_state WHERE key='last_published_generation'")->fetchColumn() ?: 0);
			$keep = array_values(array_unique(array_filter([$activeGeneration, $published], static fn(int $generation): bool => $generation > 0)));
			if ($keep === []) {
				return;
			}
			$placeholders = implode(',', array_fill(0, count($keep), '?'));
			$statement = $db->prepare("DELETE FROM candidate_generations WHERE generation NOT IN ({$placeholders})");
			$statement->execute($keep);
			$db->exec('DELETE FROM article_inputs WHERE NOT EXISTS (SELECT 1 FROM candidate_members cm WHERE cm.entry_id=article_inputs.entry_id AND cm.source_hash=article_inputs.source_hash)');
		});
	}

	/** @param null|callable():void $afterReset */
	public function fullReset(?callable $afterReset = null): void {
		$lockPath = dirname($this->path) . '/.semantic-worker.lock';
		$lock = fopen($lockPath, 'c');
		if ($lock === false) {
			throw new RuntimeException('The semantic worker lock cannot be opened.');
		}
		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);
			throw new RuntimeException('The semantic worker is active; reset was not started.');
		}
		@chmod($lockPath, 0660);
		try {
			if (!is_dir(dirname($this->path))) {
				throw new RuntimeException('Semantic data directory is missing.');
			}
			$pdo = $this->connect(false);
			$pdo->exec('PRAGMA foreign_keys = OFF');
			try {
				self::transaction($pdo, function (PDO $db): void {
					foreach (['view', 'table'] as $type) {
						$objects = $db->query("SELECT name FROM sqlite_master WHERE type='{$type}' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
						foreach ($objects as $name) {
							$identifier = '"' . str_replace('"', '""', (string)$name) . '"';
							$db->exec('DROP ' . strtoupper($type) . ' IF EXISTS ' . $identifier);
						}
					}
					foreach (self::schemaStatements() as $sql) {
						$db->exec($sql);
					}
					$db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
				});
			} finally {
				$pdo->exec('PRAGMA foreign_keys = ON');
			}
			@chmod($this->path, 0660);
			unset($pdo);
			if ($afterReset !== null) {
				$afterReset();
			}
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
