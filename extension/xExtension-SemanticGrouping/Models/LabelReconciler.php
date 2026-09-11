<?php
declare(strict_types=1);

final class SemanticGrouping_LabelReconciler {
	public const OWNER_ATTRIBUTE = 'semantic_grouping';
	public const OWNER_ID = 'xExtension-SemanticGrouping';
	public const SINGLETON_KEY = 'single-articles';
	public const SINGLETON_NAME = 'Single articles';

	private object $tagDao;
	private object $entryDao;

	public function __construct(
		private readonly SemanticGrouping_SemanticDatabase $database = new SemanticGrouping_SemanticDatabase(),
		?object $tagDao = null,
		?object $entryDao = null,
	) {
		$this->tagDao = $tagDao ?? FreshRSS_Factory::createTagDao();
		$this->entryDao = $entryDao ?? FreshRSS_Factory::createEntryDao();
	}

	public function runSafely(): void {
		try {
			$this->reconcile();
		} catch (Throwable $error) {
			$this->recordError($error);
			Minz_Log::error('Semantic label synchronization deferred: ' . self::safeError($error));
		}
	}

	public function removeManagedLabelsSafely(): void {
		try {
			$this->removeManagedLabels();
		} catch (Throwable $error) {
			$this->recordError($error);
			Minz_Log::error('Semantic label cleanup deferred: ' . self::safeError($error));
		}
	}

	public function reconcile(): bool {
		return $this->withLocks(function (): bool {
			$snapshot = $this->publishedSnapshot();
			if ($snapshot === null) {
				return false;
			}

			$generation = $snapshot['generation'];
			$groups = $snapshot['groups'];
			$candidateIds = $snapshot['candidate_ids'];
			$entries = [];
			foreach ($this->entryDao->listByIds($candidateIds, 'ASC') as $entry) {
				$entries[(string)$entry->id()] = $entry;
			}

			$errors = [];
			$targets = [];
			$groupedIds = [];
			$frozenEntries = [];
			$unpublishedGroupMembers = [];
			foreach ($groups as $group) {
				$key = (string)$group['group_id'];
				$representativeId = (string)$group['representative_entry_id'];
				$members = $group['members'];
				foreach ($members as $entryId) {
					$groupedIds[$entryId] = true;
				}
				if (count($members) < SemanticGrouping_Config::MINIMUM_GROUP_SIZE) {
					$errors[$key] = "Published semantic group {$key} has fewer than two members.";
					foreach ($members as $entryId) {
						$unpublishedGroupMembers[$entryId] = true;
					}
					continue;
				}
				$missingMembers = array_values(array_filter($members, static fn(string $entryId): bool => !isset($entries[$entryId])));
				if ($missingMembers !== []) {
					$errors[$key] = 'Semantic group ' . $key . ' contains unavailable articles: ' . implode(', ', $missingMembers) . '.';
					foreach ($members as $entryId) {
						$unpublishedGroupMembers[$entryId] = true;
					}
					continue;
				}
				if (!isset($entries[$representativeId])) {
					$errors[$key] = "Representative article {$representativeId} is no longer available.";
					foreach ($members as $entryId) {
						$unpublishedGroupMembers[$entryId] = true;
					}
					continue;
				}
				$title = (string)$entries[$representativeId]->title();
				if (!self::isExactNativeName($title)) {
					$errors[$key] = "Representative article {$representativeId} cannot be used as an exact FreshRSS label name.";
					foreach ($members as $entryId) {
						$unpublishedGroupMembers[$entryId] = true;
					}
					continue;
				}
				$targets[$key] = ['name' => $title, 'members' => $members];
			}

			$singletonMembers = [];
			foreach ($candidateIds as $entryId) {
				if (!isset($groupedIds[$entryId]) && isset($entries[$entryId])) {
					$singletonMembers[] = $entryId;
				} elseif (!isset($groupedIds[$entryId], $entries[$entryId])) {
					$errors['missing-' . $entryId] = "Candidate article {$entryId} is no longer available.";
					$frozenEntries[$entryId] = true;
				}
			}
			$targets[self::SINGLETON_KEY] = ['name' => self::SINGLETON_NAME, 'members' => $singletonMembers];

			$tags = $this->nativeTags();
			$ownedByKey = [];
			$ownedById = [];
			$personalByName = [];
			foreach ($tags as $tag) {
				$key = self::ownedKey($tag);
				$id = (int)$tag['id'];
				if ($key === null) {
					$personalByName[(string)$tag['name']] = true;
					continue;
				}
				$ownedById[$id] = $tag;
				$ownedByKey[$key][] = $tag;
			}

			$nameKeys = [];
			foreach ($targets as $key => $target) {
				$nameKeys[$target['name']][] = $key;
			}
			foreach ($nameKeys as $name => $keys) {
				if (count($keys) > 1) {
					foreach ($keys as $key) {
						$errors[$key] = "More than one semantic label requires the name {$name}.";
					}
				}
			}
			foreach ($targets as $key => $target) {
				if (isset($personalByName[$target['name']])) {
					$errors[$key] = "A personal label already uses the name {$target['name']}.";
				}
			}
			if (!isset($errors[self::SINGLETON_KEY])) {
				foreach ($errors as $key => $_error) {
					if ($key !== self::SINGLETON_KEY && isset($targets[$key])) {
						foreach ($targets[$key]['members'] as $entryId) {
							$unpublishedGroupMembers[$entryId] = true;
						}
					}
				}
				$targets[self::SINGLETON_KEY]['members'] = array_values(array_unique(array_merge(
					$targets[self::SINGLETON_KEY]['members'],
					array_keys($unpublishedGroupMembers),
				)));
			}

			$currentAssignments = [];
			foreach ($this->tagDao->selectEntryTag() as $row) {
				$labelId = (int)$row['id_tag'];
				if (isset($ownedById[$labelId])) {
					$currentAssignments[(string)$row['id_entry']][$labelId] = true;
				}
			}

			$desiredByEntry = [];
			$successfulLabelIds = [];
			foreach ($targets as $key => $target) {
				if (isset($errors[$key])) {
					if ($key === self::SINGLETON_KEY || isset($errors[self::SINGLETON_KEY])) {
						foreach ($target['members'] as $entryId) {
							$frozenEntries[$entryId] = true;
						}
					}
					continue;
				}

				$label = $this->selectOwnedLabel($key, $ownedByKey[$key] ?? []);
				try {
					if ($label === null) {
						$labelId = $this->tagDao->addTag([
							'name' => $target['name'],
							'attributes' => self::ownershipAttributes($key),
						]);
						if (!is_int($labelId) || $labelId <= 0) {
							throw new RuntimeException("FreshRSS could not create semantic label {$target['name']}.");
						}
						$label = [
							'id' => $labelId,
							'name' => $target['name'],
							'attributes' => self::ownershipAttributes($key),
						];
						$ownedById[$labelId] = $label;
						$ownedByKey[$key][] = $label;
					} else {
						$labelId = (int)$label['id'];
						if ((string)$label['name'] !== $target['name']
								&& $this->tagDao->updateTagName($labelId, $target['name']) === false) {
							throw new RuntimeException("FreshRSS could not rename semantic label {$labelId}.");
						}
						if ($this->tagDao->updateTagAttributes($labelId, self::ownershipAttributes($key)) === false) {
							throw new RuntimeException("FreshRSS could not persist ownership for semantic label {$labelId}.");
						}
					}

					$added = [];
					foreach ($target['members'] as $entryId) {
						if (!isset($currentAssignments[$entryId][$labelId])) {
							if (!$this->tagDao->tagEntry($labelId, (string)$entryId)) {
								foreach ($added as $addedId) {
									$this->tagDao->tagEntry($labelId, (string)$addedId, false);
									unset($currentAssignments[$addedId][$labelId]);
								}
								throw new RuntimeException("FreshRSS could not assign semantic label {$labelId} to article {$entryId}.");
							}
							$added[] = $entryId;
							$currentAssignments[$entryId][$labelId] = true;
						}
					}
					$successfulLabelIds[$labelId] = true;
					foreach ($target['members'] as $entryId) {
						$desiredByEntry[$entryId] = $labelId;
					}
				} catch (Throwable $error) {
					$errors[$key] = self::safeError($error);
					if ($key !== self::SINGLETON_KEY && !isset($errors[self::SINGLETON_KEY])) {
						$targets[self::SINGLETON_KEY]['members'] = array_values(array_unique(array_merge(
							$targets[self::SINGLETON_KEY]['members'],
							$target['members'],
						)));
					} else {
						foreach ($target['members'] as $entryId) {
							$frozenEntries[$entryId] = true;
						}
					}
				}
			}

			foreach ($currentAssignments as $entryId => $labelIds) {
				if (isset($frozenEntries[$entryId])) {
					continue;
				}
				$desiredLabelId = $desiredByEntry[$entryId] ?? null;
				foreach (array_keys($labelIds) as $labelId) {
					if ($labelId !== $desiredLabelId && !$this->tagDao->tagEntry($labelId, (string)$entryId, false)) {
						$errors['assignment-' . $entryId . '-' . $labelId] = "FreshRSS could not remove semantic label {$labelId} from article {$entryId}.";
					}
				}
			}

			$keepLabelIds = $successfulLabelIds;
			foreach (array_keys($frozenEntries) as $entryId) {
				foreach (array_keys($currentAssignments[$entryId] ?? []) as $labelId) {
					$keepLabelIds[$labelId] = true;
				}
			}
			foreach ($ownedById as $labelId => $tag) {
				if (!isset($keepLabelIds[$labelId]) && $this->tagDao->deleteTag($labelId) === false) {
					$errors['delete-' . $labelId] = "FreshRSS could not delete obsolete semantic label {$labelId}.";
				}
			}

			$this->persistState($generation, $snapshot['grouping_fingerprint'], $errors);
			if ($errors !== []) {
				Minz_Log::error('Semantic label synchronization incomplete: ' . implode(' ', array_values($errors)));
				return false;
			}
			if (class_exists('FreshRSS_UserDAO')) {
				FreshRSS_UserDAO::touch();
			}
			return true;
		});
	}

	public function removeManagedLabels(): void {
		$directory = dirname($this->database->path);
		$lock = null;
		if (is_dir($directory)) {
			$user = Minz_User::name() ?? '_';
			$lockPath = $directory . '/.semantic-label-' . substr(hash('sha256', $user), 0, 16) . '.lock';
			$lock = fopen($lockPath, 'c');
			if ($lock === false) {
				throw new RuntimeException('Semantic label cleanup lock cannot be opened.');
			}
			@chmod($lockPath, 0660);
			if (!flock($lock, LOCK_EX | LOCK_NB)) {
				fclose($lock);
				throw new RuntimeException('Semantic label synchronization is active; cleanup was deferred.');
			}
		}
		try {
			foreach ($this->nativeTags() as $tag) {
				if (self::ownedKey($tag) !== null && $this->tagDao->deleteTag((int)$tag['id']) === false) {
					throw new RuntimeException('FreshRSS could not delete semantic label ' . (int)$tag['id'] . '.');
				}
			}
			try {
				$pdo = $this->database->open(false);
				SemanticGrouping_SemanticDatabase::transaction($pdo, static function (PDO $db): void {
					$db->exec('DELETE FROM managed_labels');
					$db->exec('DELETE FROM label_sync_state');
				});
			} catch (Throwable) {
				// Native ownership metadata is sufficient for safe cleanup even when
				// the reproducible semantic database is unavailable.
			}
			if (class_exists('FreshRSS_UserDAO')) {
				FreshRSS_UserDAO::touch();
			}
		} finally {
			if (is_resource($lock)) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	/** @return null|array{generation:int,grouping_fingerprint:string,candidate_ids:list<string>,groups:list<array{group_id:string,representative_entry_id:string,members:list<string>}>} */
	private function publishedSnapshot(): ?array {
		$pdo = $this->database->open(false);
		$pdo->beginTransaction();
		try {
			$pipeline = $pdo->query('SELECT active_generation, config_json FROM pipeline_config WHERE singleton=1')->fetch();
			if (!is_array($pipeline)) {
				$pdo->commit();
				return null;
			}
			$config = json_decode((string)$pipeline['config_json'], true);
			if (!is_array($config) || empty($config['enabled'])) {
				$pdo->commit();
				return null;
			}
			$generation = (int)$pipeline['active_generation'];
			$fingerprint = SemanticGrouping_Config::groupingFingerprint($config);
			$stateStatement = $pdo->prepare("SELECT key, value FROM worker_state WHERE key IN ('last_published_generation', 'current_grouping_fingerprint')");
			$stateStatement->execute();
			$state = [];
			foreach ($stateStatement as $row) {
				$state[(string)$row['key']] = (string)$row['value'];
			}
			if ((int)($state['last_published_generation'] ?? 0) !== $generation
					|| !hash_equals($fingerprint, (string)($state['current_grouping_fingerprint'] ?? ''))) {
				$pdo->commit();
				return null;
			}

			$candidateStatement = $pdo->prepare('SELECT entry_id FROM candidate_members WHERE generation=? ORDER BY entry_id');
			$candidateStatement->execute([$generation]);
			$candidateIds = array_map('strval', $candidateStatement->fetchAll(PDO::FETCH_COLUMN));
			$groupStatement = $pdo->prepare('SELECT group_id, representative_entry_id FROM groups WHERE selection_generation=? AND grouping_fingerprint=? ORDER BY group_id');
			$groupStatement->execute([$generation, $fingerprint]);
			$groups = [];
			$memberStatement = $pdo->prepare('SELECT entry_id FROM group_members WHERE group_id=? ORDER BY entry_id');
			foreach ($groupStatement as $row) {
				$memberStatement->execute([(string)$row['group_id']]);
				$groups[] = [
					'group_id' => (string)$row['group_id'],
					'representative_entry_id' => (string)$row['representative_entry_id'],
					'members' => array_map('strval', $memberStatement->fetchAll(PDO::FETCH_COLUMN)),
				];
			}
			$pdo->commit();
			return [
				'generation' => $generation,
				'grouping_fingerprint' => $fingerprint,
				'candidate_ids' => $candidateIds,
				'groups' => $groups,
			];
		} catch (Throwable $error) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw $error;
		}
	}

	/** @return list<array{id:int,name:string,attributes:mixed}> */
	private function nativeTags(): array {
		$tags = [];
		foreach ($this->tagDao->selectAll() as $row) {
			if (is_array($row) && isset($row['id'], $row['name'])) {
				$tags[] = [
					'id' => (int)$row['id'],
					'name' => (string)$row['name'],
					'attributes' => $row['attributes'] ?? [],
				];
			}
		}
		return $tags;
	}

	/** @param list<array{id:int,name:string,attributes:mixed}> $labels @return null|array{id:int,name:string,attributes:mixed} */
	private function selectOwnedLabel(string $key, array $labels): ?array {
		if ($labels === []) {
			return null;
		}
		$pdo = $this->database->open(false, true);
		$statement = $pdo->prepare('SELECT label_id FROM managed_labels WHERE semantic_key=?');
		$statement->execute([$key]);
		$preferred = (int)($statement->fetchColumn() ?: 0);
		usort($labels, static fn(array $left, array $right): int => (int)$left['id'] <=> (int)$right['id']);
		foreach ($labels as $label) {
			if ((int)$label['id'] === $preferred) {
				return $label;
			}
		}
		return $labels[0];
	}

	/** @param array<string,string> $errors */
	private function persistState(int $generation, string $fingerprint, array $errors): void {
		$tags = $this->nativeTags();
		$now = time();
		$pdo = $this->database->open(false);
		SemanticGrouping_SemanticDatabase::transaction($pdo, static function (PDO $db) use ($tags, $generation, $fingerprint, $errors, $now): void {
			$db->exec('DELETE FROM managed_labels');
			$insert = $db->prepare('INSERT OR IGNORE INTO managed_labels(semantic_key, label_id, label_name, selection_generation, updated_at) VALUES (?, ?, ?, ?, ?)');
			foreach ($tags as $tag) {
				$key = self::ownedKey($tag);
				if ($key !== null) {
					$insert->execute([$key, (int)$tag['id'], (string)$tag['name'], $generation, $now]);
				}
			}
			$set = $db->prepare('INSERT INTO label_sync_state(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
			if ($errors === []) {
				foreach ([
					'last_attempt' => (string)$now,
					'last_reconciled_generation' => (string)$generation,
					'current_grouping_fingerprint' => $fingerprint,
					'last_success' => (string)$now,
					'latest_error' => '',
				] as $key => $value) {
					$set->execute([$key, $value]);
				}
			} else {
				$set->execute(['last_attempt', (string)$now]);
				$set->execute(['latest_error', mb_substr(implode(' ', array_values($errors)), 0, 500, 'UTF-8')]);
				$set->execute(['latest_error_at', (string)$now]);
			}
		});
	}

	private function recordError(Throwable $error): void {
		try {
			$pdo = $this->database->open(false);
			SemanticGrouping_SemanticDatabase::transaction($pdo, static function (PDO $db) use ($error): void {
				$statement = $db->prepare('INSERT INTO label_sync_state(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
				$statement->execute(['last_attempt', (string)time()]);
				$statement->execute(['latest_error', self::safeError($error)]);
				$statement->execute(['latest_error_at', (string)time()]);
			});
		} catch (Throwable) {
			// A status failure must not escape the maintenance hook.
		}
	}

	/** @param array{id:int,name:string,attributes:mixed} $tag */
	private static function ownedKey(array $tag): ?string {
		$attributes = $tag['attributes'];
		if (is_string($attributes)) {
			$attributes = json_decode($attributes, true);
		}
		$ownership = is_array($attributes) ? ($attributes[self::OWNER_ATTRIBUTE] ?? null) : null;
		if (!is_array($ownership) || ($ownership['owner'] ?? null) !== self::OWNER_ID) {
			return null;
		}
		$key = $ownership['semantic_key'] ?? null;
		return is_string($key) && $key !== '' ? $key : null;
	}

	/** @return array<string,array<string,string>> */
	private static function ownershipAttributes(string $key): array {
		return [self::OWNER_ATTRIBUTE => ['owner' => self::OWNER_ID, 'semantic_key' => $key]];
	}

	private static function isExactNativeName(string $name): bool {
		if ($name === '' || trim($name) !== $name) {
			return false;
		}
		if (class_exists('FreshRSS_DatabaseDAO')) {
			$maximum = FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE;
			return mb_strcut($name, 0, $maximum, 'UTF-8') === $name;
		}
		return true;
	}

	/** @param callable():bool $callback */
	private function withLocks(callable $callback): bool {
		$directory = dirname($this->database->path);
		$user = Minz_User::name() ?? '_';
		$paths = [
			$directory . '/.semantic-label-' . substr(hash('sha256', $user), 0, 16) . '.lock',
			$directory . '/.semantic-worker.lock',
		];
		$locks = [];
		try {
			foreach ($paths as $path) {
				$lock = fopen($path, 'c');
				if ($lock === false) {
					throw new RuntimeException('Semantic synchronization lock cannot be opened.');
				}
				@chmod($path, 0660);
				if (!flock($lock, LOCK_EX | LOCK_NB)) {
					fclose($lock);
					return false;
				}
				$locks[] = $lock;
			}
			return $callback();
		} finally {
			foreach (array_reverse($locks) as $lock) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	private static function safeError(Throwable $error): string {
		$message = preg_replace('~(?:[A-Za-z]:)?[/\\\\][^\s:]+~', '<path>', $error->getMessage());
		return mb_substr(get_class($error) . ': ' . (is_string($message) ? $message : 'operation failed'), 0, 500, 'UTF-8');
	}
}
