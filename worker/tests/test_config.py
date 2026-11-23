from __future__ import annotations

import pytest
from conftest import worker_config

from freshrss_semantic.config import ConfigurationError, WorkerConfig, canonical_json


def test_fingerprints_have_the_expected_invalidation_boundaries() -> None:
    base = WorkerConfig.from_mapping(worker_config())
    threshold = WorkerConfig.from_mapping(worker_config(similarity_threshold=0.95))
    content = WorkerConfig.from_mapping(worker_config(include_content=True))
    topics = WorkerConfig.from_mapping(worker_config(topic_threshold=0.4))

    assert threshold.embedding_fingerprint == base.embedding_fingerprint
    assert threshold.grouping_fingerprint != base.grouping_fingerprint
    assert content.embedding_fingerprint != base.embedding_fingerprint
    assert content.grouping_fingerprint != base.grouping_fingerprint
    assert topics.embedding_fingerprint == base.embedding_fingerprint
    assert topics.grouping_fingerprint != base.grouping_fingerprint


def test_topic_threshold_defaults_when_absent_and_stays_below_grouping() -> None:
    legacy = worker_config()
    del legacy["topic_threshold"]
    assert WorkerConfig.from_mapping(legacy).topic_threshold == 0.5
    legacy["similarity_threshold"] = 0.25
    assert WorkerConfig.from_mapping(legacy).topic_threshold == 0.15
    with pytest.raises(ConfigurationError, match="topic_threshold"):
        WorkerConfig.from_mapping(worker_config(topic_threshold=0.9))
    with pytest.raises(ConfigurationError, match="topic_threshold"):
        WorkerConfig.from_mapping(worker_config(topic_threshold="0.5"))


def test_configuration_rejects_implicit_empty_input() -> None:
    with pytest.raises(ConfigurationError, match="at least one"):
        WorkerConfig.from_mapping(worker_config(include_title=False, include_content=False))


def test_legacy_minimum_group_size_is_ignored() -> None:
    config = WorkerConfig.from_mapping(worker_config(minimum_group_size=999))
    assert not hasattr(config, "minimum_group_size")


def test_canonical_json_is_compact_and_sorted() -> None:
    assert canonical_json({"z": 1, "a": "é"}) == '{"a":"é","z":1}'


def test_default_fingerprints_match_the_php_producer_contract() -> None:
    config = WorkerConfig.from_mapping(
        worker_config(embedding_model="minishlab/potion-base-8M", topic_threshold=0.5)
    )
    assert config.embedding_fingerprint == (
        "735c7ecc39088db6c69eff6eecb21a27d5d78d31652f6b745d4c715eaf8dcfdf"
    )
    assert config.grouping_fingerprint == (
        "c11182eef4309ddd4b4643fc6c934110d4fc46d64b85631bab0a530384fe7e16"
    )


@pytest.mark.parametrize(
    ("key", "maximum"), [("content_character_limit", 2000), ("embedding_batch_size", 500)]
)
def test_content_and_batch_limits_match_the_extension(key: str, maximum: int) -> None:
    assert getattr(WorkerConfig.from_mapping(worker_config(**{key: maximum})), key) == maximum
    with pytest.raises(ConfigurationError, match=key):
        WorkerConfig.from_mapping(worker_config(**{key: maximum + 1}))
