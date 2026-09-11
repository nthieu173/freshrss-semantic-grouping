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
		$labelSync = self::state($pdo, 'label_sync_state');
		if (!is_array($pipeline)) {
			return [
				'available' => true,
				'configured' => false,
				'export' => $export,
				'worker' => $worker,
				'label_sync' => $labelSync,
			];
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
		$groupingFingerprint = $config === [] ? '' : SemanticGrouping_Config::groupingFingerprint($config);
		$groupPending = $published !== $active;
		if (!$groupPending && $groupingFingerprint !== '') {
			$groupPending = ($worker['current_grouping_fingerprint'] ?? '') !== $groupingFingerprint;
		}
		$labelPending = !empty($config['enabled']) && (
			(int)($labelSync['last_reconciled_generation'] ?? 0) !== $active
			|| ($labelSync['current_grouping_fingerprint'] ?? '') !== $groupingFingerprint
			|| trim((string)($labelSync['latest_error'] ?? '')) !== ''
		);
		return [
			'available' => true,
			'configured' => true,
			'pipeline' => $pipeline,
			'config' => $config,
			'export' => $export,
			'worker' => $worker,
			'label_sync' => $labelSync,
			'candidate_count' => $candidateCount,
			'current_embeddings' => $currentEmbeddings,
			'pending_embeddings' => max(0, $candidateCount - $currentEmbeddings),
			'export_pending' => trim((string)($export['last_export_error'] ?? '')) !== ''
				|| (int)($export['last_export_attempt'] ?? 0) > (int)($export['last_export_success'] ?? 0),
			'grouping_pending' => $groupPending,
			'label_sync_pending' => $labelPending,
		];
	}

	/** @return array<string,string> */
	private static function state(PDO $pdo, string $table): array {
		if (!in_array($table, ['export_state', 'worker_state', 'label_sync_state'], true)) {
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
