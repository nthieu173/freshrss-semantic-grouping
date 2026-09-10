<?php
declare(strict_types=1);

// install() and configuration of a disabled extension run before FreshRSS
// registers the extension autoloader. Load every model those paths use.
require_once __DIR__ . '/Models/Config.php';
require_once __DIR__ . '/Models/SemanticDatabase.php';
require_once __DIR__ . '/Models/CandidateSource.php';
require_once __DIR__ . '/Models/TextNormalizer.php';
require_once __DIR__ . '/Models/CandidateExporter.php';
require_once __DIR__ . '/Models/GroupRepository.php';

final class SemanticGroupingExtension extends Minz_Extension {
	/** @var array<string,mixed> */
	public array $configuration = [];
	/** @var array<int,string> */
	public array $savedQueries = [];
	/** @var list<string> */
	public array $configurationErrors = [];
	/** @var array<string,mixed> */
	public array $semanticStatus = [];
	public ?int $previewCount = null;
	public bool $previewTruncated = false;
	public string $notice = '';
	private ?SemanticGrouping_ExactTitleFilter $exactTitleFilter = null;

	public function autoload(string $className): void {
		$prefix = 'SemanticGrouping_';
		if (!str_starts_with($className, $prefix)) {
			return;
		}
		$name = substr($className, strlen($prefix));
		if ($name !== '' && ctype_alnum(str_replace('_', '', $name))) {
			$file = $this->getPath() . '/Models/' . $name . '.php';
			if (is_file($file)) {
				require_once $file;
			}
		}
	}

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerController('semantic');
		$this->registerViews();
		FreshRSS_View::appendStyle($this->getFileUrl('semantic.css'));
		$this->registerHook(Minz_HookType::EntryBeforeAdd, [$this, 'entryBeforeAdd']);
		$this->registerHook(Minz_HookType::FreshrssUserMaintenance, [$this, 'userMaintenance']);
		$this->registerHook(Minz_HookType::MenuOtherEntry, [$this, 'menuEntry']);
	}

	#[\Override]
	public function install() {
		if (defined('FRESHRSS_VERSION') && version_compare(FRESHRSS_VERSION, '1.28.0', '<')) {
			return 'Semantic grouping requires FreshRSS 1.28.0 or newer.';
		}
		try {
			(new SemanticGrouping_SemanticDatabase())->open(true);
			return true;
		} catch (Throwable $error) {
			return 'Could not initialize semantic storage: ' . self::safeError($error);
		}
	}

	#[\Override]
	public function uninstall() {
		(new SemanticGrouping_CandidateExporter($this->loadConfiguration()))->runSafely(force: true);
		return true;
	}

	public function entryBeforeAdd(FreshRSS_Entry $entry): ?FreshRSS_Entry {
		$config = $this->loadConfiguration();
		if (empty($config['exact_title_enabled'])) {
			return $entry;
		}
		try {
			$this->exactTitleFilter ??= new SemanticGrouping_ExactTitleFilter();
			return $this->exactTitleFilter->filter($entry);
		} catch (Throwable $error) {
			Minz_Log::error('Semantic exact-title filter failed open: ' . $error->getMessage());
			return $entry;
		}
	}

	public function userMaintenance(): void {
		(new SemanticGrouping_CandidateExporter($this->loadConfiguration()))->runSafely();
	}

	public function menuEntry(): string {
		$url = _url('semantic', 'index');
		return '<li class="item"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Semantic Groups</a></li>';
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->configuration = $this->loadConfiguration();
		if (Minz_Request::isPost()) {
			$operation = Minz_Request::paramString('semantic_operation', plaintext: true);
			if ($operation === 'full_reset') {
				$this->handleFullReset();
			} else {
				$config = SemanticGrouping_Config::fromRequest();
				if ($operation === 'rebuild') {
					$config['force_rebuild_token'] = bin2hex(random_bytes(16));
				}
				$this->configurationErrors = SemanticGrouping_Config::validate($config);
				if ($this->configurationErrors === [] && !empty($config['enabled'])) {
					try {
						SemanticGrouping_CandidateSource::resolve($config);
					} catch (Throwable $error) {
						$this->configurationErrors[] = $error->getMessage();
					}
				}
				if ($this->configurationErrors === []) {
					/** @phpstan-ignore method.deprecated */
					$this->setUserConfiguration($config);
					$this->configuration = $config;
					if (empty($config['enabled'])) {
						(new SemanticGrouping_CandidateExporter($config))->runSafely(force: true);
					}
					$this->notice = $operation === 'rebuild'
						? 'Embedding and grouping rebuild requested; the next export will publish the revision.'
						: 'Semantic grouping configuration saved.';
				}
			}
		}
		$this->loadConfigureData();
	}

	/** @return array<string,mixed> */
	public function loadConfiguration(): array {
		/** @phpstan-ignore method.deprecated */
		$stored = $this->getUserConfiguration();
		return SemanticGrouping_Config::merge($stored);
	}

	private function handleFullReset(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			$this->configurationErrors[] = 'Administrator access is required for a full reset.';
			return;
		}
		try {
			$database = new SemanticGrouping_SemanticDatabase();
			(new SemanticGrouping_CandidateExporter($this->configuration, $database))->fullResetAndExport();
			$this->notice = 'Semantic database reset and candidates republished.';
		} catch (Throwable $error) {
			$this->configurationErrors[] = 'Full reset was not completed: ' . self::safeError($error);
		}
	}

	private static function safeError(Throwable $error): string {
		$message = preg_replace('~(?:[A-Za-z]:)?[/\\\\][^\s:]+~', '<path>', $error->getMessage());
		return mb_substr(is_string($message) ? $message : 'operation failed', 0, 500, 'UTF-8');
	}

	private function loadConfigureData(): void {
		$this->savedQueries = [];
		$currentSource = null;
		$queries = FreshRSS_Context::userConf()->queries;
		if (is_array($queries)) {
			foreach ($queries as $id => $raw) {
				if (is_array($raw)) {
					$name = trim((string)($raw['name'] ?? ''));
					$this->savedQueries[(int)$id] = $name !== '' ? $name : 'Query ' . ((int)$id + 1);
				}
			}
		}
		try {
			$config = $this->configuration;
			if (!empty($config['enabled'])) {
				$currentSource = SemanticGrouping_CandidateSource::resolve($config);
				$count = 0;
				foreach ($currentSource->entries(101) as $_entry) {
					$count++;
				}
				$this->previewTruncated = $count > 100;
				$this->previewCount = min($count, 100);
			}
		} catch (Throwable) {
			$this->previewCount = null;
		}
		try {
			$this->semanticStatus = (new SemanticGrouping_GroupRepository())->status();
			if (is_array($this->semanticStatus['pipeline'] ?? null)) {
				$this->semanticStatus['current_query_name'] = $currentSource?->name
					?? (string)($this->configuration['candidate_source']['query_name'] ?? 'Unavailable');
				$this->semanticStatus['current_query_fingerprint'] = $currentSource?->fingerprint ?? '';
				if (!empty($this->configuration['enabled'])) {
					if ($currentSource === null) {
						$this->semanticStatus['export_pending'] = true;
					} else {
						$effective = SemanticGrouping_Config::effectiveWorkerConfig($this->configuration, $currentSource->fingerprint);
						$expectedRevision = SemanticGrouping_Config::fingerprint($effective);
						$this->semanticStatus['export_pending'] = !empty($this->semanticStatus['export_pending'])
							|| !hash_equals((string)$this->semanticStatus['pipeline']['config_revision'], $expectedRevision);
					}
				}
			}
		} catch (Throwable) {
			$this->semanticStatus = ['available' => false];
		}
	}
}
