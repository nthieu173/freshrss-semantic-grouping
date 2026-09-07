<?php
declare(strict_types=1);

final class SemanticGrouping_TextNormalizer {
	public static function title(string $title): string {
		$value = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (class_exists('Normalizer')) {
			$normalized = Normalizer::normalize($value, Normalizer::FORM_C);
			if (is_string($normalized)) {
				$value = $normalized;
			}
		}
		$value = mb_strtolower($value, 'UTF-8');
		$value = preg_replace('/[\p{Z}\s]+/u', ' ', trim($value));
		return is_string($value) ? $value : '';
	}

	public static function text(string $html, ?int $limit = null): string {
		$value = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = strip_tags($value);
		if (class_exists('Normalizer')) {
			$normalized = Normalizer::normalize($value, Normalizer::FORM_C);
			if (is_string($normalized)) {
				$value = $normalized;
			}
		}
		$value = preg_replace('/[\p{Z}\s]+/u', ' ', trim($value));
		$value = is_string($value) ? $value : '';
		if ($limit !== null && $limit >= 0 && mb_strlen($value, 'UTF-8') > $limit) {
			$value = mb_substr($value, 0, $limit, 'UTF-8');
		}
		return $value;
	}

	/** @param array<string,mixed> $config @return array{0:string,1:string} */
	public static function embeddingInput(string $title, string $content, array $config): array {
		$fields = [];
		if (!empty($config['include_title'])) {
			$fields['title'] = self::text($title);
		}
		if (!empty($config['include_content'])) {
			$fields['content'] = self::text($content, (int)$config['content_character_limit']);
		}
		$parts = [];
		$serialized = "embedding-input-v1\0";
		foreach ($fields as $name => $value) {
			$parts[] = $value;
			$serialized .= pack('N', strlen($name)) . $name . pack('N', strlen($value)) . $value;
		}
		return [implode("\n", $parts), hash('sha256', $serialized)];
	}
}

