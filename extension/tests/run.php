<?php
declare(strict_types=1);

final class FreshRSS_BooleanSearch {
	public function __construct(string $unused) {}
}

final class FreshRSS_Context {
	public static FreshRSS_BooleanSearch $search;
}

final class FreshRSS_Entry {
	public const STATE_ALL = 15;
	public function __construct(private readonly string $value) {}
	public function title(): string { return $this->value; }
}

final class TestEntryDao {
	/** @param list<FreshRSS_Entry> $entries */
	public function __construct(private readonly array $entries) {}
	/** @return Traversable<FreshRSS_Entry> */
	public function listWhere(...$unused): Traversable { yield from $this->entries; }
}

final class FreshRSS_Factory {
	public static TestEntryDao $dao;
	public static function createEntryDao(): TestEntryDao { return self::$dao; }
}

function _url(...$unused): string {
	return '/semantic';
}

final class TestSemanticView {
	/** @var array<string,mixed> */
	public array $semanticGroups;
	/** @var array<string,mixed> */
	public array $semanticStatus;
	public string $semanticError = '';
	public function partial(string $unused): void {}
	public function render(): string {
		ob_start();
		include __DIR__ . '/../xExtension-SemanticGrouping/views/semantic/index.phtml';
		return (string)ob_get_clean();
	}
}

require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/Config.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/TextNormalizer.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/ExactTitleFilter.php';
require_once __DIR__ . '/../xExtension-SemanticGrouping/Models/SemanticDatabase.php';

FreshRSS_Context::$search = new FreshRSS_BooleanSearch('');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

check(SemanticGrouping_TextNormalizer::title("  HELLO\n world  ") === 'hello world', 'whitespace/lowercase normalization failed');
check(SemanticGrouping_TextNormalizer::title(" \t\n ") === '', 'blank title must stay non-deduplicable');
check(SemanticGrouping_TextNormalizer::title('A &amp; B') === 'a & b', 'HTML entity title normalization failed');
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

$directory = sys_get_temp_dir() . '/semantic-php-' . bin2hex(random_bytes(6));
mkdir($directory, 0770, true);
$path = $directory . '/semantic.sqlite';
$database = new SemanticGrouping_SemanticDatabase($path);
$pdo = $database->open(true);
check((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 1, 'database migration version failed');
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
$disabledWithoutSource = SemanticGrouping_Config::defaults();
$disabledWithoutSource['enabled'] = false;
check(SemanticGrouping_Config::validate($disabledWithoutSource) === [], 'missing query prevented fail-safe pipeline disable');
check(SemanticGrouping_Config::validate(SemanticGrouping_Config::defaults()) !== [], 'enabled pipeline accepted a missing query');
$database->activateGeneration($pdo, $generation, 1, 'revision', $config, 10000, 100);
check((int)$pdo->query('SELECT active_generation FROM pipeline_config')->fetchColumn() === $generation, 'generation activation failed');

$migratedSchema = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
$fixturePath = $directory . '/fixture.sqlite';
$fixture = new PDO('sqlite:' . $fixturePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fixtureSql = file_get_contents(__DIR__ . '/../../fixtures/semantic-schema-v1.sql');
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
$pdo->exec('PRAGMA user_version = 99');
unset($pdo);
$database->fullReset();
$pdo = $database->open(false);
check((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 1, 'full reset did not recover an incompatible schema version');
check((int)$pdo->query('SELECT COUNT(*) FROM pipeline_config')->fetchColumn() === 0, 'full reset retained pipeline rows');

$view = new TestSemanticView();
$view->semanticStatus = [
	'configured' => true,
	'pipeline' => ['active_generation' => 1],
	'candidate_count' => 2,
	'pending_embeddings' => 0,
	'grouping_pending' => false,
];
$view->semanticGroups = [
	'page' => 1,
	'pages' => 1,
	'total' => 1,
	'groups' => [[
		'id' => 'group',
		'generated_at' => 100,
		'members' => [[
			'id' => '1',
			'title' => '</a><script>alert(1)</script>',
			'url' => 'https://example.invalid/\" onmouseover=\"alert(1)',
			'date' => '<unsafe-date>',
			'is_read' => false,
			'is_favorite' => false,
			'excerpt' => '<img src=x onerror=alert(1)>',
			'similarity' => 1.0,
			'representative' => true,
		]],
	]],
];
$rendered = $view->render();
check(!str_contains($rendered, '<script>') && !str_contains($rendered, '<img src=x'), 'group view emitted feed-provided markup');
check(str_contains($rendered, '&lt;script&gt;') && str_contains($rendered, '&lt;unsafe-date&gt;'), 'group view did not escape feed-provided fields');

unset($pdo);
unset($fixture);
@unlink($path);
@unlink($path . '-journal');
@unlink($directory . '/.semantic-worker.lock');
@unlink($fixturePath);
@rmdir($directory);

echo "extension tests passed\n";
