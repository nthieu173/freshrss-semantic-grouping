<?php
declare(strict_types=1);

/**
 * The Stories view: topics, which gather related stories and articles, then
 * stories in no topic, each with its articles. Stories are the worker's
 * groups, the same ones the semantic labels hold.
 */
final class FreshExtension_stories_Controller extends FreshRSS_ActionController {
	public const PAGE_SIZE = 10;

	/** @var list<array<string,mixed>> this page's topics and stories, ready to render */
	public static array $items = [];
	/** '' when stories are shown; otherwise why they cannot be. */
	public static string $notice = '';
	public static bool $firstPage = true;
	public static ?string $next = null;
	public static int $topicCount = 0;
	public static int $storyCount = 0;
	public static int $standaloneCount = 0;
	public static int $singleLabelId = 0;
	public static int $singleUnread = 0;
	/** @var 'id'|'date'|'lastUserModified'|'title'|'rand' The toolbar's sort, as the view applies it. */
	private static string $sort = 'id';
	private static bool $ascending = false;
	private static string $seed = '';

	public function indexAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Request::forward(['c' => 'auth', 'a' => 'login']);
			return;
		}
		try {
			FreshRSS_Context::updateUsingRequest(true);
		} catch (FreshRSS_Context_Exception) {
			Minz_Error::error(404);
			return;
		}
		$extension = Minz_ExtensionManager::findExtension('Semantic grouping');
		if ($extension !== null) {
			FreshRSS_View::appendStyle($extension->getFileUrl('stories.css'));
			FreshRSS_View::appendScript($extension->getFileUrl('stories.js'));
		}
		$this->view->categories = FreshRSS_Context::categories();
		$this->view->callbackBeforeFeeds = static function (FreshRSS_View $view): void {
			$view->tags = FreshRSS_Context::labels(precounts: true);
			$view->nbUnreadTags = 0;
			foreach ($view->tags as $tag) {
				$view->nbUnreadTags += $tag->nbUnread();
			}
		};
		FreshRSS_View::prependTitle('Stories · ');

		try {
			$publication = (new SemanticGrouping_StoryRepository())->load();
		} catch (Throwable $error) {
			Minz_Log::warning('Semantic stories unavailable: ' . $error->getMessage());
			self::$notice = 'Stories are unavailable: the semantic database cannot be read.';
			return;
		}
		if ($publication === null) {
			self::$notice = 'Stories appear once semantic grouping is enabled and its worker has grouped articles.';
			return;
		}
		self::build($publication, $this->view->categories);
	}

	/**
	 * @param array{groups:array<string,array{representative:string,members:list<string>}>,topics:array<string,array{representative:string,members:list<string>}>,labels:array<string,int>} $publication
	 * @param array<int,FreshRSS_Category> $categories
	 */
	private static function build(array $publication, array $categories): void {
		self::$items = [];
		$entryDao = FreshRSS_Factory::createEntryDao();
		$single = $publication['labels'][SemanticGrouping_LabelReconciler::SINGLETON_KEY] ?? 0;
		if ($single > 0) {
			self::$singleLabelId = $single;
			self::$singleUnread = FreshRSS_Factory::createTagDao()->countNotRead($single);
		}

		$ids = [];
		foreach (['groups', 'topics'] as $kind) {
			foreach ($publication[$kind] as $set) {
				foreach ($set['members'] as $entryId) {
					$ids[$entryId] = true;
				}
			}
		}
		// The toolbar's sort and read-state filter, as in the normal view. Sorts
		// with no meaning for topics and stories (by length, link, feed or
		// category) keep to reception time.
		self::$sort = in_array(FreshRSS_Context::$sort, ['date', 'lastUserModified', 'title', 'rand'], true) ? FreshRSS_Context::$sort : 'id';
		self::$ascending = FreshRSS_Context::$order === 'ASC';
		$showRead = FreshRSS_Context::isStateEnabled(FreshRSS_Entry::STATE_READ);
		$showUnread = FreshRSS_Context::isStateEnabled(FreshRSS_Entry::STATE_NOT_READ)
			|| FreshRSS_Context::isStateEnabled(FreshRSS_Entry::STATE_OR_NOT_READ);
		$show = $showUnread === $showRead ? 'all' : ($showUnread ? 'unread' : 'read');

		$unread = [];
		$values = null;
		if ($ids !== []) {
			$oldest = (string)min(array_map('intval', array_keys($ids)));
			foreach ($entryDao->listIdsWhere('Z', 0, FreshRSS_Entry::STATE_NOT_READ, id_min: $oldest, limit: -1) ?? [] as $entryId) {
				if (isset($ids[$entryId])) {
					$unread[$entryId] = true;
				}
			}
			// Other than reception times, the sort's values come from FreshRSS.
			$column = ['date' => 'date', 'lastUserModified' => '`lastUserModified`', 'title' => 'title'][self::$sort] ?? null;
			if ($column !== null) {
				$values = [];
				foreach ($entryDao->fetchAssoc("SELECT id, {$column} AS value FROM `_entry` WHERE id >= :oldest", ['oldest' => $oldest]) ?? [] as $row) {
					if (isset($ids[(string)$row['id']])) {
						$values[(string)$row['id']] = self::$sort === 'title' ? (string)$row['value'] : (int)$row['value'];
					}
				}
			}
		}
		$after = Minz_Request::paramString(SemanticGrouping_StoryRepository::CURSOR, plaintext: true);
		// The toolbar's links start over from the first page.
		Minz_Request::_param(SemanticGrouping_StoryRepository::CURSOR);
		if (self::$sort === 'rand') {
			// Shuffled anew on each visit, the same way for all its pages.
			$seed = Minz_Request::paramString(SemanticGrouping_StoryRepository::SEED, plaintext: true);
			self::$seed = $after !== '' && ctype_xdigit($seed) ? $seed : bin2hex(random_bytes(8));
			Minz_Request::_param(SemanticGrouping_StoryRepository::SEED, self::$seed);
		}
		$page = SemanticGrouping_StoryRepository::arrange($publication, $unread, $after, self::PAGE_SIZE, $show,
			['id' => 'time', 'date' => 'time', 'lastUserModified' => 'time', 'title' => 'title', 'rand' => 'random'][self::$sort],
			self::$ascending, $values, self::$seed);
		self::$firstPage = $after === '';
		self::$next = $page['next'];
		self::$topicCount = $page['topics'];
		self::$storyCount = $page['stories'];
		self::$standaloneCount = $page['standalone'];

		// Only this page's articles are read from FreshRSS, in one query.
		$shown = [];
		foreach ($page['items'] as $item) {
			foreach ($item['kind'] === 'topic' ? [...$item['stories'], ['members' => $item['loose']]] : [$item] as $set) {
				foreach ($set['members'] as $entryId) {
					$shown[] = (string)$entryId;
				}
			}
		}
		$entries = [];
		foreach ($entryDao->listByIds($shown, 'ASC') as $entry) {
			$feed = FreshRSS_Category::findFeed($categories, $entry->feedId()) ?? $entry->feed() ?? FreshRSS_Feed::default();
			$entries[$entry->id()] = ['entry' => $entry, 'feed' => $feed];
		}

		foreach ($page['items'] as $item) {
			if ($item['kind'] === 'story') {
				$story = self::story($item, $entries);
				if ($story !== null) {
					self::$items[] = $story;
				}
				continue;
			}
			$stories = array_values(array_filter(array_map(static fn(array $s): ?array => self::story($s, $entries), $item['stories'])));
			if ($stories === []) {
				continue;
			}
			$related = self::articles($item['loose'], $entries);
			self::$items[] = self::summarize([
				'kind' => 'topic',
				'key' => 'topic-' . $item['id'],
				'title' => self::title($item['representative'], $entries) ?? $stories[0]['title'],
				'stories' => $stories,
				'related' => $related,
			]);
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,array{entry:FreshRSS_Entry,feed:FreshRSS_Feed}> $entries
	 * @return array<string,mixed>|null
	 */
	private static function story(array $item, array $entries): ?array {
		$articles = self::articles($item['members'], $entries);
		if ($articles === []) {
			return null;
		}
		return self::summarize([
			'kind' => 'story',
			'key' => 'story-' . $item['id'],
			'label' => $item['label'],
			'title' => self::title($item['representative'], $entries) ?? $articles[0]['entry']->title(),
			'articles' => $articles,
			'related' => [],
		]);
	}

	/**
	 * Articles still in FreshRSS, in the toolbar's order.
	 * @param list<string> $ids
	 * @param array<string,array{entry:FreshRSS_Entry,feed:FreshRSS_Feed}> $entries
	 * @return list<array{entry:FreshRSS_Entry,feed:FreshRSS_Feed}>
	 */
	private static function articles(array $ids, array $entries): array {
		$articles = [];
		foreach ($ids as $id) {
			if (isset($entries[$id])) {
				$articles[] = $entries[$id];
			}
		}
		usort($articles, static fn(array $a, array $b): int => (self::$ascending ? 1 : -1)
			* (strcmp(self::sortValue($a['entry']), self::sortValue($b['entry'])) ?: (int)$a['entry']->id() <=> (int)$b['entry']->id()));
		return $articles;
	}

	/** @param array<string,array{entry:FreshRSS_Entry,feed:FreshRSS_Feed}> $entries */
	private static function title(string $entryId, array $entries): ?string {
		return isset($entries[$entryId]) ? $entries[$entryId]['entry']->title() : null;
	}

	/**
	 * Adds the counts and times the view shows, from the articles themselves.
	 * Times are those that order the view.
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private static function summarize(array $item): array {
		$all = $item['kind'] === 'story' ? $item['articles'] : array_merge($item['related'], ...array_map(static fn(array $s): array => $s['articles'], $item['stories']));
		$feeds = [];
		$unread = 0;
		$first = PHP_INT_MAX;
		$last = 0;
		foreach ($all as $article) {
			$feeds[$article['feed']->id()] = true;
			$unread += $article['entry']->isRead() ? 0 : 1;
			$first = min($first, self::time($article['entry']));
			$last = max($last, self::time($article['entry']));
		}
		return array_merge($item, ['count' => count($all), 'sources' => count($feeds), 'unread' => $unread, 'first' => $first, 'last' => $last]);
	}

	/** An article's time in seconds, as the view shows it: published when sorted by it, otherwise received. */
	private static function time(FreshRSS_Entry $entry): int {
		return self::$sort === 'date' ? $entry->date(raw: true) : $entry->dateAdded(raw: true);
	}

	/** An article's place in the view's sort, as its topics and stories are placed. */
	private static function sortValue(FreshRSS_Entry $entry): string {
		return match (self::$sort) {
			'date' => SemanticGrouping_StoryRepository::sortTime($entry->date(raw: true)),
			'lastUserModified' => SemanticGrouping_StoryRepository::sortTime($entry->lastUserModified() ?? 0),
			'title' => SemanticGrouping_StoryRepository::sortTitle($entry->title()),
			'rand' => SemanticGrouping_StoryRepository::shuffleKey(self::$seed, $entry->id()),
			default => SemanticGrouping_StoryRepository::sortTime((int)$entry->id()),
		};
	}
}
