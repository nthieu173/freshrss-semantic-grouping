<?php
declare(strict_types=1);

final class SemanticGrouping_ExactTitleFilter {
	/** @var array<string,true>|null */
	private ?array $titles = null;
	private ?FreshRSS_EntryDAO $dao = null;

	public function filter(FreshRSS_Entry $entry): ?FreshRSS_Entry {
		if ($this->titles === null) {
			$this->titles = [];
			$this->dao = FreshRSS_Factory::createEntryDao();
			$this->addTitles($this->dao->fetchColumn('SELECT title FROM `_entry`', 0));
		}
		// FreshRSS stages accepted entries in _entrytmp until the surrounding
		// feed actualization commits. Refresh this set for every hook invocation
		// so entries staged by another request are visible in this process.
		$this->addTitles($this->dao->fetchColumn('SELECT title FROM `_entrytmp`', 0));
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

	/** @param list<int|string|null>|null $titles */
	private function addTitles(?array $titles): void {
		foreach ($titles ?? [] as $value) {
			if (!is_string($value)) {
				continue;
			}
			$title = SemanticGrouping_TextNormalizer::title($value);
			if ($title !== '') {
				$this->titles[$title] = true;
			}
		}
	}
}
