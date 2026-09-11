<?php
declare(strict_types=1);

/**
 * Runtime contract test for the pinned FreshRSS release.
 *
 * Invoke from the FreshRSS application root inside the official container:
 *   php /integration/freshrss.php setup
 *   php /integration/freshrss.php verify-exact-disabled
 *   php /integration/freshrss.php verify-pipeline-disabled-exact
 *   php /integration/freshrss.php verify-groups
 *   php /integration/freshrss.php verify-page
 *   php /integration/freshrss.php update-entry
 *   php /integration/freshrss.php change-query
 *   php /integration/freshrss.php verify-empty-groups
 *   php /integration/freshrss.php disable-pipeline
 */

if (!is_file(getcwd() . '/cli/_cli.php')) {
	fwrite(STDERR, "Run this test from the FreshRSS application root.\n");
	exit(2);
}

require getcwd() . '/cli/_cli.php';

const SEMANTIC_EXTENSION_NAME = 'Semantic grouping';
const SEMANTIC_FIXTURE_PATH = '/semantic-data/integration-fixture.json';
const SEMANTIC_DATABASE_PATH = '/semantic-data/semantic.sqlite';

/** @param mixed $actual @param mixed $expected */
function same($actual, $expected, string $message): void {
	if ($actual !== $expected) {
		throw new RuntimeException($message . '; expected ' . json_encode($expected) . ', got ' . json_encode($actual));
	}
}

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function extensionInstance(): SemanticGroupingExtension {
	$extension = Minz_ExtensionManager::findExtension(SEMANTIC_EXTENSION_NAME);
	if (!$extension instanceof SemanticGroupingExtension) {
		throw new RuntimeException('Semantic grouping extension was not discovered.');
	}
	return $extension;
}

/** @return array<string,mixed> */
function fixture(): array {
	$raw = file_get_contents(SEMANTIC_FIXTURE_PATH);
	if (!is_string($raw)) {
		throw new RuntimeException('Integration fixture state is missing.');
	}
	$value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
	if (!is_array($value)) {
		throw new RuntimeException('Integration fixture state is invalid.');
	}
	return $value;
}

/** @param array<string,mixed> $value */
function saveFixture(array $value): void {
	file_put_contents(
		SEMANTIC_FIXTURE_PATH,
		json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
		LOCK_EX,
	);
	chmod(SEMANTIC_FIXTURE_PATH, 0660);
}

/** @return PDO */
function semanticDatabase(): PDO {
	return new PDO('sqlite:' . SEMANTIC_DATABASE_PATH, null, null, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
}

/** @return array<string,string> */
function candidateHashes(PDO $database, int $generation): array {
	$statement = $database->prepare('SELECT entry_id, source_hash FROM candidate_members WHERE generation=? ORDER BY entry_id');
	$statement->execute([$generation]);
	$result = [];
	foreach ($statement as $row) {
		$result[(string)$row['entry_id']] = (string)$row['source_hash'];
	}
	return $result;
}

/** @return array<string,mixed> */
function entryValues(
	string $id,
	int $feedId,
	string $guid,
	string $title,
	string $content,
	int $date,
	bool $read = false,
	bool $favorite = false,
): array {
	return [
		'id' => $id,
		'guid' => $guid,
		'title' => $title,
		'author' => 'Integration Author',
		'content' => $content,
		'link' => 'https://example.invalid/articles/' . rawurlencode($guid),
		'date' => $date,
		'lastSeen' => $date,
		'hash' => md5($guid . $title . $content),
		'is_read' => $read,
		'is_favorite' => $favorite,
		'id_feed' => $feedId,
		'tags' => '#integration',
		'attributes' => [],
	];
}

/** @param array<string,mixed> $query @param array<string,mixed> $baseConfig @return list<string> */
function selectedIdsForQuery(array $query, array $baseConfig, int $now): array {
	$configuration = FreshRSS_Context::userConf();
	$configuration->queries = [$query];
	$config = $baseConfig;
	$config['candidate_source'] = [
		'mode' => 'saved_query',
		'query_id' => 0,
		'query_name' => (string)$query['name'],
	];
	$source = SemanticGrouping_CandidateSource::resolve($config, $now);
	$selected = [];
	foreach ($source->entries() as $entry) {
		$selected[] = $entry->id();
	}
	sort($selected);
	return $selected;
}

/** @return array<string,mixed> */
function pipelineConfig(): array {
	return SemanticGrouping_Config::merge([
		'enabled' => true,
		'candidate_source' => [
			'mode' => 'saved_query',
			'query_id' => 0,
			'query_name' => 'Integration candidates',
		],
		'embedding_model' => 'minishlab/potion-base-8M',
		'similarity_threshold' => 0.25,
		'window_hours' => 72,
		'candidate_export_interval_minutes' => 30,
		'minimum_group_size' => 2,
		'include_title' => true,
		'include_content' => false,
		'content_character_limit' => 2000,
		'exact_title_enabled' => true,
		'embedding_batch_size' => 128,
	]);
}

function configureWhileDisabled(): void {
	FreshRSS_Context::initUser('admin');
	$extension = extensionInstance();
	check(!$extension->isEnabled(), 'Extension unexpectedly enabled before its configuration regression test.');
	$params = [
		'candidate_source' => 'all_entries',
		'enabled' => '1',
		'embedding_model' => 'integration/pre-enable',
		'similarity_threshold' => '0.75',
		'window_hours' => '48',
		'candidate_export_interval_minutes' => '20',
		'minimum_group_size' => '3',
		'include_title' => '1',
		'content_character_limit' => '1234',
		'exact_title_enabled' => '1',
		'embedding_batch_size' => '64',
		'force_rebuild_token' => '',
	];
	$previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
	$previousParams = Minz_Request::params();
	try {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Minz_Request::_params($params);
		$extension->handleConfigureAction();
		same($extension->configurationErrors, [], 'Saving configuration while disabled failed');
		same($extension->configuration['candidate_source']['mode'], 'all_entries', 'All entries was not saved before enabling');
		same($extension->configuration['embedding_model'], 'integration/pre-enable', 'Saved settings were reset before enabling');
		same($extension->configuration['force_rebuild_token'], '', 'Saving unexpectedly changed the rebuild token');
		FreshRSS_Context::initUser('admin');
		$stored = FreshRSS_Context::userConf()->extensions[SEMANTIC_EXTENSION_NAME] ?? null;
		check(is_array($stored), 'Configuration was not persisted through FreshRSS');
		same($stored, $extension->configuration, 'Reloaded FreshRSS configuration differs from the submitted settings');
	} finally {
		Minz_Request::_params($previousParams);
		if ($previousMethod === null) {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $previousMethod;
		}
	}
}

function installAndEnable(): SemanticGroupingExtension {
	FreshRSS_Context::initUser('admin');
	$extension = extensionInstance();
	$result = $extension->install();
	check($result === true, 'Extension installation failed: ' . (string)$result);

	$configuration = FreshRSS_Context::userConf();
	$enabled = $configuration->extensions_enabled;
	$enabled[SEMANTIC_EXTENSION_NAME] = true;
	$configuration->extensions_enabled = $enabled;
	$configuration->save();
	Minz_ExtensionManager::enableByList([SEMANTIC_EXTENSION_NAME => true], 'user');
	check($extension->isEnabled(), 'Extension did not enable.');
	return $extension;
}

function configuredExtension(): SemanticGroupingExtension {
	cliInitUser('admin');
	$extension = extensionInstance();
	check($extension->isEnabled(), 'Extension is not enabled for the integration user.');
	return $extension;
}

function setup(): void {
	configureWhileDisabled();
	$extension = installAndEnable();
	$now = time();
	$categoryDao = FreshRSS_Factory::createCategoryDao();
	$categories = $categoryDao->listCategories(prePopulateFeeds: false, details: false);
	if ($categories === []) {
		$categoryId = $categoryDao->addCategory(['name' => 'Integration']);
		check(is_int($categoryId), 'Could not create integration category.');
	} else {
		$categoryId = (int)reset($categories)->id();
	}

	$feedDao = FreshRSS_Factory::createFeedDao();
	$feedId = $feedDao->addFeed([
		'url' => 'https://example.invalid/integration.xml',
		'kind' => FreshRSS_Feed::KIND_RSS,
		'category' => $categoryId,
		'name' => 'Integration included feed',
		'website' => 'https://example.invalid/',
		'description' => 'Semantic integration fixtures',
		'lastUpdate' => $now,
		'error' => false,
	]);
	$otherFeedId = $feedDao->addFeed([
		'url' => 'https://other.example.invalid/integration.xml',
		'kind' => FreshRSS_Feed::KIND_RSS,
		'category' => $categoryId,
		'name' => 'Integration excluded feed',
		'website' => 'https://other.example.invalid/',
		'description' => 'Out-of-scope integration fixtures',
		'lastUpdate' => $now,
		'error' => false,
	]);
	check(is_int($feedId) && is_int($otherFeedId), 'Could not create integration feeds.');

	$base = $now * 1_000_000;
	$ids = [
		'first' => (string)($base + 101),
		'second' => (string)($base + 102),
		'text_excluded' => (string)($base + 103),
		'old' => (string)(($now - 8 * 24 * 3600) * 1_000_000 + 104),
		'feed_excluded' => (string)($base + 105),
	];
	$entryDao = FreshRSS_Factory::createEntryDao();
	$rows = [
		entryValues($ids['first'], $feedId, 'included-1', 'Semantic city council approves climate plan', '<p>First version</p>', $now - 60),
		entryValues($ids['second'], $feedId, 'included-2', 'Semantic climate plan approved by city council', '<p>Near duplicate</p>', $now - 50, true, true),
		entryValues($ids['text_excluded'], $feedId, 'excluded-title', 'Ordinary local sports report', '<p>Different topic</p>', $now - 40),
		entryValues($ids['old'], $feedId, 'old-entry', 'Semantic historic council plan', '<p>Outside rolling window</p>', $now - 8 * 24 * 3600),
		entryValues($ids['feed_excluded'], $otherFeedId, 'excluded-feed', 'Semantic city council approves climate plan elsewhere', '<p>Wrong feed</p>', $now - 30),
	];
	foreach ($rows as $row) {
		check($entryDao->addEntry($row, useTmpTable: false), 'Could not insert FreshRSS fixture ' . $row['guid']);
	}
	$tagDao = FreshRSS_Factory::createTagDao();
	$labelId = $tagDao->addTag(['name' => 'Integration label']);
	check(is_int($labelId) && $tagDao->tagEntry($labelId, $ids['first']), 'Could not label the integration fixture.');

	$recentIncludedFeed = [$ids['first'], $ids['second'], $ids['text_excluded']];
	sort($recentIncludedFeed);
	$allRecent = [$ids['first'], $ids['second'], $ids['text_excluded'], $ids['feed_excluded']];
	sort($allRecent);
	$unreadRecent = [$ids['first'], $ids['text_excluded'], $ids['feed_excluded']];
	sort($unreadRecent);
	$baseConfig = pipelineConfig();
	$nativeQueries = [
		'feed' => [
			['name' => 'Feed query', 'get' => 'f_' . $feedId],
			$recentIncludedFeed,
		],
		'category' => [
			['name' => 'Category query', 'get' => 'c_' . $categoryId],
			$allRecent,
		],
		'title' => [
			['name' => 'Title query', 'search' => 'intitle:Semantic'],
			[$ids['first'], $ids['second'], $ids['feed_excluded']],
		],
		'content' => [
			['name' => 'Content query', 'search' => "'Near duplicate'"],
			[$ids['second']],
		],
		'feed tag' => [
			['name' => 'Feed-tag query', 'search' => '#integration'],
			$allRecent,
		],
		'label' => [
			['name' => 'Label query', 'get' => 't_' . $labelId],
			[$ids['first']],
		],
		'unread status' => [
			['name' => 'Unread query', 'state' => FreshRSS_Entry::STATE_NOT_READ],
			$unreadRecent,
		],
		'favorite status' => [
			['name' => 'Favorite query', 'state' => FreshRSS_Entry::STATE_FAVORITE],
			[$ids['second']],
		],
		'date' => [
			['name' => 'Date query', 'search' => 'date:' . gmdate('Y-m-d\\TH:i:s\\Z', $now - 120) . '/'],
			$allRecent,
		],
		'nested OR and negation' => [
			['name' => 'Boolean query', 'search' => '(intitle:Semantic OR intitle:Ordinary) -intitle:historic'],
			$allRecent,
		],
	];
	foreach ($nativeQueries as $kind => [$query, $expectedIds]) {
		$expectedIds = array_map(static fn(int|string $id): string => (string)$id, $expectedIds);
		sort($expectedIds);
		same(selectedIdsForQuery($query, $baseConfig, $now), $expectedIds, 'Pinned FreshRSS ' . $kind . ' query behavior changed');
	}
	$invalidConfig = $baseConfig;
	$invalidConfig['candidate_source'] = ['mode' => 'saved_query', 'query_id' => 0, 'query_name' => 'Ambiguous'];
	$userConfiguration = FreshRSS_Context::userConf();
	$userConfiguration->queries = [
		['name' => 'Ambiguous', 'search' => 'intitle:Semantic'],
		['name' => 'Ambiguous', 'search' => 'intitle:Ordinary'],
	];
	try {
		SemanticGrouping_CandidateSource::resolve($invalidConfig, $now);
		throw new RuntimeException('Ambiguous saved query widened selection.');
	} catch (InvalidArgumentException) {
		// Expected fail-closed result.
	}
	$invalidConfig['candidate_source'] = ['mode' => 'saved_query', 'query_id' => 0, 'query_name' => 'Empty'];
	$userConfiguration->queries = [['name' => 'Empty']];
	try {
		SemanticGrouping_CandidateSource::resolve($invalidConfig, $now);
		throw new RuntimeException('Empty saved query widened selection.');
	} catch (InvalidArgumentException) {
		// Expected fail-closed result.
	}

	// Configure after feeds exist so FreshRSS's category cache expands f:<id>
	// exactly as the reader does.
	$userConfiguration = FreshRSS_Context::userConf();
	$userConfiguration->queries = [[
		'name' => 'Integration candidates',
		'get' => 'f_' . $feedId,
		'search' => 'intitle:Semantic',
	]];
	$extensions = $userConfiguration->extensions;
	$extensions[SEMANTIC_EXTENSION_NAME] = pipelineConfig();
	$userConfiguration->extensions = $extensions;
	$userConfiguration->save();

	$config = $extension->loadConfiguration();
	same(SemanticGrouping_Config::validate($config), [], 'Stored configuration did not validate');
	check(str_contains(Minz_ExtensionManager::callHookString(Minz_HookType::MenuOtherEntry), 'Semantic Groups'), 'Menu hook was not registered');

	$duplicate = new FreshRSS_Entry($feedId, 'incoming-duplicate', '  SEMANTIC city council approves climate plan  ');
	same(Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $duplicate), null, 'Existing normalized-title duplicate was accepted');
	$unique = new FreshRSS_Entry($feedId, 'incoming-unique', 'Semantic unique same-batch headline');
	same(Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $unique), $unique, 'Unique incoming title was rejected');
	same(
		Minz_ExtensionManager::callHook(
			Minz_HookType::EntryBeforeAdd,
			new FreshRSS_Entry($feedId, 'incoming-same-batch', 'semantic UNIQUE same-batch headline'),
		),
		null,
		'Same-refresh normalized-title duplicate was accepted',
	);

	$sourceConfig = $config;
	$source = SemanticGrouping_CandidateSource::resolve($sourceConfig, $now);
	$selected = [];
	foreach ($source->entries() as $entry) {
		$selected[] = $entry->id();
	}
	sort($selected);
	$expected = [$ids['first'], $ids['second']];
	sort($expected);
	same($selected, $expected, 'Pinned FreshRSS native query or rolling-window selection changed');

	$allConfig = $config;
	$allConfig['candidate_source'] = ['mode' => 'all_entries', 'query_id' => null, 'query_name' => 'All entries'];
	$allSource = SemanticGrouping_CandidateSource::resolve($allConfig, $now);
	$allSelected = iterator_to_array($allSource->entries(), false);
	same(count($allSelected), 4, 'Explicit All entries source did not include every recent entry');

	$missingConfig = $config;
	$missingConfig['candidate_source'] = ['mode' => 'saved_query', 'query_id' => 999, 'query_name' => 'Missing'];
	try {
		SemanticGrouping_CandidateSource::resolve($missingConfig, $now);
		throw new RuntimeException('Missing saved query widened selection.');
	} catch (InvalidArgumentException) {
		// Expected fail-closed result.
	}

	$exporter = new SemanticGrouping_CandidateExporter($config);
	check($exporter->export(force: true), 'Initial candidate export did not run.');
	Minz_ExtensionManager::callHookVoid(Minz_HookType::FreshrssUserMaintenance);
	$database = semanticDatabase();
	$pipeline = $database->query('SELECT * FROM pipeline_config WHERE singleton=1')->fetch();
	check(is_array($pipeline), 'Pipeline configuration was not published.');
	$generation = (int)$pipeline['active_generation'];
	same((int)$database->query('SELECT candidate_count FROM candidate_generations WHERE generation=' . $generation)->fetchColumn(), 2, 'Wrong active candidate count');
	check((int)$pipeline['producer_lease_until'] >= $now + 90 * 60, 'Producer lease was not renewed to the minimum duration.');

	$activeBeforeFailure = $generation;
	(new SemanticGrouping_CandidateExporter($missingConfig))->runSafely(force: true);
	same((int)$database->query('SELECT active_generation FROM pipeline_config WHERE singleton=1')->fetchColumn(), $activeBeforeFailure, 'Failed export changed the active generation');

	saveFixture([
		'feed_id' => $feedId,
		'other_feed_id' => $otherFeedId,
		'ids' => $ids,
		'generation' => $generation,
		'hashes' => candidateHashes($database, $generation),
	]);
}

function verifyGroups(): void {
	configuredExtension();
	$state = fixture();
	$repository = new SemanticGrouping_GroupRepository();
	$status = $repository->status();
	same((int)$status['pending_embeddings'], 0, 'Worker left active candidate embeddings pending');
	same((int)$status['worker']['last_published_generation'], (int)$state['generation'], 'Worker published the wrong generation');
	$result = $repository->groups(1);
	same(count($result['groups']), 1, 'Expected one semantic group');
	$ids = array_map(static fn(array $member): string => (string)$member['id'], $result['groups'][0]['members']);
	sort($ids);
	$expected = [(string)$state['ids']['first'], (string)$state['ids']['second']];
	sort($expected);
	same($ids, $expected, 'Grouped page did not resolve the current FreshRSS entries');
}

function verifyPage(): void {
	configuredExtension();
	Minz_Session::_param('passwordHash', FreshRSS_Context::userConf()->passwordHash);
	check(FreshRSS_Auth::giveAccess(), 'Could not authenticate the semantic page render.');

	$previousRequest = Minz_Request::currentRequest();
	try {
		Minz_Request::_controllerName('semantic');
		Minz_Request::_actionName('index');
		Minz_Request::_params([]);
		require_once __DIR__ . '/Controllers/semanticController.php';
		$controller = new FreshExtension_semantic_Controller();
		$controller->firstAction();
		$controller->indexAction();
		$rendered = $controller->view()->renderToString();
	} finally {
		Minz_Request::_controllerName($previousRequest['c']);
		Minz_Request::_actionName($previousRequest['a']);
		Minz_Request::_params($previousRequest['params']);
	}

	check(str_contains($rendered, 'id="aside_feed"'), 'Authenticated semantic page did not render the feed sidebar.');
	check(str_contains($rendered, 'Integration label'), 'Authenticated semantic page did not render FreshRSS labels.');
	check(str_contains($rendered, 'Integration included feed'), 'Authenticated semantic page did not render FreshRSS categories.');
	check(str_contains($rendered, '<h1>Semantic Groups</h1>'), 'Authenticated semantic page did not render its heading.');
	check(str_contains($rendered, 'Semantic city council approves climate plan'), 'Authenticated semantic page did not render published groups.');
}

function verifyExactDisabled(): void {
	$extension = configuredExtension();
	$configuration = FreshRSS_Context::userConf();
	$extensions = $configuration->extensions;
	$original = $extensions[SEMANTIC_EXTENSION_NAME];
	$extensions[SEMANTIC_EXTENSION_NAME]['exact_title_enabled'] = false;
	$configuration->extensions = $extensions;
	$configuration->save();
	try {
		$duplicate = new FreshRSS_Entry(
			(int)fixture()['feed_id'],
			'exact-disabled',
			'SEMANTIC CITY COUNCIL APPROVES CLIMATE PLAN',
		);
		same($extension->entryBeforeAdd($duplicate), $duplicate, 'Disabled exact-title filter rejected an entry');
	} finally {
		$extensions[SEMANTIC_EXTENSION_NAME] = $original;
		$configuration->extensions = $extensions;
		$configuration->save();
	}
}

function verifyPipelineDisabledExact(): void {
	$extension = configuredExtension();
	$configuration = FreshRSS_Context::userConf();
	$extensions = $configuration->extensions;
	$original = $extensions[SEMANTIC_EXTENSION_NAME];
	$extensions[SEMANTIC_EXTENSION_NAME]['enabled'] = false;
	$extensions[SEMANTIC_EXTENSION_NAME]['exact_title_enabled'] = true;
	$configuration->extensions = $extensions;
	$configuration->save();
	try {
		$duplicate = new FreshRSS_Entry(
			(int)fixture()['feed_id'],
			'pipeline-disabled-exact',
			'SEMANTIC CITY COUNCIL APPROVES CLIMATE PLAN',
		);
		same($extension->entryBeforeAdd($duplicate), null, 'Pipeline switch disabled the independent exact-title filter');
	} finally {
		$extensions[SEMANTIC_EXTENSION_NAME] = $original;
		$configuration->extensions = $extensions;
		$configuration->save();
	}
}

function updateEntry(): void {
	$extension = configuredExtension();
	$state = fixture();
	$entryId = (string)$state['ids']['second'];
	$entries = iterator_to_array(FreshRSS_Factory::createEntryDao()->listByIds([$entryId]), false);
	same(count($entries), 1, 'FreshRSS entry to update was not found');
	$entry = $entries[0];
	$now = time();
	$values = entryValues(
		$entryId,
		(int)$state['feed_id'],
		'included-2',
		'Semantic climate policy approved by the city council',
		'<p>Updated integration content</p>',
		(int)$entry->date(raw: true),
		(bool)$entry->isRead(),
	);
	$values['lastModified'] = $now;
	check(FreshRSS_Factory::createEntryDao()->updateEntry($values), 'FreshRSS fixture update failed.');

	$config = $extension->loadConfiguration();
	check((new SemanticGrouping_CandidateExporter($config))->export(force: true), 'Updated candidate export did not run.');
	$database = semanticDatabase();
	$newGeneration = (int)$database->query('SELECT active_generation FROM pipeline_config WHERE singleton=1')->fetchColumn();
	check($newGeneration > (int)$state['generation'], 'Updated source did not create a new generation.');
	$newHashes = candidateHashes($database, $newGeneration);
	$changed = [];
	foreach ($newHashes as $id => $hash) {
		if (($state['hashes'][$id] ?? null) !== $hash) {
			$changed[] = (string)$id;
		}
	}
	same($changed, [$entryId], 'Source update did not invalidate exactly one candidate version');
	$state['generation'] = $newGeneration;
	$state['hashes'] = $newHashes;
	saveFixture($state);
}

function changeQuery(): void {
	$extension = configuredExtension();
	$state = fixture();
	$configuration = FreshRSS_Context::userConf();
	$queries = $configuration->queries;
	$alreadyChanged = ($queries[0]['search'] ?? '') === 'intitle:Ordinary';
	$queries[0]['search'] = 'intitle:Ordinary';
	$configuration->queries = $queries;
	$configuration->save();

	$config = $extension->loadConfiguration();
	$oldRevision = (string)semanticDatabase()->query('SELECT config_revision FROM pipeline_config WHERE singleton=1')->fetchColumn();
	check((new SemanticGrouping_CandidateExporter($config))->export(force: true), 'Edited-query export did not run.');
	$database = semanticDatabase();
	$pipeline = $database->query('SELECT active_generation, config_revision FROM pipeline_config WHERE singleton=1')->fetch();
	check(is_array($pipeline), 'Edited query did not publish pipeline configuration.');
	check((int)$pipeline['active_generation'] > (int)$state['generation'], 'Edited query did not create a new generation.');
	if (!$alreadyChanged) {
		check((string)$pipeline['config_revision'] !== $oldRevision, 'Edited query did not change the configuration revision.');
	}
	$hashes = candidateHashes($database, (int)$pipeline['active_generation']);
	$selectedIds = array_map(static fn(int|string $id): string => (string)$id, array_keys($hashes));
	same($selectedIds, [(string)$state['ids']['text_excluded']], 'Edited native query selected the wrong entries');
	// Publication is intentionally deferred: previous groups remain visible
	// until the worker completes the replacement generation.
	same((int)$database->query('SELECT COUNT(*) FROM groups')->fetchColumn(), 1, 'Query edit cleared the last complete groups prematurely');
	$state['generation'] = (int)$pipeline['active_generation'];
	$state['hashes'] = $hashes;
	saveFixture($state);
}

function verifyEmptyGroups(): void {
	configuredExtension();
	$state = fixture();
	$repository = new SemanticGrouping_GroupRepository();
	$status = $repository->status();
	same((int)$status['worker']['last_published_generation'], (int)$state['generation'], 'Replacement grouping did not publish');
	$result = $repository->groups(1);
	same($result['groups'], [], 'A one-entry generation produced a semantic group');
}

function disablePipeline(): void {
	$extension = configuredExtension();
	$config = $extension->loadConfiguration();
	$config['enabled'] = false;
	same((new SemanticGrouping_CandidateExporter($config))->export(force: true), false, 'Disabled pipeline unexpectedly exported candidates');
	$pipeline = semanticDatabase()->query('SELECT producer_lease_until, config_json FROM pipeline_config WHERE singleton=1')->fetch();
	check(is_array($pipeline), 'Disabled pipeline revision was not published.');
	$effective = json_decode((string)$pipeline['config_json'], true, flags: JSON_THROW_ON_ERROR);
	same($effective['enabled'] ?? null, false, 'Published pipeline configuration is still enabled');
	check((int)$pipeline['producer_lease_until'] <= time(), 'Disabling the pipeline left its producer lease active');
}

$action = $argv[1] ?? '';
try {
	switch ($action) {
		case 'setup':
			setup();
			break;
		case 'verify-groups':
			verifyGroups();
			break;
		case 'verify-page':
			verifyPage();
			break;
		case 'verify-exact-disabled':
			verifyExactDisabled();
			break;
		case 'verify-pipeline-disabled-exact':
			verifyPipelineDisabledExact();
			break;
		case 'update-entry':
			updateEntry();
			break;
		case 'change-query':
			changeQuery();
			break;
		case 'verify-empty-groups':
			verifyEmptyGroups();
			break;
		case 'disable-pipeline':
			disablePipeline();
			break;
		default:
			throw new InvalidArgumentException('Unknown integration action: ' . $action);
	}
	echo 'FreshRSS integration ' . $action . " passed\n";
} catch (Throwable $error) {
	fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
	exit(1);
}
