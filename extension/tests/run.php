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
	public function selectAll(): Traversable { yield from array_values($this->tags); }
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

if (class_exists('Transliterator')) {
	FreshRSS_Factory::$dao = new TestEntryDao([new FreshRSS_Entry("Organizer behind \u{2018}moth\u{2019} demonstrations")]);
	$filter = new SemanticGrouping_ExactTitleFilter();
	check(
		$filter->filter(new FreshRSS_Entry("Organizer behind 'moth' demonstrations")) === null,
		'typographic quote variant of an existing title was accepted',
	);
}

$directory = sys_get_temp_dir() . '/semantic-php-' . bin2hex(random_bytes(6));
mkdir($directory, 0770, true);
$path = $directory . '/semantic.sqlite';
$database = new SemanticGrouping_SemanticDatabase($path);
$pdo = $database->open(true);
check((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 2, 'database migration version failed');
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
	'embedding_text' => 'hello', 'source_hash' => 'source-a', 'exported_at' => 100,
], [
	'entry_id' => '200000000', 'feed_id' => '1', 'received_at' => 300,
	'embedding_text' => 'newest', 'source_hash' => 'source-b', 'exported_at' => 300,
], [
	'entry_id' => '300000000', 'feed_id' => '1', 'received_at' => 200,
	'embedding_text' => 'middle', 'source_hash' => 'source-c', 'exported_at' => 200,
], [
	'entry_id' => '400000000', 'feed_id' => '1', 'received_at' => 400,
	'embedding_text' => 'similar', 'source_hash' => 'source-d', 'exported_at' => 400,
], [
	'entry_id' => '500000000', 'feed_id' => '1', 'received_at' => 500,
	'embedding_text' => 'unknown', 'source_hash' => 'source-e', 'exported_at' => 500,
]]);
check($pdo->query('SELECT COUNT(*) FROM pipeline_config')->fetchColumn() == 0, 'incomplete generation became active');
$config = SemanticGrouping_Config::effectiveWorkerConfig(SemanticGrouping_Config::merge([
	'candidate_source' => ['mode' => 'all_entries', 'query_id' => null, 'query_name' => 'All entries'],
]), 'query-v1');
check(SemanticGrouping_Config::embeddingFingerprint($config) === '735c7ecc39088db6c69eff6eecb21a27d5d78d31652f6b745d4c715eaf8dcfdf', 'PHP/Python embedding fingerprint contract changed');
check(SemanticGrouping_Config::groupingFingerprint($config) === 'e6d4cc696bdc21c3fc389f4a15ba7ccdc50d53d24c0ae8eb7bd79eae718171bf', 'PHP/Python grouping fingerprint contract changed');
$invalidThreshold = $config;
$invalidThreshold['similarity_threshold'] = NAN;
check(SemanticGrouping_Config::validate($invalidThreshold) !== [], 'non-numeric similarity threshold was accepted');
$defaults = SemanticGrouping_Config::defaults();
check(!array_key_exists('minimum_group_size', $defaults), 'Minimum group size remains configurable');
check(!array_key_exists('worker_interval_minutes', $defaults), 'Worker interval remains user-configurable');
$legacyConfig = SemanticGrouping_Config::merge([
	'schema_version' => 2,
	'candidate_export_interval_minutes' => 11,
	'worker_interval_minutes' => 60,
	'minimum_group_size' => 99,
]);
check($legacyConfig['candidate_export_interval_minutes'] === 20, 'Legacy refresh interval was not rounded to a worker boundary');
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
FreshRSS_Factory::$tagDao = $tagDao;
$reconciler = new SemanticGrouping_LabelReconciler($database, $tagDao, FreshRSS_Factory::$dao);
check($reconciler->reconcile(), 'initial native label reconciliation failed');
$groupLabel = $tagDao->labelByName('Old representative');
$singleLabel = $tagDao->labelByName('Single articles');
check($groupLabel !== null && $singleLabel !== null, 'semantic labels were not created');
check($tagDao->labelsForEntry('100000000') === ['Old representative'], 'representative did not receive its group label');
check($tagDao->labelsForEntry('200000000') === ['Old representative'], 'group member did not receive its group label');
check($tagDao->labelsForEntry('300000000') === ['Single articles'], 'singleton did not receive the singleton label');
check($tagDao->labelsForEntry('400000000') === ['Single articles'], 'second singleton did not receive the singleton label');
check($tagDao->labelsForEntry('500000000') === ['Single articles'], 'third singleton did not receive the singleton label');
check($tagDao->labelsForEntry('100000000') !== ['Personal'], 'personal label was populated by reconciliation');
check((int)$pdo->query('SELECT COUNT(*) FROM managed_labels')->fetchColumn() === 2, 'managed label ownership was not persisted');
$status = (new SemanticGrouping_GroupRepository($database))->status();
check(empty($status['label_sync_pending']), 'completed label synchronization remained pending');

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
check($reconciler->reconcile(), 'membership-change reconciliation failed');
$renamed = $tagDao->labelByName('Renamed representative');
check($renamed !== null && $renamed['id'] === $groupLabel['id'], 'existing group label was not renamed in place');
check($tagDao->labelsForEntry('200000000') === ['Single articles'], 'former group member did not move to singletons');
check($tagDao->labelsForEntry('300000000') === ['Renamed representative'], 'new group member retained a stale singleton assignment');

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

$migratedSchema = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
$fixturePath = $directory . '/fixture.sqlite';
$fixture = new PDO('sqlite:' . $fixturePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fixtureSql = file_get_contents(__DIR__ . '/../../fixtures/semantic-schema-v2.sql');
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
$legacyDatabase = new SemanticGrouping_SemanticDatabase($legacyFixturePath);
$legacyPdo = $legacyDatabase->open(true);
check((int)$legacyPdo->query('PRAGMA user_version')->fetchColumn() === 2, 'version-one database was not migrated additively');
check((int)$legacyPdo->query('SELECT database_schema_version FROM pipeline_config')->fetchColumn() === 2, 'migrated pipeline row retained the old database schema version');
$legacySchema = $legacyPdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
check($normalizeSchema($legacySchema) === $normalizeSchema($migratedSchema), 'additive migration and fresh schema differ');
$pdo->exec('PRAGMA user_version = 99');
unset($pdo);
$database->fullReset();
$pdo = $database->open(false);
check((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 2, 'full reset did not recover an incompatible schema version');
check((int)$pdo->query('SELECT COUNT(*) FROM pipeline_config')->fetchColumn() === 0, 'full reset retained pipeline rows');

unset($pdo);
unset($fixture);
unset($legacyPdo);
@unlink($path);
@unlink($path . '-journal');
@unlink($directory . '/.semantic-worker.lock');
@unlink($directory . '/.semantic-label-' . substr(hash('sha256', 'test-user'), 0, 16) . '.lock');
@unlink($fixturePath);
@unlink($legacyFixturePath);
@rmdir($directory);

echo "extension tests passed\n";
