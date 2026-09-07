"""Validation and fingerprinting for extension-published worker settings."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from typing import Any


class ConfigurationError(ValueError):
    """The producer published an invalid worker configuration."""


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


@dataclass(frozen=True)
class WorkerConfig:
    """Validated effective configuration copied into ``pipeline_config``."""

    enabled: bool
    embedding_model: str
    similarity_threshold: float
    window_hours: int
    worker_interval_minutes: int
    minimum_group_size: int
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
        rebuild_token = data.get("force_rebuild_token", "")
        if not isinstance(rebuild_token, str) or len(rebuild_token) > 128:
            raise ConfigurationError("force_rebuild_token must be a short string")
        return cls(
            enabled=_bool(data, "enabled"),
            embedding_model=model.strip(),
            similarity_threshold=_float(data, "similarity_threshold", 0.0, 1.0),
            window_hours=_int(data, "window_hours", 1, 24 * 365),
            worker_interval_minutes=_int(data, "worker_interval_minutes", 1, 24 * 60),
            minimum_group_size=_int(data, "minimum_group_size", 2, 1000),
            include_title=include_title,
            include_content=include_content,
            content_character_limit=_int(data, "content_character_limit", 0, 100_000),
            embedding_batch_size=_int(data, "embedding_batch_size", 1, 256),
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
        """Fingerprint all settings that change group membership."""
        return fingerprint(
            {
                "embedding_fingerprint": self.embedding_fingerprint,
                "minimum_group_size": self.minimum_group_size,
                "query_fingerprint": self.query_fingerprint,
                "similarity_threshold": self.similarity_threshold,
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
