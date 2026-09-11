<?php
declare(strict_types=1);

final class SemanticGrouping_Config {
	public const SCHEMA_VERSION = 3;
	public const NORMALIZATION_VERSION = 1;
	public const MINIMUM_GROUP_SIZE = 2;

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'enabled' => true,
			'candidate_source' => [
				'mode' => 'all_entries',
				'query_id' => null,
				'query_name' => 'All entries',
			],
			'embedding_model' => 'minishlab/potion-base-8M',
			'similarity_threshold' => 0.90,
			'window_hours' => 72,
			'candidate_export_interval_minutes' => 30,
			'include_title' => true,
			'include_content' => false,
			'content_character_limit' => 2000,
			'exact_title_enabled' => true,
			'embedding_batch_size' => 128,
			'normalization_version' => self::NORMALIZATION_VERSION,
			'force_rebuild_token' => '',
		];
	}

	/** @param array<string,mixed> $stored @return array<string,mixed> */
	public static function merge(array $stored): array {
		$config = array_replace(self::defaults(), $stored);
		$config['schema_version'] = self::SCHEMA_VERSION;
		unset($config['worker_interval_minutes'], $config['minimum_group_size']);
		$refreshInterval = $config['candidate_export_interval_minutes'] ?? null;
		if (is_int($refreshInterval) && $refreshInterval >= 1 && $refreshInterval <= 1440) {
			$config['candidate_export_interval_minutes'] = min(1440, max(10, intdiv($refreshInterval + 9, 10) * 10));
		}
		$source = is_array($stored['candidate_source'] ?? null) ? $stored['candidate_source'] : [];
		$config['candidate_source'] = array_replace(self::defaults()['candidate_source'], $source);
		return $config;
	}

	/** @return array<string,mixed> */
	public static function fromRequest(): array {
		$sourceValue = Minz_Request::paramString('candidate_source', plaintext: true);
		$thresholdValue = trim(Minz_Request::paramString('similarity_threshold', plaintext: true));
		$source = $sourceValue === 'all_entries'
			? ['mode' => 'all_entries', 'query_id' => null, 'query_name' => 'All entries']
			: ['mode' => 'saved_query', 'query_id' => ctype_digit($sourceValue) ? (int)$sourceValue : null, 'query_name' => ''];
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'enabled' => Minz_Request::paramBoolean('enabled'),
			'candidate_source' => $source,
			'embedding_model' => trim(Minz_Request::paramString('embedding_model', plaintext: true)),
			'similarity_threshold' => is_numeric($thresholdValue) ? (float)$thresholdValue : NAN,
			'window_hours' => Minz_Request::paramInt('window_hours'),
			'candidate_export_interval_minutes' => Minz_Request::paramInt('candidate_export_interval_minutes'),
			'include_title' => Minz_Request::paramBoolean('include_title'),
			'include_content' => Minz_Request::paramBoolean('include_content'),
			'content_character_limit' => Minz_Request::paramInt('content_character_limit'),
			'exact_title_enabled' => Minz_Request::paramBoolean('exact_title_enabled'),
			'embedding_batch_size' => Minz_Request::paramInt('embedding_batch_size') ?: 128,
			'normalization_version' => self::NORMALIZATION_VERSION,
			'force_rebuild_token' => Minz_Request::paramString('force_rebuild_token', plaintext: true),
		];
	}

	/** @param array<string,mixed> $config @return list<string> */
	public static function validate(array $config): array {
		$errors = [];
		if (($config['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
			$errors[] = 'Unsupported configuration schema.';
		}
		$model = $config['embedding_model'] ?? null;
		if (!is_string($model) || trim($model) === '' || strlen($model) > 300) {
			$errors[] = 'Embedding model must be a non-empty identifier.';
		}
		$threshold = $config['similarity_threshold'] ?? null;
		if ((!is_float($threshold) && !is_int($threshold)) || !is_finite((float)$threshold) || (float)$threshold < 0.0 || (float)$threshold > 1.0) {
			$errors[] = 'Similarity threshold must be between 0 and 1.';
		}
		self::validateInt($config, 'window_hours', 1, 8760, $errors);
		$refreshInterval = $config['candidate_export_interval_minutes'] ?? null;
		if (!is_int($refreshInterval) || $refreshInterval < 10 || $refreshInterval > 1440) {
			$errors[] = 'Refresh interval must be between 10 and 1440 minutes.';
		} elseif ($refreshInterval % 10 !== 0) {
			$errors[] = 'Refresh interval must be a multiple of 10 minutes.';
		}
		self::validateInt($config, 'content_character_limit', 0, 100000, $errors);
		self::validateInt($config, 'embedding_batch_size', 1, 256, $errors);
		if (empty($config['include_title']) && empty($config['include_content'])) {
			$errors[] = 'Enable title or content as an embedding input.';
		}
		$source = $config['candidate_source'] ?? null;
		if ((!is_array($source) || !in_array($source['mode'] ?? null, ['saved_query', 'all_entries'], true)) && !empty($config['enabled'])) {
			$errors[] = 'Choose a saved query or the explicit All entries source.';
		} elseif (is_array($source) && ($source['mode'] ?? null) === 'saved_query'
				&& !is_int($source['query_id'] ?? null) && !empty($config['enabled'])) {
			$errors[] = 'Choose a saved query.';
		}
		return $errors;
	}

	/** @param array<string,mixed> $config @param list<string> $errors */
	private static function validateInt(array $config, string $key, int $minimum, int $maximum, array &$errors): void {
		$value = $config[$key] ?? null;
		if (!is_int($value) || $value < $minimum || $value > $maximum) {
			$errors[] = str_replace('_', ' ', ucfirst($key)) . " must be between {$minimum} and {$maximum}.";
		}
	}

	/** @param mixed $value */
	public static function canonicalJson($value): string {
		$value = self::sortValue($value);
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		return $json;
	}

	/** @param mixed $value @return mixed */
	private static function sortValue($value) {
		if (!is_array($value)) {
			return $value;
		}
		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as $key => $item) {
			$value[$key] = self::sortValue($item);
		}
		return $value;
	}

	/** @param mixed $value */
	public static function fingerprint($value): string {
		return hash('sha256', "semantic-v1\0" . self::canonicalJson($value));
	}

	/** @param array<string,mixed> $config @return array<string,mixed> */
	public static function effectiveWorkerConfig(array $config, string $queryFingerprint): array {
		$config = self::merge($config);
		$config['query_fingerprint'] = $queryFingerprint;
		return $config;
	}

	/** @param array<string,mixed> $config */
	public static function embeddingFingerprint(array $config): string {
		return self::fingerprint([
			'content_character_limit' => (int)$config['content_character_limit'],
			'embedding_model' => (string)$config['embedding_model'],
			'force_rebuild_token' => (string)($config['force_rebuild_token'] ?? ''),
			'include_content' => (bool)$config['include_content'],
			'include_title' => (bool)$config['include_title'],
			'input_format_version' => 1,
			'normalization_version' => (int)$config['normalization_version'],
		]);
	}

	/** @param array<string,mixed> $config */
	public static function groupingFingerprint(array $config): string {
		return self::fingerprint([
			'embedding_fingerprint' => self::embeddingFingerprint($config),
			'minimum_group_size' => self::MINIMUM_GROUP_SIZE,
			'query_fingerprint' => (string)$config['query_fingerprint'],
			'similarity_threshold' => (float)$config['similarity_threshold'],
			'window_hours' => (int)$config['window_hours'],
		]);
	}
}
