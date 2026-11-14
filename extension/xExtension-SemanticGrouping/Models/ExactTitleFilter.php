<?php
declare(strict_types=1);

final class SemanticGrouping_ExactTitleFilter {
	/** @var array<string,true>|null Normalized titles that reject an entry. */
	private ?array $titles = null;
	/** @var array<string,true> Raw staged titles already folded into $titles. */
	private array $stagedTitles = [];
	private ?FreshRSS_EntryDAO $dao = null;
	/** @var Closure(string):string */
	private readonly Closure $normalize;

	/** @param null|Closure(string):string $normalize Title normalization; tests count its calls. */
	public function __construct(?Closure $normalize = null) {
		$this->normalize = $normalize ?? SemanticGrouping_TextNormalizer::title(...);
	}

	public function filter(FreshRSS_Entry $entry): ?FreshRSS_Entry {
		if ($this->titles === null) {
			$this->titles = [];
			$this->dao = FreshRSS_Factory::createEntryDao();
			foreach ($this->dao->fetchColumn('SELECT title FROM `_entry`', 0) ?? [] as $value) {
				if (is_string($value)) {
					$this->addTitle($value);
				}
			}
		}
		// FreshRSS stages accepted entries in _entrytmp until the surrounding
		// feed actualization commits. Re-read it for every hook invocation so
		// entries staged by another request are visible in this process. Ids
		// cannot bound the read: they are taken from the clock before the hook
		// runs, so another request can stage a lower id after this one read a
		// higher one. Every row is therefore re-read, but only a raw title not
		// seen before is normalized, which is where the cost lies.
		foreach ($this->dao->fetchColumn('SELECT title FROM `_entrytmp`', 0) ?? [] as $value) {
			if (is_string($value) && !isset($this->stagedTitles[$value])) {
				$this->stagedTitles[$value] = true;
				$this->addTitle($value);
			}
		}
		$title = ($this->normalize)($entry->title());
		if ($title === '') {
			return $entry;
		}
		if (isset($this->titles[$title])) {
			return null;
		}
		$this->titles[$title] = true;
		return $entry;
	}

	private function addTitle(string $value): void {
		$title = ($this->normalize)($value);
		if ($title !== '') {
			$this->titles[$title] = true;
		}
	}
}
