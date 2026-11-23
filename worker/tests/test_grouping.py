from __future__ import annotations

import hashlib
from dataclasses import dataclass

import numpy as np
from test_embeddings import assert_database_is_free

from freshrss_semantic.grouping import (
    UnexpectedEncoder,
    _semhash,
    group,
    groups_from_result,
    topics_from_groups,
)
from freshrss_semantic.semantic_store import GroupingInput, PublishedGroup, SemanticStore


@dataclass
class Duplicate:
    record: dict[str, str]
    duplicates: list[tuple[dict[str, str], float]]


@dataclass
class Result:
    filtered: list[Duplicate]


def test_two_article_groups_tie_on_centrality_and_keep_the_earliest_title() -> None:
    inputs = [
        GroupingInput("late", 20, "late", np.array([0.9, 0.1], dtype=np.float32)),
        GroupingInput("early", 10, "early", np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("alone", 5, "alone", np.array([0.0, 1.0], dtype=np.float32)),
    ]
    result = Result([Duplicate({"entry_id": "late"}, [({"entry_id": "early"}, 0.99)])])
    groups = groups_from_result(result, inputs)
    assert len(groups) == 1
    assert groups[0].representative_entry_id == "early"
    assert list(groups[0].members) == ["early", "late"]


def test_most_central_member_names_the_group_but_earliest_keys_it() -> None:
    inputs = [
        GroupingInput("edge-a", 10, "edge a", np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("center", 30, "center", np.array([0.7, 0.7], dtype=np.float32)),
        GroupingInput("edge-b", 20, "edge b", np.array([0.0, 2.0], dtype=np.float32)),
        GroupingInput("other-late", 40, "other late", np.array([0.0, -1.0], dtype=np.float32)),
        GroupingInput("other-early", 5, "other early", np.array([0.0, -1.0], dtype=np.float32)),
    ]
    result = Result(
        [
            Duplicate({"entry_id": "center"}, [({"entry_id": "edge-a"}, 0.7)]),
            Duplicate({"entry_id": "edge-b"}, [({"entry_id": "center"}, 0.7)]),
            Duplicate({"entry_id": "other-late"}, [({"entry_id": "other-early"}, 1.0)]),
        ]
    )

    groups = groups_from_result(result, inputs)

    assert [group.members for group in groups] == [
        ("other-early", "other-late"),
        ("edge-a", "edge-b", "center"),
    ]
    chained = groups[1]
    assert chained.representative_entry_id == "center"
    assert chained.group_id == hashlib.sha256(b"group-v1\0edge-a").hexdigest()[:32]


def test_singleton_components_are_not_published_as_groups() -> None:
    inputs = [
        GroupingInput("first", 10, "first", np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("second", 20, "second", np.array([0.0, 1.0], dtype=np.float32)),
    ]

    groups = groups_from_result(Result([]), inputs)

    assert groups == []


def test_a_headline_carried_by_several_feeds_is_not_favoured_as_the_name() -> None:
    # Four copies of the "edge a" headline would outscore the central article
    # if every copy counted; counted once, the central article names the group.
    copies = [
        GroupingInput(f"edge-a-{n}", 10 + n, "edge a", np.array([1.0, 0.0], dtype=np.float32))
        for n in range(4)
    ]
    inputs = [
        *copies,
        GroupingInput("center", 30, "center", np.array([0.7, 0.7], dtype=np.float32)),
        GroupingInput("edge-b", 20, "edge b", np.array([0.0, 1.0], dtype=np.float32)),
    ]
    neighbors = [({"entry_id": item.entry_id}, 0.7) for item in inputs[1:]]
    result = Result([Duplicate({"entry_id": "edge-a-0"}, neighbors)])

    groups = groups_from_result(result, inputs)

    assert len(groups) == 1
    assert groups[0].representative_entry_id == "center"


def test_the_earliest_copy_of_the_winning_title_names_the_group() -> None:
    inputs = [
        GroupingInput("edge-a", 10, "edge a", np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("center", 30, "center", np.array([0.7, 0.7], dtype=np.float32)),
        GroupingInput("center-copy", 40, "center", np.array([0.69, 0.71], dtype=np.float32)),
        GroupingInput("edge-b", 20, "edge b", np.array([0.0, 1.0], dtype=np.float32)),
    ]
    neighbors = [({"entry_id": item.entry_id}, 0.7) for item in inputs[1:]]
    result = Result([Duplicate({"entry_id": "edge-a"}, neighbors)])

    assert groups_from_result(result, inputs)[0].representative_entry_id == "center"


def test_articles_without_a_title_each_count_once() -> None:
    # Blank titles are not one headline: the two untitled articles must not
    # pool into a single direction that hides which of them is central.
    inputs = [
        GroupingInput("untitled-edge", 10, "", np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("untitled-center", 20, "", np.array([0.7, 0.7], dtype=np.float32)),
        GroupingInput("titled-edge", 30, "edge", np.array([0.0, 1.0], dtype=np.float32)),
    ]
    neighbors = [({"entry_id": item.entry_id}, 0.7) for item in inputs[1:]]
    result = Result([Duplicate({"entry_id": "untitled-edge"}, neighbors)])

    assert groups_from_result(result, inputs)[0].representative_entry_id == "untitled-center"


def test_articles_with_the_same_title_stay_in_their_group() -> None:
    # Grouping ignores titles: a syndicated copy is another source for its
    # story, not a reason to leave the story.
    inputs = [
        GroupingInput("duplicate", 20, "same title", np.array([0.9, 0.1], dtype=np.float32)),
        GroupingInput("original", 10, "same title", np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("distinct", 30, "distinct", np.array([0.8, 0.2], dtype=np.float32)),
    ]
    result = Result(
        [
            Duplicate(
                {"entry_id": "duplicate"},
                [({"entry_id": "original"}, 0.99), ({"entry_id": "distinct"}, 0.98)],
            )
        ]
    )

    groups = groups_from_result(result, inputs)

    assert len(groups) == 1
    assert list(groups[0].members) == ["original", "duplicate", "distinct"]


def test_two_copies_of_one_article_form_a_group() -> None:
    inputs = [
        GroupingInput("original", 10, "same title", np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("duplicate", 20, "same title", np.array([0.9, 0.1], dtype=np.float32)),
    ]
    result = Result([Duplicate({"entry_id": "duplicate"}, [({"entry_id": "original"}, 0.99)])])

    groups = groups_from_result(result, inputs)

    assert [list(group.members) for group in groups] == [["original", "duplicate"]]


def _at(entry_id: str, received_at: int, degrees: float, title: str = "") -> GroupingInput:
    angle = np.radians(degrees)
    vector = np.array([np.cos(angle), np.sin(angle)], dtype=np.float32)
    return GroupingInput(entry_id, received_at, title or entry_id, vector)


def _group(*members: str) -> PublishedGroup:
    return PublishedGroup(f"group-{members[0]}", members[0], members)


def test_two_stories_share_a_topic_by_their_average_similarity() -> None:
    inputs = [_at("a1", 10, 0), _at("a2", 20, 10), _at("b1", 30, 40), _at("b2", 40, 50)]
    groups = [_group("a1", "a2"), _group("b1", "b2")]
    # The four cross-story cosines average cos 30-50 degrees, about 0.76.
    topics = topics_from_groups(inputs, groups, 0.70)
    assert [topic.members for topic in topics] == [("a1", "a2", "b1", "b2")]
    assert topics[0].topic_id == hashlib.sha256(b"topic-v1\0a1").hexdigest()[:32]
    assert topics_from_groups(inputs, groups, 0.80) == []


def test_a_related_article_in_no_story_joins_the_topic() -> None:
    inputs = [_at("a1", 10, 0), _at("a2", 20, 10), _at("loose", 5, 30), _at("far", 30, 120)]
    topics = topics_from_groups(inputs, [_group("a1", "a2")], 0.80)
    assert [topic.members for topic in topics] == [("loose", "a1", "a2")]
    # The earliest article keys the topic, even when it is in no story.
    assert topics[0].topic_id == hashlib.sha256(b"topic-v1\0loose").hexdigest()[:32]


def test_related_articles_in_no_story_are_not_a_topic_by_themselves() -> None:
    inputs = [_at("x", 10, 0), _at("y", 20, 5), _at("a1", 30, 90), _at("a2", 40, 95)]
    assert topics_from_groups(inputs, [_group("a1", "a2")], 0.80) == []


def test_a_lone_story_is_not_a_topic() -> None:
    inputs = [_at("a1", 10, 0), _at("a2", 20, 10), _at("b1", 30, 90), _at("b2", 40, 100)]
    assert topics_from_groups(inputs, [_group("a1", "a2"), _group("b1", "b2")], 0.80) == []


def test_the_most_central_distinct_title_names_the_topic() -> None:
    inputs = [
        _at("a1", 10, 0, "edge"),
        _at("a2", 20, 20, "middle"),
        _at("b1", 30, 40, "far edge"),
        _at("b2", 40, 40, "far edge"),
    ]
    topics = topics_from_groups(inputs, [_group("a1", "a2"), _group("b1", "b2")], 0.70)
    assert topics[0].representative_entry_id == "a2"


def _reference_topics(
    inputs: list[GroupingInput], groups: list[PublishedGroup], threshold: float
) -> set[tuple[str, ...]]:
    """Plain average linkage: recompute every pair's mean cosine at each step."""
    vectors = {item.entry_id: item.embedding / np.linalg.norm(item.embedding) for item in inputs}
    grouped = {entry_id for group in groups for entry_id in group.members}
    clusters = [(list(group.members), True) for group in groups]
    clusters += [([item.entry_id], False) for item in inputs if item.entry_id not in grouped]
    units = [1] * len(clusters)
    while True:
        best, pair = threshold, None
        for x in range(len(clusters)):
            for y in range(x + 1, len(clusters)):
                pairs = [(a, b) for a in clusters[x][0] for b in clusters[y][0]]
                score = float(np.mean([vectors[a] @ vectors[b] for a, b in pairs]))
                if score >= best:
                    best, pair = score, (x, y)
        if pair is None:
            break
        x, y = pair
        clusters[x] = (clusters[x][0] + clusters[y][0], clusters[x][1] or clusters[y][1])
        units[x] += units[y]
        del clusters[y], units[y]
    return {
        tuple(sorted(members))
        for (members, has_group), count in zip(clusters, units, strict=True)
        if count > 1 and has_group
    }


def test_topics_match_plain_average_linkage() -> None:
    generator = np.random.default_rng(7)
    centers = generator.normal(size=(5, 16))
    inputs = []
    for index in range(60):
        vector = centers[index % 5] + generator.normal(scale=0.9, size=16)
        inputs.append(GroupingInput(f"e{index:02d}", index, f"t{index}", vector.astype(np.float32)))
    ids = [item.entry_id for item in inputs]
    groups = [PublishedGroup(f"g{n}", ids[n], (ids[n], ids[n + 5])) for n in range(0, 30, 3)]
    for threshold in (0.3, 0.5, 0.7):
        topics = topics_from_groups(inputs, groups, threshold)
        assert {tuple(sorted(topic.members)) for topic in topics} == _reference_topics(
            inputs, groups, threshold
        )
    assert topics_from_groups(inputs, groups, 0.3)


def test_group_phase_publishes_only_after_all_embeddings_exist(populate, monkeypatch) -> None:
    store = populate(entries=3)
    snapshot = store.load_snapshot()
    with store.connection() as db:
        pending = store.pending_inputs(db, snapshot, 3)
    store.store_embeddings(
        snapshot,
        pending,
        np.array([[1.0, 0.0], [0.99, 0.01], [0.0, 1.0]], dtype=np.float32),
    )

    class FakeSemHash:
        def self_deduplicate(self, threshold):
            assert threshold == 0.9
            return Result(
                [
                    Duplicate(
                        {"entry_id": pending[1].entry_id},
                        [({"entry_id": pending[0].entry_id}, 0.99)],
                    )
                ]
            )

    connections = 0
    real_connect = SemanticStore.connect

    def counting_connect(self):
        nonlocal connections
        connections += 1
        return real_connect(self)

    def semhash(_vectors, _records):
        # SemHash runs with no lock or transaction held.
        assert_database_is_free(store.database)
        return FakeSemHash()

    monkeypatch.setattr(SemanticStore, "connect", counting_connect)
    assert group(store, semhash) == 1
    assert connections == 1
    assert store.get_state("last_published_generation") == "1"


def test_precomputed_encoder_fails_on_unexpected_encode() -> None:
    try:
        UnexpectedEncoder().encode(["must not happen"])
    except RuntimeError as error:
        assert "unexpectedly" in str(error)
    else:
        raise AssertionError("encode unexpectedly succeeded")


def test_real_semhash_usearch_precomputed_api() -> None:
    vectors = np.array([[1.0, 0.0], [0.999, 0.001], [0.0, 1.0]], dtype=np.float32)
    records = [{"entry_id": value} for value in ("first", "second", "third")]
    result = _semhash(vectors, records).self_deduplicate(threshold=0.9)
    assert len(result.filtered) == 1
