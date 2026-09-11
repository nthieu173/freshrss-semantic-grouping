<?php
declare(strict_types=1);

final class SemanticGrouping_CandidateSource {
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly int $id,
		public readonly int $state,
		public readonly FreshRSS_BooleanSearch $search,
		public readonly string $fingerprint,
	) {}

	/** @param array<string,mixed> $config */
	public static function resolve(array &$config, ?int $now = null): self {
		$source = $config['candidate_source'] ?? null;
		if (!is_array($source)) {
			throw new InvalidArgumentException('Candidate source is missing.');
		}
		$cutoff = ($now ?? time()) - (int)$config['window_hours'] * 3600;
		$outerSearch = new FreshRSS_BooleanSearch('');
		$outerSearch->add(new FreshRSS_BooleanSearch('date:' . gmdate('Y-m-d\TH:i:s\Z', $cutoff) . '/'));

		if (($source['mode'] ?? null) === 'all_entries') {
			$fingerprint = SemanticGrouping_Config::fingerprint([
				'mode' => 'all_entries',
				'window_hours' => (int)$config['window_hours'],
			]);
			$config['candidate_source'] = ['mode' => 'all_entries', 'query_id' => null, 'query_name' => 'All entries'];
			return new self('All entries', 'Z', 0, FreshRSS_Entry::STATE_ALL, $outerSearch, $fingerprint);
		}

		$queryId = $source['query_id'] ?? null;
		if (!is_int($queryId)) {
			throw new InvalidArgumentException('Saved query identity is missing.');
		}
		$queries = FreshRSS_Context::userConf()->queries;
		if (!is_array($queries) || !array_key_exists($queryId, $queries) || !is_array($queries[$queryId])) {
			throw new InvalidArgumentException('The selected saved query no longer exists.');
		}
		$raw = $queries[$queryId];
		$query = new FreshRSS_UserQuery($raw, FreshRSS_Context::categories(), FreshRSS_Context::labels());
		$name = trim($query->getName());
		$state = $query->getState();
		$unfilteredStates = [
			FreshRSS_Entry::STATE_ALL,
			FreshRSS_Entry::STATE_ALL | FreshRSS_Entry::STATE_FAVORITE | FreshRSS_Entry::STATE_NOT_FAVORITE,
		];
		$hasFilter = $query->getGetType() !== 'all' || $query->hasSearch() || !in_array($state, $unfilteredStates, true);
		if ($name === '' || !$hasFilter || $query->isDeprecated()) {
			throw new InvalidArgumentException('The selected saved query is empty or references a missing source.');
		}
		$configuredName = $source['query_name'] ?? '';
		if (is_string($configuredName) && $configuredName !== '' && $configuredName !== $name) {
			throw new InvalidArgumentException('The selected saved query was renamed; save the configuration again.');
		}
		$matchingNames = 0;
		foreach ($queries as $candidate) {
			if (is_array($candidate) && trim((string)($candidate['name'] ?? '')) === $name) {
				$matchingNames++;
			}
		}
		if ($matchingNames !== 1) {
			throw new InvalidArgumentException('The selected saved query name is ambiguous.');
		}

		$outerSearch->add(clone $query->getSearch());
		[$type, $id] = self::daoSource($query);
		$definition = [
			'query_id' => $queryId,
			'query' => $query->toArray(),
			'expanded_search' => $query->getSearch()->toString(),
			'type' => $type,
			'id' => $id,
			'state' => $state,
			'window_hours' => (int)$config['window_hours'],
		];
		$fingerprint = SemanticGrouping_Config::fingerprint($definition);
		$config['candidate_source'] = ['mode' => 'saved_query', 'query_id' => $queryId, 'query_name' => $name];
		return new self($name, $type, $id, $state, $outerSearch, $fingerprint);
	}

	/** @return array{0:string,1:int} */
	private static function daoSource(FreshRSS_UserQuery $query): array {
		$type = match ($query->getGetType()) {
			'all' => 'a',
			'A' => 'A',
			'category' => 'c',
			'feed' => 'f',
			'important' => 'i',
			'favorite' => 's',
			'label' => 't',
			'all_labels' => 'T',
			default => throw new InvalidArgumentException('Unsupported saved-query source.'),
		};
		$id = 0;
		if (preg_match('/_(\d+)$/', $query->getGet(), $matches) === 1) {
			$id = (int)$matches[1];
		}
		return [$type, $id];
	}

	/** @return Traversable<FreshRSS_Entry> */
	public function entries(int $limit = 0): Traversable {
		$dao = FreshRSS_Factory::createEntryDao();
		// EntryDAO 1.30.0 uses Context::$search for visibility decisions even
		// when the same filter is passed to listWhere(). Mirror the normal reader
		// for the lifetime of the lazy iterator, then restore request state.
		$previousSearch = FreshRSS_Context::$search;
		FreshRSS_Context::$search = $this->search;
		try {
			yield from $dao->listWhere(
				$this->type,
				$this->id,
				$this->state,
				$this->search,
				sort: 'id',
				order: 'ASC',
				limit: $limit,
			);
		} finally {
			FreshRSS_Context::$search = $previousSearch;
		}
	}
}
