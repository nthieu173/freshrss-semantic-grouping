<?php
declare(strict_types=1);

final class SemanticGrouping_GroupRepository {
	public function __construct(private readonly SemanticGrouping_SemanticDatabase $database = new SemanticGrouping_SemanticDatabase()) {}

	/** @return array<string,mixed> */
	public function status(): array {
		$pdo = $this->database->open(false, true);
		$pipeline = $pdo->query('SELECT * FROM pipeline_config WHERE singleton=1')->fetch();
		$export = self::state($pdo, 'export_state');
		$worker = self::state($pdo, 'worker_state');
		if (!is_array($pipeline)) {
			return ['available' => true, 'configured' => false, 'export' => $export, 'worker' => $worker];
		}
		$config = json_decode((string)$pipeline['config_json'], true);
		$config = is_array($config) ? $config : [];
		$active = (int)$pipeline['active_generation'];
		$candidateCountStatement = $pdo->prepare('SELECT COUNT(*) FROM candidate_members WHERE generation=?');
		$candidateCountStatement->execute([$active]);
		$candidateCount = (int)$candidateCountStatement->fetchColumn();
		$currentEmbeddings = 0;
		if ($config !== []) {
			$embeddingFingerprint = SemanticGrouping_Config::embeddingFingerprint($config);
			$statement = $pdo->prepare('SELECT COUNT(*) FROM candidate_members cm JOIN embeddings e ON e.entry_id=cm.entry_id AND e.source_hash=cm.source_hash AND e.embedding_fingerprint=? WHERE cm.generation=?');
			$statement->execute([$embeddingFingerprint, $active]);
			$currentEmbeddings = (int)$statement->fetchColumn();
		}
		$published = (int)($worker['last_published_generation'] ?? 0);
		$groupPending = $published !== $active;
		if ($config !== [] && !$groupPending) {
			$groupPending = ($worker['current_grouping_fingerprint'] ?? '') !== SemanticGrouping_Config::groupingFingerprint($config);
		}
		return [
			'available' => true,
			'configured' => true,
			'pipeline' => $pipeline,
			'config' => $config,
			'export' => $export,
			'worker' => $worker,
			'candidate_count' => $candidateCount,
			'current_embeddings' => $currentEmbeddings,
			'pending_embeddings' => max(0, $candidateCount - $currentEmbeddings),
			'export_pending' => trim((string)($export['last_export_error'] ?? '')) !== ''
				|| (int)($export['last_export_attempt'] ?? 0) > (int)($export['last_export_success'] ?? 0),
			'grouping_pending' => $groupPending,
		];
	}

	/** @return array{groups:list<array<string,mixed>>,page:int,pages:int,total:int} */
	public function groups(int $page, int $pageSize = 20): array {
		$page = max(1, $page);
		$pageSize = min(100, max(1, $pageSize));
		$pdo = $this->database->open(false, true);
		$total = (int)$pdo->query('SELECT COUNT(*) FROM groups')->fetchColumn();
		$statement = $pdo->prepare('SELECT * FROM groups ORDER BY generated_at DESC, group_id LIMIT ? OFFSET ?');
		$statement->bindValue(1, $pageSize, PDO::PARAM_INT);
		$statement->bindValue(2, ($page - 1) * $pageSize, PDO::PARAM_INT);
		$statement->execute();
		$groupRows = $statement->fetchAll();
		$groups = [];
		$minimum = 1;
		$configJson = $pdo->query('SELECT config_json FROM pipeline_config WHERE singleton=1')->fetchColumn();
		if (is_string($configJson)) {
			$config = json_decode($configJson, true);
			$minimum = is_array($config) ? max(1, (int)($config['minimum_group_size'] ?? 1)) : 1;
		}
		$entryDao = FreshRSS_Factory::createEntryDao();
		foreach ($groupRows as $groupRow) {
			$memberStatement = $pdo->prepare('SELECT entry_id, similarity FROM group_members WHERE group_id=? ORDER BY CASE WHEN entry_id=? THEN 0 ELSE 1 END, similarity DESC, entry_id');
			$memberStatement->execute([$groupRow['group_id'], $groupRow['representative_entry_id']]);
			$memberRows = $memberStatement->fetchAll();
			$ids = array_map(static fn(array $row): string => (string)$row['entry_id'], $memberRows);
			$entries = [];
			foreach ($entryDao->listByIds($ids, 'ASC') as $entry) {
				$entries[$entry->id()] = $entry;
			}
			$members = [];
			foreach ($memberRows as $memberRow) {
				$id = (string)$memberRow['entry_id'];
				if (!isset($entries[$id])) {
					continue;
				}
				$entry = $entries[$id];
				$link = $entry->link(raw: true);
				$scheme = is_string($link) ? strtolower((string)parse_url($link, PHP_URL_SCHEME)) : '';
				if (!in_array($scheme, ['http', 'https'], true)) {
					$link = '';
				}
				$members[] = [
					'id' => $id,
					'title' => $entry->title(),
					'url' => $link,
					'date' => $entry->date(),
					'is_read' => $entry->isRead(),
					'is_favorite' => $entry->isFavorite(),
					'excerpt' => mb_substr(SemanticGrouping_TextNormalizer::text($entry->content(false)), 0, 300, 'UTF-8'),
					'similarity' => $memberRow['similarity'] === null ? null : (float)$memberRow['similarity'],
					'representative' => $id === (string)$groupRow['representative_entry_id'],
				];
			}
			if (count($members) >= $minimum) {
				$groups[] = ['id' => (string)$groupRow['group_id'], 'generated_at' => (int)$groupRow['generated_at'], 'members' => $members];
			}
		}
		return ['groups' => $groups, 'page' => $page, 'pages' => max(1, (int)ceil($total / $pageSize)), 'total' => $total];
	}

	/** @return array<string,string> */
	private static function state(PDO $pdo, string $table): array {
		if (!in_array($table, ['export_state', 'worker_state'], true)) {
			return [];
		}
		$result = [];
		foreach ($pdo->query("SELECT key, value FROM {$table}") as $row) {
			$result[(string)$row['key']] = self::safe((string)$row['value']);
		}
		return $result;
	}

	private static function safe(string $value): string {
		$value = preg_replace('~(?:[A-Za-z]:)?[/\\\\][^\s:]+~', '<path>', $value);
		return mb_substr(is_string($value) ? $value : '', 0, 500, 'UTF-8');
	}
}
