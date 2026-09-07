<?php
declare(strict_types=1);

final class SemanticGrouping_ExactTitleFilter {
	/** @var array<string,true>|null */
	private ?array $titles = null;

	public function filter(FreshRSS_Entry $entry): ?FreshRSS_Entry {
		if ($this->titles === null) {
			$this->titles = [];
			$dao = FreshRSS_Factory::createEntryDao();
			$emptySearch = new FreshRSS_BooleanSearch('');
			// EntryDAO 1.29.1 also consults the request-global search while
			// deciding whether hidden feeds are visible. An ingestion filter must
			// compare against every retained entry, regardless of the reader view
			// that happened to initialize this process.
			$previousSearch = FreshRSS_Context::$search;
			FreshRSS_Context::$search = $emptySearch;
			try {
				foreach ($dao->listWhere('Z', 0, FreshRSS_Entry::STATE_ALL, $emptySearch, limit: 0) as $existing) {
					$title = SemanticGrouping_TextNormalizer::title($existing->title());
					if ($title !== '') {
						$this->titles[$title] = true;
					}
				}
			} finally {
				FreshRSS_Context::$search = $previousSearch;
			}
		}
		$title = SemanticGrouping_TextNormalizer::title($entry->title());
		if ($title === '') {
			return $entry;
		}
		if (isset($this->titles[$title])) {
			return null;
		}
		$this->titles[$title] = true;
		return $entry;
	}
}
