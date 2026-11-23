<?php
declare(strict_types=1);

require_once __DIR__ . '/SemanticLock.php';

/**
 * The shared semantic database: SQLite at the fixed path by default, or
 * PostgreSQL when the operator sets SEMANTIC_DATABASE_URL for both FreshRSS
 * and the worker. No browser parameter can select either.
 */
final class SemanticGrouping_SemanticDatabase {
	public const PATH = '/semantic-data/semantic.sqlite';
	public const URL_ENVIRONMENT = 'SEMANTIC_DATABASE_URL';
	public const SCHEMA_VERSION = 4;
	public const WORKER_LOCK = 'semantic-worker';
	/** Serializes PostgreSQL writers the way SQLite's BEGIN IMMEDIATE does. */
	private const WRITE_LOCK = 'semantic-write';
	/** Every table this release owns, in a safe drop order. */
	private const TABLES = [
		'topic_members', 'topics', 'group_members', 'groups', 'embeddings', 'worker_state',
		'candidate_members', 'article_inputs', 'candidate_generations',
		'pipeline_config', 'export_state', 'managed_labels', 'label_sync_state',
		'schema_version',
	];

	/** PostgreSQL connection URL, or '' for SQLite at $path. */
	public readonly string $url;

	/** @param null|string $url null reads SEMANTIC_DATABASE_URL; '' forces SQLite. */
	public function __construct(public readonly string $path = self::PATH, ?string $url = null) {
		if ($url === null) {
			$environment = getenv(self::URL_ENVIRONMENT);
			$url = is_string($environment) ? $environment : '';
		}
		$this->url = trim($url);
	}

	public function isPostgres(): bool {
		return $this->url !== '';
	}

	public function open(bool $migrate = false, bool $queryOnly = false): PDO {
		if ($this->isPostgres()) {
			$pdo = $this->connect($queryOnly);
			if ($migrate) {
				$this->migrate($pdo);
			} else {
				$version = self::schemaVersion($pdo);
				if ($version === 0) {
					throw new RuntimeException('Semantic database has not been created.');
				}
				if ($version !== self::SCHEMA_VERSION) {
					throw new RuntimeException('Semantic database schema is incompatible.');
				}
			}
			return $pdo;
		}
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
			$version = self::schemaVersion($pdo);
			if ($version !== self::SCHEMA_VERSION) {
				throw new RuntimeException('Semantic database schema is incompatible.');
			}
		}
		return $pdo;
	}

	private function connect(bool $queryOnly): PDO {
		if ($this->isPostgres()) {
			[$dsn, $user, $password] = self::postgresDsn($this->url);
			$pdo = new PDO($dsn, $user, $password, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);
			// The same five-second bound SQLite's busy timeout gives writers.
			$pdo->exec("SET lock_timeout = '5s'");
			if ($queryOnly) {
				$pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
			}
			return $pdo;
		}
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

	/**
	 * Converts a postgresql:// URL into a PDO DSN. Credentials go to PDO
	 * separately; libpq's PG* variables (PGPASSWORD, PGPASSFILE) fill in
	 * anything the URL leaves out.
	 * @return array{string,?string,?string}
	 */
	public static function postgresDsn(string $url): array {
		// parse_url() rejects an empty host, which libpq reads as its default socket.
		$socket = preg_match('~^[a-z]+:///~i', $url) === 1;
		$parts = parse_url($socket ? (string)preg_replace('~^([a-z]+)://~i', '$1://socket', $url, 1) : $url);
		if (is_array($parts) && $socket) {
			unset($parts['host']);
		}
		if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['postgres', 'postgresql'], true)) {
			throw new InvalidArgumentException(self::URL_ENVIRONMENT . ' must be a postgresql:// URL.');
		}
		$keywords = ['connect_timeout' => '5'];
		if (($parts['host'] ?? '') !== '') {
			$keywords['host'] = rawurldecode(trim($parts['host'], '[]'));
		}
		if (isset($parts['port'])) {
			$keywords['port'] = (string)$parts['port'];
		}
		$database = rawurldecode(ltrim($parts['path'] ?? '', '/'));
		if ($database !== '') {
			$keywords['dbname'] = $database;
		}
		parse_str($parts['query'] ?? '', $query);
		foreach ($query as $key => $value) {
			if (!is_string($key) || preg_match('/^[a-z_]+$/', $key) !== 1 || !is_string($value)) {
				throw new InvalidArgumentException(self::URL_ENVIRONMENT . ' has an invalid query parameter.');
			}
			$keywords[$key] = $value;
		}
		$pairs = [];
		foreach ($keywords as $key => $value) {
			if (str_contains($value, ';')) {
				// PDO turns every semicolon in a DSN into a separator.
				throw new InvalidArgumentException(self::URL_ENVIRONMENT . ' may not contain semicolons.');
			}
			$pairs[] = $key . "='" . addcslashes($value, "'\\") . "'";
		}
		$user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
		$password = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
		return ['pgsql:' . implode(' ', $pairs), $user, $password];
	}

	private static function driver(PDO $pdo): string {
		return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
	}

	/** Shared with the worker, which derives the same key from the same name. */
	public static function lockKey(string $name): int {
		return (int)hexdec(substr(hash('sha256', 'freshrss-semantic-lock:' . $name), 0, 15));
	}

	/** A per-FreshRSS-user lock name, e.g. semantic-export-<hash>. */
	public static function userLockName(string $prefix, string $user): string {
		return $prefix . '-' . substr(hash('sha256', $user), 0, 16);
	}

	/**
	 * Takes an exclusive lock without waiting. Returns null while another
	 * process holds it, and throws when the storage cannot be reached.
	 */
	public function tryLock(string $name): ?SemanticGrouping_SemanticLock {
		if ($this->isPostgres()) {
			$pdo = $this->connect(false);
			$key = self::lockKey($name);
			$statement = $pdo->prepare('SELECT pg_try_advisory_lock(?)');
			$statement->execute([$key]);
			return $statement->fetchColumn() === true ? SemanticGrouping_SemanticLock::advisory($pdo, $key) : null;
		}
		$directory = dirname($this->path);
		if (!is_dir($directory)) {
			throw new RuntimeException('Semantic data directory is missing.');
		}
		$lockPath = $directory . '/.' . $name . '.lock';
		$lock = fopen($lockPath, 'c');
		if ($lock === false) {
			throw new RuntimeException('Semantic lock cannot be opened.');
		}
		@chmod($lockPath, 0660);
		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);
			return null;
		}
		return SemanticGrouping_SemanticLock::file($lock);
	}

	/** 0 when no schema has been created. */
	public static function schemaVersion(PDO $pdo): int {
		if (self::driver($pdo) !== 'pgsql') {
			return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
		}
		$table = $pdo->query("SELECT to_regclass(quote_ident(current_schema()) || '.schema_version')")->fetchColumn();
		if ($table === null || $table === false) {
			return 0;
		}
		return (int)$pdo->query('SELECT version FROM schema_version WHERE singleton = 1')->fetchColumn();
	}

	public function migrate(PDO $pdo): void {
		if (self::driver($pdo) === 'pgsql') {
			$this->migratePostgres($pdo);
			return;
		}
		// FreshRSS and the migration CLI may both start a migration. The version
		// is read only after BEGIN IMMEDIATE takes the write lock, so a second
		// migrator waits for the first and then finds the current version.
		$migrated = self::transaction($pdo, static function (PDO $db): bool {
			$current = (int)$db->query('PRAGMA user_version')->fetchColumn();
			if ($current > self::SCHEMA_VERSION) {
				throw new RuntimeException('Semantic database belongs to a newer release.');
			}
			if ($current === self::SCHEMA_VERSION) {
				return false;
			}
			if (!in_array($current, [0, 1, 2, 3], true)) {
				throw new RuntimeException('No migration path exists for this semantic database.');
			}
			$statements = $current === 0 ? self::schemaStatements() : [];
			if ($current === 1) {
				$statements = array_merge($statements, self::labelSchemaStatements());
			}
			if ($current === 1 || $current === 2) {
				$statements[] = "ALTER TABLE candidate_members ADD COLUMN normalized_title TEXT NOT NULL DEFAULT ''";
			}
			if ($current > 0) {
				$statements = array_merge($statements, self::topicSchemaStatements(false));
			}
			foreach ($statements as $sql) {
				$db->exec($sql);
			}
			if ($current > 0) {
				$db->exec('UPDATE pipeline_config SET database_schema_version = ' . self::SCHEMA_VERSION . ', producer_lease_until = 0');
				$db->exec("DELETE FROM export_state WHERE key = 'last_export_success'");
			}
			$db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
			return true;
		});
		if ($migrated) {
			@chmod($this->path, 0660);
		}
	}

	/** PostgreSQL databases start at schema 3 or later; 3 is the oldest to upgrade. */
	private function migratePostgres(PDO $pdo): void {
		$pdo->beginTransaction();
		try {
			// FreshRSS and the migration CLI may both start a migration.
			$pdo->prepare('SELECT pg_advisory_xact_lock(?)')->execute([self::lockKey(self::WRITE_LOCK)]);
			$current = self::schemaVersion($pdo);
			if ($current > self::SCHEMA_VERSION) {
				throw new RuntimeException('Semantic database belongs to a newer release.');
			}
			if ($current === 3) {
				foreach (self::topicSchemaStatements(true) as $sql) {
					$pdo->exec($sql);
				}
				$pdo->exec('UPDATE schema_version SET version = ' . self::SCHEMA_VERSION . ' WHERE singleton = 1');
				$pdo->exec('UPDATE pipeline_config SET database_schema_version = ' . self::SCHEMA_VERSION . ', producer_lease_until = 0');
				$pdo->exec("DELETE FROM export_state WHERE key = 'last_export_success'");
			} elseif ($current !== self::SCHEMA_VERSION) {
				if ($current !== 0 || self::postgresTablesExist($pdo)) {
					throw new RuntimeException('No migration path exists for this semantic database.');
				}
				foreach (self::postgresSchemaStatements() as $sql) {
					$pdo->exec($sql);
				}
			}
			$pdo->commit();
		} catch (Throwable $error) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw $error;
		}
	}

	private static function postgresTablesExist(PDO $pdo): bool {
		$placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
		$statement = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN ({$placeholders})");
		$statement->execute(self::TABLES);
		return (int)$statement->fetchColumn() > 0;
	}

	/**
	 * The schema in fixtures/semantic-schema-v4.postgres.sql: the SQLite
	 * contract with BIGINT integers, BYTEA embeddings and a version row.
	 * @return list<string>
	 */
	private static function postgresSchemaStatements(): array {
		return [
			'CREATE TABLE schema_version (
				singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
				version INTEGER NOT NULL
			)',
			'CREATE TABLE pipeline_config (
				singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
				database_schema_version INTEGER NOT NULL,
				config_revision TEXT NOT NULL,
				active_generation BIGINT NOT NULL,
				producer_lease_until BIGINT NOT NULL,
				config_json TEXT NOT NULL,
				updated_at BIGINT NOT NULL
			)',
			'CREATE TABLE candidate_generations (
				generation BIGINT PRIMARY KEY,
				query_fingerprint TEXT NOT NULL,
				started_at BIGINT NOT NULL,
				completed_at BIGINT,
				candidate_count BIGINT
			)',
			'CREATE TABLE article_inputs (
				entry_id TEXT NOT NULL,
				feed_id TEXT NOT NULL,
				received_at BIGINT NOT NULL,
				embedding_text TEXT NOT NULL,
				source_hash TEXT NOT NULL,
				exported_at BIGINT NOT NULL,
				PRIMARY KEY (entry_id, source_hash)
			)',
			'CREATE TABLE candidate_members (
				generation BIGINT NOT NULL,
				entry_id TEXT NOT NULL,
				source_hash TEXT NOT NULL,
				normalized_title TEXT NOT NULL DEFAULT \'\',
				PRIMARY KEY (generation, entry_id),
				FOREIGN KEY (generation) REFERENCES candidate_generations(generation) ON DELETE CASCADE,
				FOREIGN KEY (entry_id, source_hash) REFERENCES article_inputs(entry_id, source_hash)
			)',
			'CREATE INDEX candidate_members_article ON candidate_members (entry_id, source_hash)',
			'CREATE TABLE embeddings (
				entry_id TEXT PRIMARY KEY,
				source_hash TEXT NOT NULL,
				embedding_fingerprint TEXT NOT NULL,
				model_id TEXT NOT NULL,
				dimensions INTEGER NOT NULL CHECK (dimensions > 0),
				embedding BYTEA NOT NULL,
				embedded_at BIGINT NOT NULL
			)',
			'CREATE TABLE groups (
				group_id TEXT PRIMARY KEY,
				representative_entry_id TEXT NOT NULL,
				selection_generation BIGINT NOT NULL,
				grouping_fingerprint TEXT NOT NULL,
				generated_at BIGINT NOT NULL
			)',
			'CREATE TABLE group_members (
				group_id TEXT NOT NULL,
				entry_id TEXT NOT NULL UNIQUE,
				similarity DOUBLE PRECISION,
				PRIMARY KEY (group_id, entry_id),
				FOREIGN KEY (group_id) REFERENCES groups(group_id) ON DELETE CASCADE
			)',
			'CREATE TABLE worker_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
			'CREATE TABLE export_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
			'CREATE TABLE managed_labels (
				semantic_key TEXT PRIMARY KEY,
				label_id BIGINT NOT NULL UNIQUE,
				label_name TEXT NOT NULL,
				selection_generation BIGINT NOT NULL,
				updated_at BIGINT NOT NULL
			)',
			'CREATE TABLE label_sync_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
			...self::topicSchemaStatements(true),
			'INSERT INTO schema_version (singleton, version) VALUES (1, ' . self::SCHEMA_VERSION . ')',
		];
	}

	/** @return list<string> */
	private static function schemaStatements(): array {
		return array_merge([
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
				normalized_title TEXT NOT NULL DEFAULT \'\',
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
		], self::labelSchemaStatements(), self::topicSchemaStatements(false));
	}

	/**
	 * Schema 4: topics, worker-owned like groups. A topic's members are
	 * articles, both those in its stories (groups) and related articles in
	 * no group.
	 * @return list<string>
	 */
	private static function topicSchemaStatements(bool $postgres): array {
		$integer = $postgres ? 'BIGINT' : 'INTEGER';
		return [
			"CREATE TABLE topics (
				topic_id TEXT PRIMARY KEY,
				representative_entry_id TEXT NOT NULL,
				selection_generation {$integer} NOT NULL,
				grouping_fingerprint TEXT NOT NULL,
				generated_at {$integer} NOT NULL
			)",
			'CREATE TABLE topic_members (
				topic_id TEXT NOT NULL,
				entry_id TEXT NOT NULL UNIQUE,
				PRIMARY KEY (topic_id, entry_id),
				FOREIGN KEY (topic_id) REFERENCES topics(topic_id) ON DELETE CASCADE
			)' . ($postgres ? '' : ' WITHOUT ROWID'),
		];
	}

	/** @return list<string> */
	private static function labelSchemaStatements(): array {
		return [
			'CREATE TABLE managed_labels (
				semantic_key TEXT PRIMARY KEY,
				label_id INTEGER NOT NULL UNIQUE,
				label_name TEXT NOT NULL,
				selection_generation INTEGER NOT NULL,
				updated_at INTEGER NOT NULL
			) WITHOUT ROWID',
			'CREATE TABLE label_sync_state (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID',
		];
	}

	/** @param callable(PDO):mixed $callback @return mixed */
	public static function transaction(PDO $pdo, callable $callback) {
		if (self::driver($pdo) === 'pgsql') {
			// One writer at a time, like SQLite: compare-before-publish checks
			// in either component stay valid until their commit. Readers are
			// not blocked, and lock_timeout bounds the wait.
			$pdo->exec('BEGIN');
			try {
				$pdo->prepare('SELECT pg_advisory_xact_lock(?)')->execute([self::lockKey(self::WRITE_LOCK)]);
			} catch (Throwable $error) {
				$pdo->exec('ROLLBACK');
				throw $error;
			}
		} else {
			$pdo->exec('BEGIN IMMEDIATE');
		}
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

	/** Starts a read-only transaction that sees one consistent snapshot. */
	public static function beginRead(PDO $pdo): void {
		$pdo->beginTransaction();
		if (self::driver($pdo) === 'pgsql') {
			$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
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

	/** @param list<array{entry_id:string,feed_id:string,received_at:int,embedding_text:string,source_hash:string,normalized_title:string,exported_at:int}> $rows */
	public function writeCandidateBatch(PDO $pdo, int $generation, array $rows): void {
		self::transaction($pdo, static function (PDO $db) use ($generation, $rows): void {
			$input = $db->prepare('INSERT INTO article_inputs(entry_id, feed_id, received_at, embedding_text, source_hash, exported_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING');
			$member = $db->prepare('INSERT INTO candidate_members(generation, entry_id, source_hash, normalized_title) VALUES (?, ?, ?, ?)');
			foreach ($rows as $row) {
				$input->execute([$row['entry_id'], $row['feed_id'], $row['received_at'], $row['embedding_text'], $row['source_hash'], $row['exported_at']]);
				$member->execute([$generation, $row['entry_id'], $row['source_hash'], $row['normalized_title']]);
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
		$lock = $this->tryLock(self::WORKER_LOCK);
		if ($lock === null) {
			throw new RuntimeException('The semantic worker is active; reset was not started.');
		}
		try {
			$pdo = $this->connect(false);
			if ($this->isPostgres()) {
				// Only this release's tables: the database may hold nothing else
				// of ours, and anything else in it is not ours to drop.
				self::transaction($pdo, static function (PDO $db): void {
					$db->exec('DROP TABLE IF EXISTS ' . implode(', ', self::TABLES) . ' CASCADE');
					foreach (self::postgresSchemaStatements() as $sql) {
						$db->exec($sql);
					}
				});
			} else {
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
			}
			unset($pdo);
			if ($afterReset !== null) {
				$afterReset();
			}
		} finally {
			$lock->release();
		}
	}
}
