"""Validation and fingerprinting for extension-published worker settings."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from typing import Any


class ConfigurationError(ValueError):
    """The producer published an invalid worker configuration."""


class ConfigurationMissing(ConfigurationError):
    """The producer has not published any worker configuration yet."""


MINIMUM_GROUP_SIZE = 2
GROUPING_FORMAT_VERSION = 3
# Used when a configuration published before topics has no topic threshold.
DEFAULT_TOPIC_THRESHOLD = 0.50
# Match the extension's limits: Model2Vec embeds about the first 2000
# characters, and 500 such texts fit the worker's 512 MiB limit.
MAX_CONTENT_CHARACTERS = 2000
MAX_EMBEDDING_BATCH_SIZE = 500


def canonical_json(value: Any) -> str:
    """Return the stable JSON representation used by both fingerprints."""
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def fingerprint(value: Any) -> str:
    """Return a versioned SHA-256 fingerprint for a JSON-compatible value."""
    return hashlib.sha256(("semantic-v1\0" + canonical_json(value)).encode()).hexdigest()


def _bool(data: dict[str, Any], key: str) -> bool:
    value = data.get(key)
    if not isinstance(value, bool):
        raise ConfigurationError(f"{key} must be a boolean")
    return value


def _int(data: dict[str, Any], key: str, low: int, high: int) -> int:
    value = data.get(key)
    if isinstance(value, bool) or not isinstance(value, int) or not low <= value <= high:
        raise ConfigurationError(f"{key} must be an integer from {low} through {high}")
    return value


def _float(data: dict[str, Any], key: str, low: float, high: float) -> float:
    value = data.get(key)
    if isinstance(value, bool) or not isinstance(value, int | float):
        raise ConfigurationError(f"{key} must be a number")
    result = float(value)
    if not low <= result <= high:
        raise ConfigurationError(f"{key} must be from {low} through {high}")
    return result


def default_topic_threshold(similarity_threshold: float) -> float:
    """Return the extension's topic threshold for a configuration without one.

    The default, or 0.10 below a lower similarity threshold, so topics stay
    broader than stories.
    """
    return round(max(0.0, min(DEFAULT_TOPIC_THRESHOLD, similarity_threshold - 0.10)), 2)


@dataclass(frozen=True)
class WorkerConfig:
    """Validated effective configuration copied into ``pipeline_config``."""

    enabled: bool
    embedding_model: str
    similarity_threshold: float
    topic_threshold: float
    window_hours: int
    include_title: bool
    include_content: bool
    content_character_limit: int
    embedding_batch_size: int
    normalization_version: int
    query_fingerprint: str
    force_rebuild_token: str

    @classmethod
    def from_mapping(cls, data: dict[str, Any]) -> WorkerConfig:
        """Validate an extension-published JSON object."""
        if not isinstance(data, dict):
            raise ConfigurationError("config_json must contain an object")
        model = data.get("embedding_model")
        if not isinstance(model, str) or not model.strip() or len(model) > 300:
            raise ConfigurationError("embedding_model must be a non-empty string")
        include_title = _bool(data, "include_title")
        include_content = _bool(data, "include_content")
        if not include_title and not include_content:
            raise ConfigurationError("at least one embedding input field must be enabled")
        query_fingerprint = data.get("query_fingerprint", "")
        if not isinstance(query_fingerprint, str) or not query_fingerprint:
            raise ConfigurationError("query_fingerprint is missing")
        similarity_threshold = _float(data, "similarity_threshold", 0.0, 1.0)
        topic_threshold = (
            _float(data, "topic_threshold", 0.0, 1.0)
            if "topic_threshold" in data
            else default_topic_threshold(similarity_threshold)
        )
        if topic_threshold >= similarity_threshold:
            raise ConfigurationError("topic_threshold must be below similarity_threshold")
        rebuild_token = data.get("force_rebuild_token", "")
        if not isinstance(rebuild_token, str) or len(rebuild_token) > 128:
            raise ConfigurationError("force_rebuild_token must be a short string")
        return cls(
            enabled=_bool(data, "enabled"),
            embedding_model=model.strip(),
            similarity_threshold=similarity_threshold,
            topic_threshold=topic_threshold,
            window_hours=_int(data, "window_hours", 1, 24 * 365),
            include_title=include_title,
            include_content=include_content,
            content_character_limit=_int(
                data, "content_character_limit", 0, MAX_CONTENT_CHARACTERS
            ),
            embedding_batch_size=_int(data, "embedding_batch_size", 1, MAX_EMBEDDING_BATCH_SIZE),
            normalization_version=_int(data, "normalization_version", 1, 1000),
            query_fingerprint=query_fingerprint,
            force_rebuild_token=rebuild_token,
        )

    @property
    def embedding_fingerprint(self) -> str:
        """Fingerprint all settings that change an article vector."""
        return fingerprint(
            {
                "content_character_limit": self.content_character_limit,
                "embedding_model": self.embedding_model,
                "force_rebuild_token": self.force_rebuild_token,
                "include_content": self.include_content,
                "include_title": self.include_title,
                "input_format_version": 1,
                "normalization_version": self.normalization_version,
            }
        )

    @property
    def grouping_fingerprint(self) -> str:
        """Fingerprint all settings that change group or topic membership."""
        return fingerprint(
            {
                "embedding_fingerprint": self.embedding_fingerprint,
                "grouping_format_version": GROUPING_FORMAT_VERSION,
                "minimum_group_size": MINIMUM_GROUP_SIZE,
                "query_fingerprint": self.query_fingerprint,
                "similarity_threshold": self.similarity_threshold,
                "topic_threshold": self.topic_threshold,
                "window_hours": self.window_hours,
            }
        )


@dataclass(frozen=True)
class PipelineSnapshot:
    """An immutable worker view of one producer generation and revision."""

    active_generation: int
    config_revision: str
    producer_lease_until: int
    updated_at: int
    config: WorkerConfig
