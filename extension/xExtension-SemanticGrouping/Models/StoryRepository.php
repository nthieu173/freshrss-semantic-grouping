<?php
declare(strict_types=1);

/**
 * The Stories view's data: the worker's last complete publication of groups
 * (stories) and topics, by entry ID. Articles themselves come from FreshRSS.
 */
final class SemanticGrouping_StoryRepository {
	/** The request parameter holding the sort key the Stories view's next page starts after. */
	public const CURSOR = 'stories_from';
	/** The request parameter holding the random order's seed, kept across its pages. */
	public const SEED = 'stories_seed';

	public function __construct(private readonly SemanticGrouping_SemanticDatabase $database = new SemanticGrouping_SemanticDatabase()) {}

	/**
	 * Null while the pipeline is unconfigured or disabled. Groups and topics
	 * are replaced together by the worker, so one snapshot is consistent.
	 * @return null|array{
	 * 	groups: array<string,array{representative:string,members:list<string>}>,
	 * 	topics: array<string,array{representative:string,members:list<string>}>,
	 * 	labels: array<string,int>
	 * }
	 */
	public function load(): ?array {
		$pdo = $this->database->open(false, true);
		SemanticGrouping_SemanticDatabase::beginRead($pdo);
		try {
			$config = $pdo->query('SELECT config_json FROM pipeline_config WHERE singleton=1')->fetchColumn();
			$config = is_string($config) ? json_decode($config, true) : null;
			if (!is_array($config) || empty($config['enabled'])) {
				$pdo->commit();
				return null;
			}
			$publication = [
				'groups' => self::members($pdo, 'SELECT g.group_id AS id, g.representative_entry_id AS representative, m.entry_id
					FROM groups g JOIN group_members m ON m.group_id = g.group_id ORDER BY g.group_id, m.entry_id'),
				'topics' => self::members($pdo, 'SELECT t.topic_id AS id, t.representative_entry_id AS representative, m.entry_id
					FROM topics t JOIN topic_members m ON m.topic_id = t.topic_id ORDER BY t.topic_id, m.entry_id'),
				'labels' => [],
			];
			foreach ($pdo->query('SELECT semantic_key, label_id FROM managed_labels') as $row) {
				$publication['labels'][(string)$row['semantic_key']] = (int)$row['label_id'];
			}
			$pdo->commit();
			return $publication;
		} catch (Throwable $error) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw $error;
		}
	}

	/** @return array<string,array{representative:string,members:list<string>}> */
	private static function members(PDO $pdo, string $sql): array {
		$result = [];
		foreach ($pdo->query($sql) as $row) {
			$id = (string)$row['id'];
			$result[$id] ??= ['representative' => (string)$row['representative'], 'members' => []];
			$result[$id]['members'][] = (string)$row['entry_id'];
		}
		return $result;
	}

	/**
	 * Orders the publication for the Stories view and cuts one page.
	 *
	 * Topics and stories with an unread article come first, then those read
	 * through; $show keeps only one kind ('unread' or 'read') or 'all'. Within
	 * each, topics come first: they are the broader trends. Then stories in no
	 * topic. A topic holds the stories whose articles it gathered, ordered the
	 * same way, and its related articles in no story.
	 *
	 * Topics and stories are ordered by $sort, latest or last first unless
	 * $ascending:
	 * - 'time': by their latest article, its time being $values[entry ID] if
	 *   given, otherwise its reception time (FreshRSS entry IDs are reception
	 *   times in microseconds);
	 * - 'title': by their title, $values[representative entry ID];
	 * - 'random': in the order $seed shuffles them into.
	 *
	 * A page starts after the item whose sort key is $after (the previous
	 * page's `next`), not at a position: reading moves items to the end, which
	 * would shift positions. An item read since its page was shown can thus
	 * come again among the read ones, so pages are appended without repeats.
	 *
	 * @param array{groups:array<string,array{representative:string,members:list<string>}>,topics:array<string,array{representative:string,members:list<string>}>,labels:array<string,int>} $publication
	 * @param array<string,true> $unread unread entry IDs
	 * @param 'all'|'unread'|'read' $show
	 * @param 'time'|'title'|'random' $sort
	 * @param array<string,int|string>|null $values entry times or titles
	 * @return array{items:list<array<string,mixed>>,next:?string,topics:int,stories:int,standalone:int}
	 */
	public static function arrange(array $publication, array $unread, string $after, int $size,
			string $show = 'all', string $sort = 'time', bool $ascending = false, ?array $values = null, string $seed = ''): array {
		$storyOf = [];
		foreach ($publication['groups'] as $groupId => $group) {
			foreach ($group['members'] as $entryId) {
				$storyOf[$entryId] = (string)$groupId;
			}
		}
		$countUnread = static fn(array $ids): int => count(array_filter($ids, static fn(string $id): bool => isset($unread[$id])));
		// Articles no longer in FreshRSS have no time of their own.
		$latest = static fn(array $ids): int => max([0, ...array_map(static fn(string $id): int => $values === null ? (int)$id : (int)($values[$id] ?? 0), $ids)]);
		$value = static fn(string $id, string $representative, array $members): string => match ($sort) {
			'title' => self::sortTitle((string)($values[$representative] ?? '')),
			'random' => self::shuffleKey($seed, $id),
			default => self::sortNumber($latest($members)),
		};
		$story = static function (string $groupId) use ($publication, $countUnread, $value): array {
			$group = $publication['groups'][$groupId];
			return [
				'kind' => 'story',
				'id' => $groupId,
				'representative' => $group['representative'],
				'label' => $publication['labels'][$groupId] ?? null,
				'members' => $group['members'],
				'count' => count($group['members']),
				'unread' => $countUnread($group['members']),
				'value' => $value($groupId, $group['representative'], $group['members']),
			];
		};
		$compare = static fn(array $a, array $b): int => self::compare($a, $b, $ascending);

		$items = [];
		$inTopic = [];
		foreach ($publication['topics'] as $topicId => $topic) {
			$topicId = (string)$topicId;
			$stories = [];
			$loose = [];
			$shown = [];
			foreach ($topic['members'] as $entryId) {
				if (!isset($storyOf[$entryId])) {
					$loose[] = $entryId;
				} elseif (!isset($stories[$storyOf[$entryId]])) {
					$stories[$storyOf[$entryId]] = $story($storyOf[$entryId]);
					$inTopic[$storyOf[$entryId]] = true;
					// Its stories' articles outside the topic are shown in it too.
					$shown = [...$shown, ...$stories[$storyOf[$entryId]]['members']];
				}
			}
			if ($stories === []) {
				continue;
			}
			$stories = array_values($stories);
			usort($stories, $compare);
			$items[] = [
				'kind' => 'topic',
				'id' => $topicId,
				'representative' => $topic['representative'],
				'stories' => $stories,
				'loose' => $loose,
				'count' => count($topic['members']),
				'unread' => $countUnread($loose) + array_sum(array_column($stories, 'unread')),
				'value' => $value($topicId, $topic['representative'], [...$loose, ...$shown]),
			];
		}
		foreach (array_keys($publication['groups']) as $groupId) {
			if (!isset($inTopic[$groupId])) {
				$items[] = $story((string)$groupId);
			}
		}
		if ($show !== 'all') {
			$items = array_values(array_filter($items, static fn(array $item): bool => ($item['unread'] > 0) === ($show === 'unread')));
		}
		usort($items, $compare);

		$cursor = self::parseKey($after);
		$page = [];
		$next = null;
		foreach ($items as $item) {
			if ($cursor !== null && $compare($item, $cursor) <= 0) {
				continue;
			}
			if (count($page) === $size) {
				$next = self::key($page[$size - 1]);
				break;
			}
			$page[] = $item;
		}
		$topics = array_filter($items, static fn(array $item): bool => $item['kind'] === 'topic');
		return [
			'items' => $page,
			'next' => $next,
			'topics' => count($topics),
			'stories' => array_sum(array_map(static fn(array $item): int => count($item['stories']), $topics)),
			'standalone' => count($items) - count($topics),
		];
	}

	/** A time or size as a sort value: sort values compare as strings. */
	public static function sortNumber(int $number): string {
		return str_pad((string)max(0, $number), 20, '0', STR_PAD_LEFT);
	}

	/** A title, as FreshRSS stores it, as a sort value. */
	public static function sortTitle(string $title): string {
		return mb_strtolower(trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'UTF-8');
	}

	/** A topic, story or article's place in the random order $seed makes. */
	public static function shuffleKey(string $seed, string $id): string {
		return substr(hash('sha256', $seed . "\0" . $id), 0, 16);
	}

	/**
	 * The view's order: unread first, then topics, then by sort value.
	 * @param array{kind:string,id:string,unread:int,value:string} $a
	 * @param array{kind:string,id:string,unread:int,value:string} $b
	 */
	private static function compare(array $a, array $b, bool $ascending): int {
		return ([$b['unread'] > 0, $b['kind'] === 'topic'] <=> [$a['unread'] > 0, $a['kind'] === 'topic'])
			?: ($ascending ? strcmp($a['value'], $b['value']) : strcmp($b['value'], $a['value']))
			?: strcmp($a['id'], $b['id']);
	}

	/** @param array{kind:string,id:string,unread:int,value:string} $item */
	private static function key(array $item): string {
		return implode('.', [$item['unread'] > 0 ? 1 : 0, $item['kind'] === 'topic' ? 't' : 's', $item['id'], $item['value']]);
	}

	/** @return array{kind:string,id:string,unread:int,value:string}|null */
	private static function parseKey(string $key): ?array {
		$parts = explode('.', $key, 4);
		if (count($parts) !== 4 || !in_array($parts[0], ['0', '1'], true) || !in_array($parts[1], ['t', 's'], true) || $parts[2] === '') {
			return null;
		}
		return ['unread' => (int)$parts[0], 'kind' => $parts[1] === 't' ? 'topic' : 'story', 'id' => $parts[2], 'value' => $parts[3]];
	}
}
