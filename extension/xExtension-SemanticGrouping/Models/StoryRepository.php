<?php
declare(strict_types=1);

/**
 * The Stories view's data: the worker's last complete publication of groups
 * (stories) and topics, by entry ID. Articles themselves come from FreshRSS.
 */
final class SemanticGrouping_StoryRepository {
	/** The request parameter holding the Stories view's next page position. */
	public const CURSOR = 'stories_from';

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
	 * Topics come first, most articles first: they are the broader trends.
	 * Then stories in no topic, most recently received article first. A topic
	 * holds the stories whose articles it gathered and its related articles in
	 * no story. With $unreadOnly, items without an unread article are skipped.
	 *
	 * $from is a position in the unfiltered ordering, which reading does not
	 * change, so marking items read never shifts the next page. FreshRSS entry
	 * IDs are reception times in microseconds.
	 *
	 * @param array{groups:array<string,array{representative:string,members:list<string>}>,topics:array<string,array{representative:string,members:list<string>}>,labels:array<string,int>} $publication
	 * @param array<string,true> $unread unread entry IDs
	 * @return array{items:list<array<string,mixed>>,next:?int,topics:int,stories:int,standalone:int}
	 */
	public static function arrange(array $publication, array $unread, bool $unreadOnly, int $from, int $size): array {
		$storyOf = [];
		foreach ($publication['groups'] as $groupId => $group) {
			foreach ($group['members'] as $entryId) {
				$storyOf[$entryId] = (string)$groupId;
			}
		}
		$story = static function (string $groupId) use ($publication, $unread): array {
			$members = $publication['groups'][$groupId]['members'];
			return [
				'kind' => 'story',
				'id' => $groupId,
				'representative' => $publication['groups'][$groupId]['representative'],
				'label' => $publication['labels'][$groupId] ?? null,
				'members' => $members,
				'count' => count($members),
				'unread' => count(array_filter($members, static fn(string $id): bool => isset($unread[$id]))),
				'last' => max(array_map('intval', $members)),
			];
		};

		$items = [];
		$inTopic = [];
		foreach ($publication['topics'] as $topicId => $topic) {
			$stories = [];
			$loose = [];
			foreach ($topic['members'] as $entryId) {
				if (!isset($storyOf[$entryId])) {
					$loose[] = $entryId;
				} elseif (!isset($stories[$storyOf[$entryId]])) {
					$stories[$storyOf[$entryId]] = $story($storyOf[$entryId]);
					$inTopic[$storyOf[$entryId]] = true;
				}
			}
			if ($stories === []) {
				continue;
			}
			$stories = array_values($stories);
			usort($stories, static fn(array $a, array $b): int => [$b['last'], $a['id']] <=> [$a['last'], $b['id']]);
			$members = $topic['members'];
			$items[] = [
				'kind' => 'topic',
				'id' => (string)$topicId,
				'representative' => $topic['representative'],
				'stories' => $stories,
				'loose' => $loose,
				'count' => count($members),
				'unread' => count(array_filter($members, static fn(string $id): bool => isset($unread[$id]))),
				'last' => max(array_map('intval', $members)),
			];
		}
		foreach (array_keys($publication['groups']) as $groupId) {
			if (!isset($inTopic[$groupId])) {
				$items[] = $story((string)$groupId);
			}
		}
		usort($items, static fn(array $a, array $b): int => [
			$b['kind'] === 'topic', $b['kind'] === 'topic' ? $b['count'] : 0, $b['last'], $a['id'],
		] <=> [
			$a['kind'] === 'topic', $a['kind'] === 'topic' ? $a['count'] : 0, $a['last'], $b['id'],
		]);

		$visible = $unreadOnly ? array_filter($items, static fn(array $item): bool => $item['unread'] > 0) : $items;
		$page = [];
		$next = null;
		foreach ($visible as $position => $item) {
			if ($position < $from) {
				continue;
			}
			if (count($page) === $size) {
				$next = $position;
				break;
			}
			$page[] = $item;
		}
		$topics = array_filter($visible, static fn(array $item): bool => $item['kind'] === 'topic');
		return [
			'items' => $page,
			'next' => $next,
			'topics' => count($topics),
			'stories' => array_sum(array_map(static fn(array $item): int => count($item['stories']), $topics)),
			'standalone' => count($visible) - count($topics),
		];
	}
}
