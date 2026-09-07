"""SemHash grouping over precomputed active-generation embeddings."""

from __future__ import annotations

import hashlib
import logging
import resource
import time
from collections.abc import Callable, Sequence
from typing import Any

import numpy as np
from numpy.typing import NDArray

from .config import PipelineSnapshot
from .semantic_store import GroupingInput, PublishedGroup, SemanticStore

LOGGER = logging.getLogger(__name__)


class UnexpectedEncoder:
    """SemHash protocol adapter that proves precomputed vectors are used."""

    def encode(self, _inputs: Sequence[Any] | Any, **_kwargs: Any) -> NDArray[np.float32]:
        raise RuntimeError("SemHash unexpectedly requested text encoding")


def _semhash(embeddings: NDArray[np.float32], records: list[dict[str, str]]) -> Any:
    from semhash import SemHash  # type: ignore[import-untyped]
    from vicinity import Backend

    return SemHash.from_embeddings(
        embeddings=embeddings,
        records=records,
        columns=["entry_id"],
        model=UnexpectedEncoder(),
        ann_backend=Backend.USEARCH,
    )


def _record_id(record: Any) -> str:
    if not isinstance(record, dict):
        raise ValueError("SemHash returned a record without entry_id")
    entry_id = record.get("entry_id")
    if not isinstance(entry_id, str):
        raise ValueError("SemHash returned a record without entry_id")
    return entry_id


def _cosine(left: NDArray[np.float32], right: NDArray[np.float32]) -> float:
    denominator = float(np.linalg.norm(left) * np.linalg.norm(right))
    if denominator == 0:
        return 0.0
    return max(-1.0, min(1.0, float(np.dot(left, right) / denominator)))


def groups_from_result(
    result: Any,
    inputs: Sequence[GroupingInput],
    minimum_group_size: int,
) -> list[PublishedGroup]:
    """Turn SemHash duplicate edges into deterministic connected groups."""
    order = {item.entry_id: (item.received_at, item.entry_id) for item in inputs}
    vectors = {item.entry_id: item.embedding for item in inputs}
    parent = {item.entry_id: item.entry_id for item in inputs}

    def find(item: str) -> str:
        while parent[item] != item:
            parent[item] = parent[parent[item]]
            item = parent[item]
        return item

    def union(left: str, right: str) -> None:
        root_left, root_right = find(left), find(right)
        if root_left == root_right:
            return
        if order[root_left] <= order[root_right]:
            parent[root_right] = root_left
        else:
            parent[root_left] = root_right

    for duplicate in result.filtered:
        entry_id = _record_id(duplicate.record)
        if entry_id not in parent:
            raise ValueError("SemHash returned an unknown duplicate record")
        for neighbor, _score in duplicate.duplicates:
            neighbor_id = _record_id(neighbor)
            if neighbor_id not in parent:
                raise ValueError("SemHash returned an unknown neighbor record")
            union(entry_id, neighbor_id)

    components: dict[str, list[str]] = {}
    for entry_id in parent:
        components.setdefault(find(entry_id), []).append(entry_id)

    groups: list[PublishedGroup] = []
    for members in components.values():
        if len(members) < minimum_group_size:
            continue
        members.sort(key=order.__getitem__)
        representative = members[0]
        group_id = hashlib.sha256(("group-v1\0" + representative).encode()).hexdigest()[:32]
        scored = tuple(
            (
                entry_id,
                1.0
                if entry_id == representative
                else _cosine(vectors[representative], vectors[entry_id]),
            )
            for entry_id in members
        )
        groups.append(PublishedGroup(group_id, representative, scored))
    groups.sort(key=lambda group: order[group.representative_entry_id])
    return groups


def group(
    store: SemanticStore,
    semhash_factory: Callable[[NDArray[np.float32], list[dict[str, str]]], Any] = _semhash,
) -> int:
    """Compute all groups before atomically replacing the published mapping."""
    started = time.monotonic()
    snapshot: PipelineSnapshot = store.load_snapshot()
    store.require_live(snapshot)
    store.set_state("last_attempted_group", str(int(time.time())))
    with store.connection() as db:
        inputs = store.load_grouping_inputs(db, snapshot)
    if not inputs:
        groups: list[PublishedGroup] = []
        dimensions = 0
    else:
        matrix = np.stack([item.embedding for item in inputs]).astype("<f4", copy=False)
        records = [{"entry_id": item.entry_id} for item in inputs]
        result = semhash_factory(matrix, records).self_deduplicate(
            threshold=snapshot.config.similarity_threshold
        )
        groups = groups_from_result(result, inputs, snapshot.config.minimum_group_size)
        dimensions = int(matrix.shape[1])
    store.publish_groups(snapshot, groups)
    LOGGER.info(
        "grouped entries=%d groups=%d dimensions=%d window_hours=%d "
        "duration_seconds=%.3f peak_rss_mib=%.1f",
        len(inputs),
        len(groups),
        dimensions,
        snapshot.config.window_hours,
        time.monotonic() - started,
        resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024,
    )
    return len(groups)
