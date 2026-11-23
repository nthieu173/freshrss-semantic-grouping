from __future__ import annotations

import hashlib
from dataclasses import dataclass

import numpy as np
from test_embeddings import assert_database_is_free

from freshrss_semantic.grouping import UnexpectedEncoder, _semhash, group, groups_from_result
from freshrss_semantic.semantic_store import GroupingInput, SemanticStore


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
