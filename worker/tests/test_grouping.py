from __future__ import annotations

from dataclasses import dataclass

import numpy as np

from freshrss_semantic.grouping import UnexpectedEncoder, _semhash, group, groups_from_result
from freshrss_semantic.semantic_store import GroupingInput


@dataclass
class Duplicate:
    record: dict[str, str]
    duplicates: list[tuple[dict[str, str], float]]


@dataclass
class Result:
    filtered: list[Duplicate]


def test_result_conversion_uses_earliest_member_and_fixed_minimum_size() -> None:
    inputs = [
        GroupingInput("late", 20, np.array([0.9, 0.1], dtype=np.float32)),
        GroupingInput("early", 10, np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("alone", 5, np.array([0.0, 1.0], dtype=np.float32)),
    ]
    result = Result([Duplicate({"entry_id": "late"}, [({"entry_id": "early"}, 0.99)])])
    groups = groups_from_result(result, inputs)
    assert len(groups) == 1
    assert groups[0].representative_entry_id == "early"
    assert list(groups[0].members) == ["early", "late"]


def test_singleton_components_are_not_published_as_groups() -> None:
    inputs = [
        GroupingInput("first", 10, np.array([1.0, 0.0], dtype=np.float32)),
        GroupingInput("second", 20, np.array([0.0, 1.0], dtype=np.float32)),
    ]

    groups = groups_from_result(Result([]), inputs)

    assert groups == []


def test_group_phase_publishes_only_after_all_embeddings_exist(populate) -> None:
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

    assert group(store, lambda _vectors, _records: FakeSemHash()) == 1
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
