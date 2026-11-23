"""SemHash grouping over precomputed active-generation embeddings, then topics."""

from __future__ import annotations

import hashlib
import logging
import resource
import time
from collections.abc import Callable, Sequence
from typing import Any

import numpy as np
from numpy.typing import NDArray

from .config import MINIMUM_GROUP_SIZE, PipelineSnapshot
from .semantic_store import GroupingInput, PublishedGroup, PublishedTopic, SemanticStore

LOGGER = logging.getLogger(__name__)
# Cosine-sum scores closer than this count as tied (float rounding, not meaning).
CENTRALITY_TIE_TOLERANCE = 1e-6


class UnexpectedEncoder:
    """SemHash protocol adapter that proves precomputed vectors are used."""

    def encode(self, inputs: Sequence[Any] | Any, **kwargs: Any) -> NDArray[np.float32]:
        raise RuntimeError("SemHash unexpectedly requested text encoding")


def _semhash(embeddings: NDArray[np.float32], records: list[dict[str, str]]) -> Any:
    from semhash import SemHash
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


def groups_from_result(
    result: Any,
    inputs: Sequence[GroupingInput],
) -> list[PublishedGroup]:
    """Turn SemHash duplicate edges into deterministic connected groups."""
    order = {item.entry_id: (item.received_at, item.entry_id) for item in inputs}
    embeddings = {item.entry_id: item.embedding for item in inputs}
    titles = {item.entry_id: item.normalized_title for item in inputs}
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
        if len(members) < MINIMUM_GROUP_SIZE:
            continue
        members.sort(key=order.__getitem__)
        # The earliest member keys the group, so its label survives as members
        # arrive; the most central member only names it.
        group_id = hashlib.sha256(("group-v1\0" + members[0]).encode()).hexdigest()[:32]
        representative = most_central(members, embeddings, titles)
        groups.append(PublishedGroup(group_id, representative, tuple(members)))
    groups.sort(key=lambda group: order[group.members[0]])
    return groups


def most_central(
    members: Sequence[str],
    embeddings: dict[str, NDArray[np.float32]],
    titles: dict[str, str],
) -> str:
    """Return the earliest member with the title closest to the group's mean direction.

    Titles only name the group; they never decide membership. Each distinct
    normalized title counts once, its copies averaged into one direction, so a
    headline carried by several feeds is not favoured for its copies. Members
    without a title each count on their own. Members are in arrival order, and
    near-ties go to the earliest title, so a group of two distinct titles
    (always a tie) keeps its first.
    """
    copies: dict[str, list[str]] = {}
    for entry_id in members:
        title = titles[entry_id]
        copies.setdefault("title\0" + title if title else "entry\0" + entry_id, []).append(entry_id)
    directions = np.stack(
        [_mean_direction([embeddings[i] for i in ids]) for ids in copies.values()]
    )
    scores = directions @ directions.sum(axis=0)
    best = int(np.flatnonzero(scores >= scores.max() - CENTRALITY_TIE_TOLERANCE)[0])
    return list(copies.values())[best][0]


def _mean_direction(vectors: Sequence[NDArray[np.float32]]) -> NDArray[np.float64]:
    stacked = np.stack(vectors).astype(np.float64)
    norms = np.linalg.norm(stacked, axis=1, keepdims=True)
    mean = (stacked / np.where(norms == 0, 1, norms)).mean(axis=0)
    length = np.linalg.norm(mean)
    return mean / length if length > 0 else mean


def topics_from_groups(
    inputs: Sequence[GroupingInput],
    groups: Sequence[PublishedGroup],
    threshold: float,
) -> list[PublishedTopic]:
    """Gather related groups and ungrouped articles into topics.

    Units are the groups and every article in no group. Average linkage merges
    the two units, or merged clusters, whose articles are most alike on
    average, while that average cosine is at least ``threshold``. A cluster
    becomes a topic when it holds at least two units, one of them a group: a
    lone group is already a story, and articles with no group are not a topic
    by themselves. Ties go to the earliest units.
    """
    if not inputs or not groups:
        return []
    order = {item.entry_id: (item.received_at, item.entry_id) for item in inputs}
    embeddings = {item.entry_id: item.embedding for item in inputs}
    titles = {item.entry_id: item.normalized_title for item in inputs}
    grouped = {entry_id for group in groups for entry_id in group.members}
    # (members, is a group), earliest first, so ties go to earlier units.
    units = [(group.members, True) for group in groups]
    units += [((item.entry_id,), False) for item in inputs if item.entry_id not in grouped]
    units.sort(key=lambda unit: min(order[entry_id] for entry_id in unit[0]))

    # For unit-length vectors, the mean cosine between the articles of two
    # units is the dot product of their mean vectors. float32 keeps the
    # unit-by-unit matrix at 4 bytes a cell.
    means = np.empty((len(units), inputs[0].embedding.shape[0]), dtype=np.float32)
    for index, (members, _) in enumerate(units):
        stacked = np.stack([embeddings[entry_id] for entry_id in members]).astype(np.float32)
        norms = np.linalg.norm(stacked, axis=1, keepdims=True)
        means[index] = (stacked / np.where(norms == 0, 1, norms)).mean(axis=0)
    similarity = means @ means.T
    del means
    sizes = np.array([len(members) for members, _ in units], dtype=np.float64)
    np.fill_diagonal(similarity, -np.inf)
    best = similarity.max(axis=1)
    partner = similarity.argmax(axis=1)
    clusters: list[list[int]] = [[index] for index in range(len(units))]

    while True:
        left = int(best.argmax())
        if not best[left] >= threshold:
            break
        right = int(partner[left])
        keep, gone = min(left, right), max(left, right)
        # Lance-Williams update for average linkage, weighted by article count.
        merged = (
            sizes[keep] * similarity[keep].astype(np.float64)
            + sizes[gone] * similarity[gone].astype(np.float64)
        ) / (sizes[keep] + sizes[gone])
        merged[[keep, gone]] = -np.inf
        similarity[keep] = merged
        similarity[:, keep] = merged
        similarity[gone] = -np.inf
        similarity[:, gone] = -np.inf
        sizes[keep] += sizes[gone]
        clusters[keep] += clusters[gone]
        clusters[gone] = []
        best[gone] = -np.inf
        # A merged similarity lies between the two it replaces, so only rows
        # whose best partner was one of the merged units can change their best.
        stale = np.flatnonzero((partner == keep) | (partner == gone))
        for index in [keep, *stale.tolist()]:
            if clusters[index]:
                partner[index] = int(similarity[index].argmax())
                best[index] = similarity[index, partner[index]]
        improved = np.flatnonzero(merged > best)
        improved = improved[improved != keep]
        best[improved] = merged[improved]
        partner[improved] = keep

    topics: list[PublishedTopic] = []
    for members_of in clusters:
        if len(members_of) < 2 or not any(units[index][1] for index in members_of):
            continue
        articles = sorted(
            (entry_id for index in members_of for entry_id in units[index][0]),
            key=order.__getitem__,
        )
        topic_id = hashlib.sha256(("topic-v1\0" + articles[0]).encode()).hexdigest()[:32]
        topics.append(
            PublishedTopic(topic_id, most_central(articles, embeddings, titles), tuple(articles))
        )
    topics.sort(key=lambda topic: order[topic.members[0]])
    return topics


def group(
    store: SemanticStore,
    semhash_factory: Callable[[NDArray[np.float32], list[dict[str, str]]], Any] = _semhash,
) -> int:
    """Compute all groups before atomically replacing the published mapping."""
    # One connection for the phase; no transaction is open during SemHash.
    with store.session():
        return _group(store, semhash_factory)


def _group(
    store: SemanticStore,
    semhash_factory: Callable[[NDArray[np.float32], list[dict[str, str]]], Any],
) -> int:
    started = time.monotonic()
    with store.connection() as db:
        snapshot: PipelineSnapshot = store.load_snapshot(db)
        store.require_live(snapshot)
        store.set_state("last_attempted_group", str(int(time.time())), db)
        inputs = store.load_grouping_inputs(db, snapshot)
    topics: list[PublishedTopic] = []
    if not inputs:
        groups: list[PublishedGroup] = []
        dimensions = 0
    else:
        matrix = np.stack([item.embedding for item in inputs]).astype("<f4", copy=False)
        records = [{"entry_id": item.entry_id} for item in inputs]
        result = semhash_factory(matrix, records).self_deduplicate(
            threshold=snapshot.config.similarity_threshold
        )
        groups = groups_from_result(result, inputs)
        del result, matrix
        topics = topics_from_groups(inputs, groups, snapshot.config.topic_threshold)
        dimensions = int(inputs[0].embedding.shape[0])
    store.publish_groups(snapshot, groups, topics)
    LOGGER.info(
        "grouped entries=%d groups=%d topics=%d dimensions=%d window_hours=%d "
        "duration_seconds=%.3f peak_rss_mib=%.1f",
        len(inputs),
        len(groups),
        len(topics),
        dimensions,
        snapshot.config.window_hours,
        time.monotonic() - started,
        resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024,
    )
    return len(groups)
