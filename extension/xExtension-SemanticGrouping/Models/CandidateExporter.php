<?php
declare(strict_types=1);

final class SemanticGrouping_CandidateExporter {
	private const BATCH_SIZE = 200;

	/** @param array<string,mixed> $config */
	public function __construct(
		private array $config,
		private readonly SemanticGrouping_SemanticDatabase $database = new SemanticGrouping_SemanticDatabase(),
	) {}

	public function runSafely(bool $force = false): void {
		try {
			$this->export($force);
		} catch (Throwable $error) {
			Minz_Log::error('Semantic candidate export deferred: ' . self::safeError($error));
		}
	}

	public function export(bool $force = false): bool {
		$configErrors = SemanticGrouping_Config::validate($this->config);
		if ($configErrors !== []) {
			if (!empty($this->config['enabled'])) {
				throw new InvalidArgumentException(implode(' ', $configErrors));
			}
			// Disabling must remain fail-safe even if an older stored
			// configuration can no longer be validated by this release.
			$this->config = SemanticGrouping_Config::defaults();
			$this->config['enabled'] = false;
		}
		$directory = dirname($this->database->path);
		if (!is_dir($directory)) {
			throw new RuntimeException('Semantic data directory is missing.');
		}
		$user = Minz_User::name() ?? '_';
		$lockPath = $directory . '/.semantic-export-' . substr(hash('sha256', $user), 0, 16) . '.lock';
		$lock = fopen($lockPath, 'c');
		if ($lock === false) {
			throw new RuntimeException('Candidate exporter lock cannot be opened.');
		}
		@chmod($lockPath, 0660);
		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);
			return false;
		}

		try {
			if (empty($this->config['enabled'])) {
				$this->database->publishDisabled($this->config);
				return false;
			}
			return $this->exportLocked($force);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	public function fullResetAndExport(): bool {
		$configErrors = SemanticGrouping_Config::validate($this->config);
		if ($configErrors !== [] || empty($this->config['enabled'])) {
			throw new InvalidArgumentException($configErrors === [] ? 'Enable the pipeline before resetting it.' : implode(' ', $configErrors));
		}
		$directory = dirname($this->database->path);
		if (!is_dir($directory)) {
			throw new RuntimeException('Semantic data directory is missing.');
		}
		$user = Minz_User::name() ?? '_';
		$lockPath = $directory . '/.semantic-export-' . substr(hash('sha256', $user), 0, 16) . '.lock';
		$lock = fopen($lockPath, 'c');
		if ($lock === false) {
			throw new RuntimeException('Candidate exporter lock cannot be opened.');
		}
		@chmod($lockPath, 0660);
		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);
			throw new RuntimeException('A candidate export is active; reset was not started.');
		}
		try {
			$exported = false;
			$this->database->fullReset(function () use (&$exported): void {
				$exported = $this->exportLocked(true);
			});
			return $exported;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	private function exportLocked(bool $force): bool {
		$pdo = $this->database->open(false);
		$now = time();
		$this->database->recordExportState($pdo, 'last_export_attempt', (string)$now);
		$generation = null;
		try {
			$source = SemanticGrouping_CandidateSource::resolve($this->config, $now);
			$effective = SemanticGrouping_Config::effectiveWorkerConfig($this->config, $source->fingerprint);
			$revision = SemanticGrouping_Config::fingerprint($effective);
			$current = $pdo->query('SELECT config_revision FROM pipeline_config WHERE singleton=1')->fetchColumn();
			$lastSuccess = (int)($pdo->query("SELECT value FROM export_state WHERE key='last_export_success'")->fetchColumn() ?: 0);
			$interval = (int)$this->config['candidate_export_interval_minutes'] * 60;
			if (!$force && $current === $revision && $lastSuccess > 0 && $now - $lastSuccess < $interval) {
				return false;
			}

			$generation = $this->database->allocateGeneration($pdo, $source->fingerprint, $now);
			$count = 0;
			$batch = [];
			foreach ($source->entries() as $entry) {
				$title = $entry->title();
				[$embeddingText, $sourceHash] = SemanticGrouping_TextNormalizer::embeddingInput(
					$title,
					$entry->content(false),
					$this->config,
				);
				$batch[] = [
					'entry_id' => $entry->id(),
					'feed_id' => (string)$entry->feedId(),
					'received_at' => self::receivedAt($entry),
					'embedding_text' => $embeddingText,
					'source_hash' => $sourceHash,
					'normalized_title' => SemanticGrouping_TextNormalizer::title($title),
					'exported_at' => $now,
				];
				$count++;
				if (count($batch) >= self::BATCH_SIZE) {
					$this->database->writeCandidateBatch($pdo, $generation, $batch);
					$batch = [];
				}
			}
			if ($batch !== []) {
				$this->database->writeCandidateBatch($pdo, $generation, $batch);
			}
			$leaseMinutes = max((int)$this->config['candidate_export_interval_minutes'] * 3, 90);
			$this->database->activateGeneration($pdo, $generation, $count, $revision, $effective, $now + $leaseMinutes * 60, $now);
			try {
				$this->database->prune($pdo, $generation);
			} catch (Throwable $pruneError) {
				Minz_Log::warning('Semantic candidate cleanup deferred: ' . self::safeError($pruneError));
			}
			return true;
		} catch (Throwable $error) {
			try {
				$this->database->recordExportState($pdo, 'last_export_error', self::safeError($error));
				$this->database->recordExportState($pdo, 'last_export_error_at', (string)$now);
			} catch (Throwable) {
				// The feed maintenance hook must not inherit a status-write failure.
			}
			throw $error;
		}
	}

	private static function receivedAt(FreshRSS_Entry $entry): int {
		try {
			return (int)$entry->dateAdded(raw: true);
		} catch (Throwable) {
			$id = $entry->id();
			return ctype_digit($id) && strlen($id) > 6 ? (int)substr($id, 0, -6) : (int)$entry->date(raw: true);
		}
	}

	private static function safeError(Throwable $error): string {
		$message = preg_replace('~(?:[A-Za-z]:)?[/\\\\][^\s:]+~', '<path>', $error->getMessage());
		return mb_substr(get_class($error) . ': ' . (is_string($message) ? $message : 'operation failed'), 0, 500, 'UTF-8');
	}
}
