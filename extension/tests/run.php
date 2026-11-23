<?php
declare(strict_types=1);

final class FreshRSS_BooleanSearch {
	public function __construct(string $unused) {}
}

final class FreshRSS_Context {
	public static FreshRSS_BooleanSearch $search;
}

final class FreshRSS_DatabaseDAO {
	public const LENGTH_INDEX_UNICODE = 191;
}

final class FreshRSS_UserDAO {
	public static function touch(): void {}
}

final class Minz_User {
	public static function name(): string { return 'test-user'; }
}

final class Minz_Log {
	public static function error(string $unused): void {}
}

final class FreshRSS_Entry {
	public const STATE_ALL = 15;
	public function __construct(
		private readonly string $value,
		private readonly string $entryId = '1',
		private readonly int $timestamp = 0,
	) {}
	public function title(): string { return $this->value; }
	public function id(): string { return $this->entryId; }
	public function date(bool $raw = false): int|string { return $raw ? $this->timestamp : (string)$this->timestamp; }
	public function link(bool $raw = false): string { return 'https://example.invalid/' . $this->entryId; }
	public function isRead(): bool { return false; }
	public function isFavorite(): bool { return false; }
	public function content(bool $raw = false): string { return ''; }
}

class FreshRSS_EntryDAO {}

final class TestEntryDao extends FreshRSS_EntryDAO {
	/** @var list<string> */
	public array $pendingTitles;
	/** @param list<FreshRSS_Entry> $entries */
	public function __construct(private readonly array $entries, array $pendingTitles = []) {
		$this->pendingTitles = $pendingTitles;
	}
	/** @return Traversable<FreshRSS_Entry> */
	public function listWhere(...$unused): Traversable { yield from $this->entries; }
	/** @return list<string> */
	public function fetchColumn(string $sql, int $column): array {
		check($column === 0, 'exact-title query selected an unexpected column');
		if (str_contains($sql, '_entrytmp')) {
			return $this->pendingTitles;
		}
		check(str_contains($sql, '_entry'), 'exact-title query used an unexpected table');
		return array_map(static fn(FreshRSS_Entry $entry): string => $entry->title(), $this->entries);
	}
	/** @param list<string> $ids @return Traversable<FreshRSS_Entry> */
	public function listByIds(array $ids, string $order = 'ASC'): Traversable {
		foreach ($this->entries as $entry) {
			if (in_array($entry->id(), $ids, true)) {
				yield $entry;
			}
		}
	}
}

final class TestTagDao {
	/** @var array<int,array{id:int,name:string,attributes:mixed}> */
	public array $tags = [];
	/** @var array<string,array<int,bool>> */
	public array $assignments = [];
	private int $nextId = 1;
	/** Called after each successful attribute update, as a label write. */
	public ?Closure $afterAttributes = null;
	/** Called before every label listing. */
	public ?Closure $beforeSelectAll = null;

	/** @param array{name:string,attributes?:mixed} $values */
	public function addTag(array $values): int|false {
		foreach ($this->tags as $tag) {
			if ($tag['name'] === trim($values['name'])) {
				return false;
			}
		}
		$id = $this->nextId++;
		$this->tags[$id] = ['id' => $id, 'name' => trim($values['name']), 'attributes' => $values['attributes'] ?? []];
		return $id;
	}
	/** @return Traversable<array{id:int,name:string,attributes:mixed}> */
	public function selectAll(): Traversable {
		if ($this->beforeSelectAll !== null) {
			($this->beforeSelectAll)();
		}
		yield from array_values($this->tags);
	}
	/** @return Traversable<array{id_tag:int,id_entry:string}> */
	public function selectEntryTag(): Traversable {
		foreach ($this->assignments as $entryId => $labels) {
			foreach (array_keys($labels) as $labelId) {
				yield ['id_tag' => $labelId, 'id_entry' => $entryId];
			}
		}
	}
	public function updateTagName(int $id, string $name): int|false {
		if (!isset($this->tags[$id])) {
			return false;
		}
		foreach ($this->tags as $otherId => $tag) {
			if ($otherId !== $id && $tag['name'] === trim($name)) {
				return false;
			}
		}
		$this->tags[$id]['name'] = trim($name);
		return 1;
	}
	/** @param array<string,mixed> $attributes */
	public function updateTagAttributes(int $id, array $attributes): int|false {
		if (!isset($this->tags[$id])) {
			return false;
		}
		$this->tags[$id]['attributes'] = $attributes;
		if ($this->afterAttributes !== null) {
			($this->afterAttributes)();
		}
		return 1;
	}
	public function deleteTag(int $id): int|false {
		if (!isset($this->tags[$id])) {
			return 0;
		}
		unset($this->tags[$id]);
		foreach ($this->assignments as $entryId => $labels) {
			unset($this->assignments[$entryId][$id]);
		}
		return 1;
	}
	public function tagEntry(int $labelId, string $entryId, bool $checked = true): bool {
		if (!isset($this->tags[$labelId])) {
			return false;
		}
		if ($checked) {
			$this->assignments[$entryId][$labelId] = true;
		} else {
			unset($this->assignments[$entryId][$labelId]);
		}
		return true;
	}
	/** @return null|array{id:int,name:string,attributes:mixed} */
	public function labelByName(string $name): ?array {
		foreach ($this->tags as $tag) {
			if ($tag['name'] === $name) {
				return $tag;
			}
		}
		return null;
	}
	/** @return list<string> */
	public function labelsForEntry(string $entryId): array {
		$names = [];
		foreach (array_keys($this->assignments[$entryId] ?? []) as $labelId) {
			$names[] = $this->tags[$labelId]['name'];
		}
		sort($names);
		return $names;
	}
}

final class FreshRSS_Factory {
	public static TestEntryDao $dao;
	public static TestTagDao $tagDao;
	public static function createEntryDao(): TestEntryDao { return self::$dao; }
	public static function createTagDao(): TestTagDao { return self::$tagDao; }
}

require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/Config.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/TextNormalizer.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/ExactTitleFilter.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/SemanticDatabase.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/GroupRepository.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/LabelReconciler.php';

FreshRSS_Context::$search = new FreshRSS_BooleanSearch('');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

check(SemanticGrouping_TextNormalizer::title("  HELLO\n world  ") === 'hello world', 'whitespace/lowercase normalization failed');
check(SemanticGrouping_TextNormalizer::title(" \t\n ") === '', 'blank title must stay non-deduplicable');
check(SemanticGrouping_TextNormalizer::title('A &amp; B') === 'a & b', 'HTML entity title normalization failed');
if (class_exists('Transliterator')) {
	check(
		SemanticGrouping_TextNormalizer::title("Organizer behind \u{2018}moth\u{2019} demonstrations")
			=== SemanticGrouping_TextNormalizer::title("Organizer behind 'moth' demonstrations"),
		'typographic single-quote normalization failed',
	);
	check(
		SemanticGrouping_TextNormalizer::title("Witness called it \u{201C}unexpected\u{201D}")
			=== SemanticGrouping_TextNormalizer::title('Witness called it "unexpected"'),
		'typographic double-quote normalization failed',
	);
}
if (class_exists('Normalizer')) {
	check(SemanticGrouping_TextNormalizer::title("CAFE\u{0301}") === 'café', 'Unicode NFC title normalization failed');
}
[$text, $hash] = SemanticGrouping_TextNormalizer::embeddingInput('A &amp; B', '<p>Some <b>text</b></p>', [
	'include_title' => true,
	'include_content' => true,
	'content_character_limit' => 4,
]);
check($text === "A & B\nSome", 'embedding input canonicalization failed');
check(strlen($hash) === 64, 'source hash is not SHA-256');
[, $changedHash] = SemanticGrouping_TextNormalizer::embeddingInput('A &amp; B', '<p>Different</p>', [
	'include_title' => true,
	'include_content' => true,
	'content_character_limit' => 4,
]);
check($changedHash !== $hash, 'changed source input did not create an immutable version');

FreshRSS_Factory::$dao = new TestEntryDao([new FreshRSS_Entry('Existing title')]);
$readerSearch = new FreshRSS_BooleanSearch('reader-state');
FreshRSS_Context::$search = $readerSearch;
$filter = new SemanticGrouping_ExactTitleFilter();
check($filter->filter(new FreshRSS_Entry(' existing   TITLE ')) === null, 'existing duplicate was accepted');
check(FreshRSS_Context::$search === $readerSearch, 'exact-title scan did not restore the reader search');
$accepted = new FreshRSS_Entry('New title');
check($filter->filter($accepted) === $accepted, 'new title was rejected');
check($filter->filter(new FreshRSS_Entry('NEW TITLE')) === null, 'same-batch duplicate was accepted');
check($filter->filter(new FreshRSS_Entry('   ')) instanceof FreshRSS_Entry, 'blank title was rejected');

$stagingDao = new TestEntryDao([]);
FreshRSS_Factory::$dao = $stagingDao;
$filter = new SemanticGrouping_ExactTitleFilter();
check($filter->filter(new FreshRSS_Entry('First local title')) instanceof FreshRSS_Entry, 'initial title was rejected');
$stagingDao->pendingTitles[] = 'Externally staged title';
check(
	$filter->filter(new FreshRSS_Entry(' externally STAGED title ')) === null,
	'entry staged after filter initialization was accepted',
);

// Staged titles are re-read on every call, but each raw title is normalized
// once. Rows staged out of id order by another request are still seen,
// because the filter does not bound the read by the highest id it has seen.
$normalized = [];
$countingNormalizer = static function (string $title) use (&$normalized): string {
	$normalized[] = $title;
	return SemanticGrouping_TextNormalizer::title($title);
};
$stagingDao = new TestEntryDao([new FreshRSS_Entry('Retained title')], ['Staged one', 'Staged two']);
FreshRSS_Factory::$dao = $stagingDao;
$filter = new SemanticGrouping_ExactTitleFilter($countingNormalizer);
for ($index = 0; $index < 20; $index++) {
	$entry = new FreshRSS_Entry("Fresh title {$index}");
	check($filter->filter($entry) === $entry, 'distinct staged-batch title was rejected');
	$stagingDao->pendingTitles[] = "Fresh title {$index}";
}
array_unshift($stagingDao->pendingTitles, 'Late lower-id title');
check($filter->filter(new FreshRSS_Entry('LATE lower-id title')) === null, 'title staged with a lower id by another request was accepted');
check($filter->filter(new FreshRSS_Entry('staged TWO')) === null, 'initially staged title was forgotten');
// 1 retained + 2 initially staged + 20 own + 1 late staged, plus one
// normalization of each of the 22 incoming entries.
check(count($normalized) === 1 + 2 + 20 + 1 + 22, 'staged titles were normalized again on every call: ' . count($normalized));

if (class_exists('Transliterator')) {
	FreshRSS_Factory::$dao = new TestEntryDao([new FreshRSS_Entry("Organizer behind \u{2018}moth\u{2019} demonstrations")]);
	$filter = new SemanticGrouping_ExactTitleFilter();
	check(
		$filter->filter(new FreshRSS_Entry("Organizer behind 'moth' demonstrations")) === null,
		'typographic quote variant of an existing title was accepted',
	);
}

/** The publication and label scenario every storage backend must pass. */
function exerciseDatabase(SemanticGrouping_SemanticDatabase $database): PDO {
	$pdo = $database->open(true);
	check(SemanticGrouping_SemanticDatabase::schemaVersion($pdo) === 3, 'database migration version failed');
	try {
		SemanticGrouping_SemanticDatabase::transaction($pdo, static function (PDO $db): void {
			$db->exec("INSERT INTO export_state(key, value) VALUES ('rollback-test', 'value')");
			throw new RuntimeException('rollback requested');
		});
		throw new RuntimeException('transaction callback error was not rethrown');
	} catch (RuntimeException $error) {
		check($error->getMessage() === 'rollback requested', 'transaction did not preserve the callback error');
	}
	check($pdo->query("SELECT COUNT(*) FROM export_state WHERE key = 'rollback-test'")->fetchColumn() == 0, 'transaction rollback failed');
	$generation = $database->allocateGeneration($pdo, 'query', 100);
	$database->writeCandidateBatch($pdo, $generation, [[
		'entry_id' => '100000000', 'feed_id' => '1', 'received_at' => 100,
		'embedding_text' => 'hello', 'source_hash' => 'source-a', 'normalized_title' => 'hello', 'exported_at' => 100,
	], [
		'entry_id' => '200000000', 'feed_id' => '1', 'received_at' => 300,
		'embedding_text' => 'newest', 'source_hash' => 'source-b', 'normalized_title' => 'newest', 'exported_at' => 300,
	], [
		'entry_id' => '300000000', 'feed_id' => '1', 'received_at' => 200,
		'embedding_text' => 'middle', 'source_hash' => 'source-c', 'normalized_title' => 'middle', 'exported_at' => 200,
	], [
		'entry_id' => '400000000', 'feed_id' => '1', 'received_at' => 400,
		'embedding_text' => 'similar', 'source_hash' => 'source-d', 'normalized_title' => 'similar', 'exported_at' => 400,
	], [
		'entry_id' => '500000000', 'feed_id' => '1', 'received_at' => 500,
		'embedding_text' => 'unknown', 'source_hash' => 'source-e', 'normalized_title' => 'unknown', 'exported_at' => 500,
	]]);
	check($pdo->query("SELECT normalized_title FROM candidate_members WHERE entry_id='100000000'")->fetchColumn() === 'hello', 'normalized candidate title was not persisted');
	check($pdo->query('SELECT COUNT(*) FROM pipeline_config')->fetchColumn() == 0, 'incomplete generation became active');
	// The model and threshold are explicit so this cross-language contract
	// (shared with worker/tests/test_config.py) does not move with the defaults.
	$config = SemanticGrouping_Config::effectiveWorkerConfig(SemanticGrouping_Config::merge([
		'candidate_source' => ['mode' => 'all_entries', 'query_id' => null, 'query_name' => 'All entries'],
		'embedding_model' => 'minishlab/potion-base-8M',
		'similarity_threshold' => 0.90,
	]), 'query-v1');
	check(SemanticGrouping_Config::embeddingFingerprint($config) === '735c7ecc39088db6c69eff6eecb21a27d5d78d31652f6b745d4c715eaf8dcfdf', 'PHP/Python embedding fingerprint contract changed');
	check(SemanticGrouping_Config::groupingFingerprint($config) === 'ead21a46c9b0161a3ecc806982bb2c5488ab79ebf60ff8051ad0e486d6368d99', 'PHP/Python grouping fingerprint contract changed');
	$invalidThreshold = $config;
	$invalidThreshold['similarity_threshold'] = NAN;
	check(SemanticGrouping_Config::validate($invalidThreshold) !== [], 'non-numeric similarity threshold was accepted');
	$defaults = SemanticGrouping_Config::defaults();
	check(isset(SemanticGrouping_Config::BUNDLED_MODELS[$defaults['embedding_model']]), 'Default embedding model is not bundled');
	check(SemanticGrouping_Config::modelChoices('minishlab/potion-base-8M') === SemanticGrouping_Config::BUNDLED_MODELS, 'Bundled model choices changed for a bundled current model');
	$customChoices = SemanticGrouping_Config::modelChoices('someone/custom-model');
	check(count($customChoices) === count(SemanticGrouping_Config::BUNDLED_MODELS) + 1 && isset($customChoices['someone/custom-model']), 'Stored custom model was not kept as a choice');
	check(SemanticGrouping_Config::modelChoices('  ') === SemanticGrouping_Config::BUNDLED_MODELS, 'Blank stored model became a choice');
	$containerfile = (string)file_get_contents(__DIR__ . '/../../packaging/Containerfile');
	$workerEmbeddings = (string)file_get_contents(__DIR__ . '/../../worker/src/freshrss_semantic/embeddings.py');
	foreach (array_keys(SemanticGrouping_Config::BUNDLED_MODELS) as $bundledModel) {
		check(str_contains($containerfile, '"' . basename($bundledModel) . '"'), "Worker image does not bundle {$bundledModel}");
		check(str_contains($workerEmbeddings, '"' . $bundledModel . '"'), "Worker does not load {$bundledModel} from its bundled copy");
	}
	check(!array_key_exists('minimum_group_size', $defaults), 'Minimum group size remains configurable');
	check(!array_key_exists('worker_interval_minutes', $defaults), 'Worker interval remains user-configurable');
	$legacyConfig = SemanticGrouping_Config::merge([
		'schema_version' => 2,
		'candidate_export_interval_minutes' => 11,
		'worker_interval_minutes' => 60,
		'minimum_group_size' => 99,
		'content_character_limit' => 5000,
		'embedding_batch_size' => 256,
	]);
	check($legacyConfig['candidate_export_interval_minutes'] === 20, 'Legacy refresh interval was not rounded to a worker boundary');
	check($legacyConfig['content_character_limit'] === 2000, 'Legacy content limit above the cap was not lowered to it');
	check($legacyConfig['embedding_batch_size'] === 256, 'A valid saved batch size was changed');
	check(SemanticGrouping_Config::validate($legacyConfig) === [], 'A legacy configuration no longer validates');
	check($defaults['content_character_limit'] === 2000 && $defaults['embedding_batch_size'] === 500, 'Content and batch defaults are not the measured maxima');
	foreach (['content_character_limit' => 2001, 'embedding_batch_size' => 501] as $key => $tooLarge) {
		$oversized = $legacyConfig;
		$oversized[$key] = $tooLarge;
		check(SemanticGrouping_Config::validate($oversized) !== [], "{$key} above its maximum was accepted");
	}
	check(!array_key_exists('worker_interval_minutes', $legacyConfig), 'Legacy worker interval was not removed');
	check(!array_key_exists('minimum_group_size', $legacyConfig), 'Legacy minimum group size was not removed');
	check($legacyConfig['schema_version'] === 3, 'Legacy configuration schema was not migrated');
	$invalidRefresh = $defaults;
	$invalidRefresh['candidate_export_interval_minutes'] = 15;
	check(SemanticGrouping_Config::validate($invalidRefresh) !== [], 'Refresh interval accepted a value between worker boundaries');
	check($defaults['candidate_source'] === [
		'mode' => 'all_entries',
		'query_id' => null,
		'query_name' => 'All entries',
	], 'All entries is not the default candidate source');
	check(SemanticGrouping_Config::validate($defaults) === [], 'default configuration did not validate');
	$disabledWithoutSource = $defaults;
	$disabledWithoutSource['candidate_source'] = ['mode' => 'saved_query', 'query_id' => null, 'query_name' => ''];
	$disabledWithoutSource['enabled'] = false;
	check(SemanticGrouping_Config::validate($disabledWithoutSource) === [], 'missing query prevented fail-safe pipeline disable');
	$enabledWithoutSource = $disabledWithoutSource;
	$enabledWithoutSource['enabled'] = true;
	check(SemanticGrouping_Config::validate($enabledWithoutSource) !== [], 'enabled pipeline accepted a missing query');
	$database->activateGeneration($pdo, $generation, 5, 'revision', $config, 10000, 100);
	check((int)$pdo->query('SELECT active_generation FROM pipeline_config')->fetchColumn() === $generation, 'generation activation failed');

	$fingerprint = SemanticGrouping_Config::groupingFingerprint($config);
	$pdo->exec("INSERT INTO groups(group_id, representative_entry_id, selection_generation, grouping_fingerprint, generated_at)
		VALUES ('group-a', '100000000', {$generation}, '{$fingerprint}', 100)");
	$pdo->exec("INSERT INTO group_members(group_id, entry_id) VALUES
		('group-a', '100000000'), ('group-a', '200000000')");
	$pdo->exec("INSERT INTO worker_state(key, value) VALUES
		('last_published_generation', '{$generation}'),
		('current_grouping_fingerprint', '{$fingerprint}')");
	$entries = [
		new FreshRSS_Entry('Old representative', '100000000', 100),
		new FreshRSS_Entry('Newest member', '200000000', 300),
		new FreshRSS_Entry('Middle singleton', '300000000', 200),
		new FreshRSS_Entry('Another singleton', '400000000', 400),
		new FreshRSS_Entry('Last singleton', '500000000', 500),
	];
	FreshRSS_Factory::$dao = new TestEntryDao($entries);
	$tagDao = new TestTagDao();
	$personalId = $tagDao->addTag(['name' => 'Personal']);
	check(is_int($personalId), 'personal label fixture was not created');
	// Two owned labels for one key: managed_labels picks the newer one,
	// which already has the right name, over the older one.
	$groupOwnership = [SemanticGrouping_LabelReconciler::OWNER_ATTRIBUTE => [
		'owner' => SemanticGrouping_LabelReconciler::OWNER_ID, 'semantic_key' => 'group-a',
	]];
	$staleCopyId = $tagDao->addTag(['name' => 'Stale copy', 'attributes' => $groupOwnership]);
	$preferredId = $tagDao->addTag(['name' => 'Old representative', 'attributes' => $groupOwnership]);
	check(is_int($staleCopyId) && is_int($preferredId) && $staleCopyId < $preferredId, 'duplicate owned label fixture was not created');
	$pdo->prepare("INSERT INTO managed_labels(semantic_key, label_id, label_name, selection_generation, updated_at) VALUES ('group-a', ?, 'Old representative', 0, 0)")
		->execute([$preferredId]);
	FreshRSS_Factory::$tagDao = $tagDao;
	$reconciler = new SemanticGrouping_LabelReconciler($database, $tagDao, FreshRSS_Factory::$dao);
	check($reconciler->reconcile(), 'initial native label reconciliation failed');
	$groupLabel = $tagDao->labelByName('Old representative');
	$singleLabel = $tagDao->labelByName('Single articles');
	check($groupLabel !== null && $singleLabel !== null, 'semantic labels were not created');
	check($groupLabel['id'] === $preferredId, 'the label recorded in managed_labels was not preferred');
	check($tagDao->labelByName('Stale copy') === null, 'the duplicate owned label was not retired');
	check($tagDao->labelsForEntry('100000000') === ['Old representative'], 'representative did not receive its group label');
	check($tagDao->labelsForEntry('200000000') === ['Old representative'], 'group member did not receive its group label');
	check($tagDao->labelsForEntry('300000000') === ['Single articles'], 'singleton did not receive the singleton label');
	check($tagDao->labelsForEntry('400000000') === ['Single articles'], 'second singleton did not receive the singleton label');
	check($tagDao->labelsForEntry('500000000') === ['Single articles'], 'third singleton did not receive the singleton label');
	check($tagDao->labelsForEntry('100000000') !== ['Personal'], 'personal label was populated by reconciliation');
	check((int)$pdo->query('SELECT COUNT(*) FROM managed_labels')->fetchColumn() === 2, 'managed label ownership was not persisted');
	$status = (new SemanticGrouping_GroupRepository($database))->status();
	check(empty($status['label_sync_pending']), 'completed label synchronization remained pending');

	// The published groups and their members are read in one query, ordered
	// by group and then entry, keeping a group without members.
	$pdo->exec("INSERT INTO groups(group_id, representative_entry_id, selection_generation, grouping_fingerprint, generated_at)
		VALUES ('group-b', '400000000', {$generation}, '{$fingerprint}', 100), ('group-0', '500000000', {$generation}, '{$fingerprint}', 100)");
	$pdo->exec("INSERT INTO group_members(group_id, entry_id) VALUES ('group-b', '400000000'), ('group-b', '300000000')");
	$snapshot = (new ReflectionMethod($reconciler, 'publishedSnapshot'))->invoke($reconciler);
	check(is_array($snapshot) && $snapshot['groups'] === [
		['group_id' => 'group-0', 'representative_entry_id' => '500000000', 'members' => []],
		['group_id' => 'group-a', 'representative_entry_id' => '100000000', 'members' => ['100000000', '200000000']],
		['group_id' => 'group-b', 'representative_entry_id' => '400000000', 'members' => ['300000000', '400000000']],
	], 'published groups were not read as ordered groups with their members');
	check($snapshot['candidate_ids'] === ['100000000', '200000000', '300000000', '400000000', '500000000'], 'published candidates were not read in order');
	check($snapshot['preferred_labels'] === ['group-a' => $preferredId, 'single-articles' => $singleLabel['id']], 'managed label mapping was not read with the publication');
	$pdo->exec("DELETE FROM groups WHERE group_id IN ('group-b', 'group-0')");

	$nextGeneration = static function () use ($database, $pdo, $generation): int {
		$next = $database->allocateGeneration($pdo, 'query', 200);
		$statement = $pdo->prepare('INSERT INTO candidate_members(generation, entry_id, source_hash) SELECT ?, entry_id, source_hash FROM candidate_members WHERE generation=?');
		$statement->execute([$next, $generation]);
		return $next;
	};
	$secondGeneration = $nextGeneration();
	$database->activateGeneration($pdo, $secondGeneration, 5, 'revision-2', $config, 10000, 200);
	$pdo->exec('DELETE FROM group_members');
	$pdo->exec('DELETE FROM groups');
	$pdo->exec("INSERT INTO groups(group_id, representative_entry_id, selection_generation, grouping_fingerprint, generated_at)
		VALUES ('group-a', '100000000', {$secondGeneration}, '{$fingerprint}', 200)");
	$pdo->exec("INSERT INTO group_members(group_id, entry_id) VALUES
		('group-a', '100000000'), ('group-a', '300000000')");
	$pdo->exec("UPDATE worker_state SET value='{$secondGeneration}' WHERE key='last_published_generation'");
	FreshRSS_Factory::$dao = new TestEntryDao([
		new FreshRSS_Entry('Renamed representative', '100000000', 100),
		new FreshRSS_Entry('Newest member', '200000000', 300),
		new FreshRSS_Entry('Middle singleton', '300000000', 200),
		new FreshRSS_Entry('Another singleton', '400000000', 400),
		new FreshRSS_Entry('Last singleton', '500000000', 500),
	]);
	$reconciler = new SemanticGrouping_LabelReconciler($database, $tagDao, FreshRSS_Factory::$dao);
	// Make the database unopenable between the first label write and the
	// final status write: reconciliation must not reopen it for each group.
	$setVersion = static function (int $version) use ($pdo): void {
		$pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
			? "UPDATE schema_version SET version = {$version}"
			: "PRAGMA user_version = {$version}");
	};
	$tagDao->afterAttributes = static function () use ($setVersion, $tagDao): void {
		$setVersion(99);
		$tagDao->afterAttributes = null;
		$tagDao->beforeSelectAll = static function () use ($setVersion, $tagDao): void {
			$setVersion(3);
			$tagDao->beforeSelectAll = null;
		};
	};
	check($reconciler->reconcile(), 'membership-change reconciliation failed');
	check($tagDao->afterAttributes === null && $tagDao->beforeSelectAll === null, 'label-write probe did not run');
	$renamed = $tagDao->labelByName('Renamed representative');
	check($renamed !== null && $renamed['id'] === $groupLabel['id'], 'existing group label was not renamed in place');
	check($tagDao->labelsForEntry('200000000') === ['Single articles'], 'former group member did not move to singletons');
	check($tagDao->labelsForEntry('300000000') === ['Renamed representative'], 'new group member retained a stale singleton assignment');

	// The group is re-keyed when its representative changes to another article
	// with the same title, such as a syndicated copy. The obsolete label still
	// holds that name, so the new key must take it over instead of creating a
	// second label with the same name, which FreshRSS rejects.
	$rekeyedGeneration = $nextGeneration();
	$database->activateGeneration($pdo, $rekeyedGeneration, 5, 'revision-rekeyed', $config, 10000, 250);
	$pdo->exec('DELETE FROM group_members');
	$pdo->exec('DELETE FROM groups');
	$pdo->exec("INSERT INTO groups(group_id, representative_entry_id, selection_generation, grouping_fingerprint, generated_at)
		VALUES ('group-rekeyed', '300000000', {$rekeyedGeneration}, '{$fingerprint}', 250)");
	$pdo->exec("INSERT INTO group_members(group_id, entry_id) VALUES
		('group-rekeyed', '300000000'), ('group-rekeyed', '200000000')");
	$pdo->exec("UPDATE worker_state SET value='{$rekeyedGeneration}' WHERE key='last_published_generation'");
	FreshRSS_Factory::$dao = new TestEntryDao([
		new FreshRSS_Entry('Renamed representative', '100000000', 100),
		new FreshRSS_Entry('Newest member', '200000000', 300),
		new FreshRSS_Entry('Renamed representative', '300000000', 200),
		new FreshRSS_Entry('Another singleton', '400000000', 400),
		new FreshRSS_Entry('Last singleton', '500000000', 500),
	]);
	$reconciler = new SemanticGrouping_LabelReconciler($database, $tagDao, FreshRSS_Factory::$dao);
	check($reconciler->reconcile(), 're-keyed group could not reuse the name of its obsolete label');
	$rekeyed = $tagDao->labelByName('Renamed representative');
	check($rekeyed !== null && $rekeyed['id'] === $groupLabel['id'], 're-keyed group did not take over the obsolete label');
	check(($rekeyed['attributes'][SemanticGrouping_LabelReconciler::OWNER_ATTRIBUTE]['semantic_key'] ?? null) === 'group-rekeyed', 'adopted label kept its former semantic key');
	check($tagDao->labelsForEntry('100000000') === ['Single articles'], 'former representative kept the re-keyed group label');
	check($tagDao->labelsForEntry('200000000') === ['Renamed representative'], 're-keyed group member did not receive the label');
	check($tagDao->labelsForEntry('300000000') === ['Renamed representative'], 'new representative did not receive the label');
	$secondGeneration = $rekeyedGeneration;

	$thirdGeneration = $database->allocateGeneration($pdo, 'query', 300);
	$statement = $pdo->prepare('INSERT INTO candidate_members(generation, entry_id, source_hash) SELECT ?, entry_id, source_hash FROM candidate_members WHERE generation=?');
	$statement->execute([$thirdGeneration, $secondGeneration]);
	$database->activateGeneration($pdo, $thirdGeneration, 5, 'revision-3', $config, 10000, 300);
	check(!$reconciler->reconcile(), 'labels changed before the worker published the active generation');
	check($tagDao->labelByName('Renamed representative') !== null, 'incomplete grouping retired the previous group label');
	$pdo->exec('DELETE FROM group_members');
	$pdo->exec('DELETE FROM groups');
	$pdo->exec("UPDATE worker_state SET value='{$thirdGeneration}' WHERE key='last_published_generation'");
	check($reconciler->reconcile(), 'empty-group retirement reconciliation failed');
	check($tagDao->labelByName('Renamed representative') === null, 'obsolete group label was not retired');
	foreach (['100000000', '200000000', '300000000', '400000000', '500000000'] as $entryId) {
		check($tagDao->labelsForEntry($entryId) === ['Single articles'], 'retired group member did not have exactly one singleton label');
	}

	$fourthGeneration = $database->allocateGeneration($pdo, 'query', 400);
	$statement = $pdo->prepare('INSERT INTO candidate_members(generation, entry_id, source_hash) SELECT ?, entry_id, source_hash FROM candidate_members WHERE generation=?');
	$statement->execute([$fourthGeneration, $thirdGeneration]);
	$database->activateGeneration($pdo, $fourthGeneration, 5, 'revision-4', $config, 10000, 400);
	$pdo->exec("INSERT INTO groups(group_id, representative_entry_id, selection_generation, grouping_fingerprint, generated_at)
		VALUES ('group-conflict', '100000000', {$fourthGeneration}, '{$fingerprint}', 400)");
	$pdo->exec("INSERT INTO group_members(group_id, entry_id) VALUES
		('group-conflict', '100000000'), ('group-conflict', '200000000')");
	$pdo->exec("UPDATE worker_state SET value='{$fourthGeneration}' WHERE key='last_published_generation'");
	FreshRSS_Factory::$dao = new TestEntryDao([
		new FreshRSS_Entry('Personal', '100000000', 100),
		new FreshRSS_Entry('Newest member', '200000000', 300),
		new FreshRSS_Entry('Middle singleton', '300000000', 200),
		new FreshRSS_Entry('Another singleton', '400000000', 400),
		new FreshRSS_Entry('Last singleton', '500000000', 500),
	]);
	$reconciler = new SemanticGrouping_LabelReconciler($database, $tagDao, FreshRSS_Factory::$dao);
	check(!$reconciler->reconcile(), 'personal-label name conflict did not fail synchronization');
	check($tagDao->labelsForEntry('100000000') === ['Single articles'], 'conflicted group commandeered or removed the prior managed assignment');
	check($tagDao->labelsForEntry('200000000') === ['Single articles'], 'conflicted group changed another prior managed assignment');
	check($tagDao->labelsForEntry('300000000') === ['Single articles'], 'unconflicted singleton was not preserved');
	check($tagDao->labelByName('Personal') !== null, 'personal label was renamed or deleted');
	$syncError = (string)$pdo->query("SELECT value FROM label_sync_state WHERE key='latest_error'")->fetchColumn();
	check(str_contains($syncError, 'personal label'), 'ownership conflict was not reported in synchronization status');

	$reconciler->removeManagedLabels();
	check(count($tagDao->tags) === 1 && $tagDao->labelByName('Personal') !== null, 'cleanup removed a personal label or retained managed labels');
	check(!$reconciler->reconcile(), 'first-pass ownership conflict did not remain visible as an error');
	foreach (['100000000', '200000000', '300000000', '400000000', '500000000'] as $entryId) {
		check($tagDao->labelsForEntry($entryId) === ['Single articles'], 'first-pass conflict left a candidate without exactly one managed label');
	}
	$reconciler->removeManagedLabels();
	return $pdo;
}

$directory = sys_get_temp_dir() . '/semantic-php-' . bin2hex(random_bytes(6));
mkdir($directory, 0770, true);
$path = $directory . '/semantic.sqlite';
$database = new SemanticGrouping_SemanticDatabase($path, '');
$pdo = exerciseDatabase($database);

$migratedSchema = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
$fixturePath = $directory . '/fixture.sqlite';
$fixture = new PDO('sqlite:' . $fixturePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fixtureSql = file_get_contents(__DIR__ . '/../../fixtures/semantic-schema-v3.sql');
check(is_string($fixtureSql), 'schema fixture is missing');
$fixture->exec($fixtureSql);
$fixtureSchema = $fixture->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
$normalizeSchema = static function (array $schema): array {
	return array_map(static function (array $row): array {
		$sql = preg_replace('/\s+/', ' ', trim((string)$row['sql']));
		return ['type' => $row['type'], 'name' => $row['name'], 'sql' => $sql];
	}, $schema);
};
check($normalizeSchema($migratedSchema) === $normalizeSchema($fixtureSchema), 'PHP migration and checked-in schema fixture differ');
$legacyFixturePath = $directory . '/legacy-fixture.sqlite';
$legacyFixture = new PDO('sqlite:' . $legacyFixturePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacyFixtureSql = file_get_contents(__DIR__ . '/../../fixtures/semantic-schema-v1.sql');
check(is_string($legacyFixtureSql), 'legacy schema fixture is missing');
$legacyFixture->exec($legacyFixtureSql);
$legacyFixture->exec("INSERT INTO pipeline_config VALUES (1, 1, 'legacy', 0, 0, '{}', 0)");
unset($legacyFixture);
$legacyDatabase = new SemanticGrouping_SemanticDatabase($legacyFixturePath, '');
$legacyPdo = $legacyDatabase->open(true);
check((int)$legacyPdo->query('PRAGMA user_version')->fetchColumn() === 3, 'version-one database was not migrated additively');
check((int)$legacyPdo->query('SELECT database_schema_version FROM pipeline_config')->fetchColumn() === 3, 'migrated pipeline row retained the old database schema version');
$legacySchema = $legacyPdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
check($normalizeSchema($legacySchema) === $normalizeSchema($migratedSchema), 'additive migration and fresh schema differ');
$versionTwoFixturePath = $directory . '/version-two-fixture.sqlite';
$versionTwoFixture = new PDO('sqlite:' . $versionTwoFixturePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$versionTwoFixtureSql = file_get_contents(__DIR__ . '/../../fixtures/semantic-schema-v2.sql');
check(is_string($versionTwoFixtureSql), 'version-two schema fixture is missing');
$versionTwoFixture->exec($versionTwoFixtureSql);
$versionTwoFixture->exec("INSERT INTO pipeline_config VALUES (1, 2, 'legacy', 0, 100, '{}', 0)");
$versionTwoFixture->exec("INSERT INTO export_state VALUES ('last_export_success', '100')");
unset($versionTwoFixture);
$versionTwoDatabase = new SemanticGrouping_SemanticDatabase($versionTwoFixturePath, '');
$versionTwoPdo = $versionTwoDatabase->open(true);
check((int)$versionTwoPdo->query('PRAGMA user_version')->fetchColumn() === 3, 'version-two database was not migrated additively');
check((int)$versionTwoPdo->query('SELECT database_schema_version FROM pipeline_config')->fetchColumn() === 3, 'version-two pipeline row retained the old database schema version');
check((int)$versionTwoPdo->query('SELECT producer_lease_until FROM pipeline_config')->fetchColumn() === 0, 'schema migration did not expire the producer lease');
check($versionTwoPdo->query("SELECT COUNT(*) FROM export_state WHERE key='last_export_success'")->fetchColumn() == 0, 'schema migration did not make candidate export immediately due');
$versionTwoSchema = $versionTwoPdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
check($normalizeSchema($versionTwoSchema) === $normalizeSchema($migratedSchema), 'version-two migration and fresh schema differ');

// Two migrators at once (FreshRSS install and cli/migrate.php): the second
// must read the version under the write lock, wait for the first, and then
// find nothing left to do instead of repeating the migration.
$racePath = $directory . '/race.sqlite';
$race = new PDO('sqlite:' . $racePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
$race->exec($versionTwoFixtureSql);
$race->exec('BEGIN IMMEDIATE');
$race->exec("ALTER TABLE candidate_members ADD COLUMN normalized_title TEXT NOT NULL DEFAULT ''");
$race->exec('PRAGMA user_version = 3');
$child = proc_open([
	PHP_BINARY, '-r',
	'require $argv[1]; try { (new SemanticGrouping_SemanticDatabase($argv[2], ""))->open(true); echo "migrated"; } catch (Throwable $e) { echo $e->getMessage(); exit(1); }',
	'--', __DIR__ . '/../xExtension-SemanticGrouping/Models/SemanticDatabase.php', $racePath,
], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $childPipes);
check(is_resource($child), 'concurrent migrator could not be started');
// Long enough for the second migrator to reach its version check.
usleep(500000);
$race->exec('COMMIT');
$childOutput = stream_get_contents($childPipes[1]) . stream_get_contents($childPipes[2]);
fclose($childPipes[1]);
fclose($childPipes[2]);
check(proc_close($child) === 0 && $childOutput === 'migrated', 'concurrent migration failed: ' . $childOutput);
$raceSchema = $race->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
check($normalizeSchema($raceSchema) === $normalizeSchema($migratedSchema), 'concurrent migration changed the schema twice');
unset($race);
$pdo->exec('PRAGMA user_version = 99');
unset($pdo);
$database->fullReset();
$pdo = $database->open(false);
check((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 3, 'full reset did not recover an incompatible schema version');
check((int)$pdo->query('SELECT COUNT(*) FROM pipeline_config')->fetchColumn() === 0, 'full reset retained pipeline rows');

unset($pdo);
unset($fixture);
unset($legacyPdo);
unset($versionTwoPdo);
@unlink($path);
@unlink($path . '-journal');
@unlink($directory . '/.semantic-worker.lock');
@unlink($directory . '/.semantic-label-' . substr(hash('sha256', 'test-user'), 0, 16) . '.lock');
@unlink($fixturePath);
@unlink($legacyFixturePath);
@unlink($versionTwoFixturePath);
@unlink($racePath);
@rmdir($directory);

[$dsn, $user, $password] = SemanticGrouping_SemanticDatabase::postgresDsn("postgresql://role%40x:p%40ss@[::1]:6543/semantic%20db?sslmode=require&options=-csearch_path%3Dsg"); // gitleaks:allow
check($dsn === "pgsql:connect_timeout='5' host='::1' port='6543' dbname='semantic db' sslmode='require' options='-csearch_path=sg'", 'PostgreSQL URL was not converted to a DSN');
check($user === 'role@x' && $password === 'p@ss', 'PostgreSQL URL credentials were not decoded');
[$dsn, $user, $password] = SemanticGrouping_SemanticDatabase::postgresDsn('postgres:///semantic');
check($dsn === "pgsql:connect_timeout='5' dbname='semantic'" && $user === null && $password === null, 'socket URL without credentials was not left to libpq');
foreach (['sqlite:///semantic', 'postgresql://host/db?bad-key=1', 'postgresql://host/db?application_name=a;b'] as $invalidUrl) {
	try {
		SemanticGrouping_SemanticDatabase::postgresDsn($invalidUrl);
		throw new LogicException('invalid PostgreSQL URL was accepted: ' . $invalidUrl);
	} catch (InvalidArgumentException) {
	}
}
check(SemanticGrouping_SemanticDatabase::lockKey('semantic-worker') === 0xdf51f117f5e3dde, 'PHP/Python advisory lock key contract changed');
check(!(new SemanticGrouping_SemanticDatabase($path, ''))->isPostgres(), 'an empty URL did not select SQLite');

// PostgreSQL runs the same scenario when SEMANTIC_TEST_POSTGRES_URL names a
// disposable database (make test/postgres starts one). Each run works in its
// own schema, selected through the URL as an operator could.
$postgresUrl = getenv('SEMANTIC_TEST_POSTGRES_URL');
if (is_string($postgresUrl) && $postgresUrl !== '') {
	[$adminDsn, $adminUser, $adminPassword] = SemanticGrouping_SemanticDatabase::postgresDsn($postgresUrl);
	$admin = new PDO($adminDsn, $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$schema = 'semantic_php_' . bin2hex(random_bytes(6));
	$fixtureSchema = $schema . '_fixture';
	$admin->exec("CREATE SCHEMA {$schema}");
	$admin->exec("CREATE SCHEMA {$fixtureSchema}");
	$schemaUrl = $postgresUrl . (str_contains($postgresUrl, '?') ? '&' : '?') . 'options=' . rawurlencode("-csearch_path={$schema}");
	try {
		$postgres = new SemanticGrouping_SemanticDatabase('/nonexistent/semantic.sqlite', $schemaUrl);
		check($postgres->isPostgres(), 'a PostgreSQL URL did not select PostgreSQL');
		try {
			$postgres->open(false);
			throw new LogicException('an empty PostgreSQL schema was opened without migration');
		} catch (RuntimeException $error) {
			check(str_contains($error->getMessage(), 'has not been created'), 'empty PostgreSQL schema was misreported');
		}
		$pgPdo = exerciseDatabase($postgres);
		check($postgres->open(true) instanceof PDO, 'repeated PostgreSQL migration was not idempotent');

		$describe = static function (PDO $db, string $name): array {
			$columns = $db->prepare("SELECT table_name, column_name, data_type, is_nullable, COALESCE(column_default, '') AS column_default
				FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, column_name");
			$columns->execute([$name]);
			$constraints = $db->prepare('SELECT cl.relname, pg_get_constraintdef(con.oid) AS definition FROM pg_constraint con
				JOIN pg_class cl ON cl.oid = con.conrelid JOIN pg_namespace n ON n.oid = con.connamespace
				WHERE n.nspname = ? ORDER BY 1, 2');
			$constraints->execute([$name]);
			$indexes = $db->prepare('SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = ? ORDER BY 1, 2');
			$indexes->execute([$name]);
			$text = json_encode([$columns->fetchAll(PDO::FETCH_ASSOC), $constraints->fetchAll(PDO::FETCH_ASSOC), $indexes->fetchAll(PDO::FETCH_ASSOC)]);
			return [str_replace($name . '.', '', (string)$text)];
		};
		$admin->exec("SET search_path TO {$fixtureSchema}");
		$pgFixtureSql = file_get_contents(__DIR__ . '/../../fixtures/semantic-schema-v3.postgres.sql');
		check(is_string($pgFixtureSql), 'PostgreSQL schema fixture is missing');
		$admin->exec($pgFixtureSql);
		$admin->exec('SET search_path TO DEFAULT');
		check($describe($admin, $schema) === $describe($admin, $fixtureSchema), 'PHP PostgreSQL migration and checked-in fixture differ');

		$workerLock = $postgres->tryLock(SemanticGrouping_SemanticDatabase::WORKER_LOCK);
		check($workerLock !== null, 'PostgreSQL worker lock was not acquired');
		check($postgres->tryLock(SemanticGrouping_SemanticDatabase::WORKER_LOCK) === null, 'PostgreSQL worker lock was acquired twice');
		$reconciler = new SemanticGrouping_LabelReconciler($postgres, FreshRSS_Factory::$tagDao, FreshRSS_Factory::$dao);
		check(!$reconciler->reconcile(), 'label reconciliation ran while the worker lock was held');
		try {
			$postgres->fullReset();
			throw new LogicException('full reset ran while the worker lock was held');
		} catch (RuntimeException $error) {
			check(str_contains($error->getMessage(), 'worker is active'), 'full reset contention was misreported');
		}
		$workerLock->release();
		$workerLock = $postgres->tryLock(SemanticGrouping_SemanticDatabase::WORKER_LOCK);
		check($workerLock !== null, 'released PostgreSQL worker lock could not be retaken');
		$workerLock->release();

		// A second writer waits for the first, as SQLite's BEGIN IMMEDIATE does.
		$holder = $postgres->open(false);
		$holder->exec('BEGIN');
		$holder->prepare('SELECT pg_advisory_xact_lock(?)')->execute([SemanticGrouping_SemanticDatabase::lockKey('semantic-write')]);
		$waiter = $postgres->open(false);
		$waiter->exec("SET lock_timeout = '100ms'");
		try {
			$postgres->recordExportState($waiter, 'blocked', 'yes');
			throw new LogicException('a second PostgreSQL writer did not wait for the first');
		} catch (PDOException $error) {
			check($error->getCode() === '55P03', 'blocked PostgreSQL writer failed for an unexpected reason');
		}
		$holder->exec('ROLLBACK');
		$postgres->recordExportState($waiter, 'blocked', 'no');
		check($pgPdo->query("SELECT value FROM export_state WHERE key = 'blocked'")->fetchColumn() === 'no', 'PostgreSQL writer did not proceed after the lock was released');

		$pgPdo->exec('UPDATE schema_version SET version = 99');
		try {
			$postgres->open(false);
			throw new LogicException('an incompatible PostgreSQL schema was opened');
		} catch (RuntimeException $error) {
			check(str_contains($error->getMessage(), 'incompatible'), 'incompatible PostgreSQL schema was misreported');
		}
		$pgPdo->exec('DROP TABLE schema_version');
		try {
			$postgres->open(true);
			throw new LogicException('unversioned PostgreSQL tables were migrated');
		} catch (RuntimeException $error) {
			check(str_contains($error->getMessage(), 'No migration path'), 'unversioned PostgreSQL tables were misreported');
		}
		$admin->exec("CREATE TABLE {$schema}.unrelated (id INTEGER)");
		$postgres->fullReset();
		$pgPdo = $postgres->open(false);
		check(SemanticGrouping_SemanticDatabase::schemaVersion($pgPdo) === 3, 'PostgreSQL full reset did not restore the schema');
		check((int)$pgPdo->query('SELECT COUNT(*) FROM pipeline_config')->fetchColumn() === 0, 'PostgreSQL full reset retained pipeline rows');
		check((int)$pgPdo->query('SELECT COUNT(*) FROM unrelated')->fetchColumn() === 0, 'PostgreSQL full reset dropped a table it does not own');
		unset($pgPdo, $holder, $waiter, $reconciler, $postgres);
	} finally {
		$admin->exec("DROP SCHEMA {$schema} CASCADE");
		$admin->exec("DROP SCHEMA {$fixtureSchema} CASCADE");
	}
	echo "extension PostgreSQL tests passed\n";
}

echo "extension tests passed\n";
