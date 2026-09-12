#!/usr/bin/env python3
"""Verify that every packaged release-version declaration agrees."""

from __future__ import annotations

import json
import os
import re
from pathlib import Path

import tomllib

REPOSITORY = Path(__file__).resolve().parents[2]
PACKAGE_NAME = "freshrss-semantic-grouping"


def matched_version(path: Path, pattern: str, label: str) -> str:
    match = re.search(pattern, path.read_text(), flags=re.MULTILINE)
    if match is None:
        raise SystemExit(f"could not read {label} version from {path.relative_to(REPOSITORY)}")
    return match.group(1)


extension_metadata = json.loads(
    (REPOSITORY / "extension/xExtension-SemanticGrouping/metadata.json").read_text()
)
worker_project = tomllib.loads((REPOSITORY / "worker/pyproject.toml").read_text())
worker_lock = tomllib.loads((REPOSITORY / "worker/uv.lock").read_text())
locked_packages = [
    package for package in worker_lock["package"] if package.get("name") == PACKAGE_NAME
]
if len(locked_packages) != 1:
    raise SystemExit(f"expected one {PACKAGE_NAME} package in worker/uv.lock")

versions = {
    "extension metadata": extension_metadata.get("version"),
    "worker project": worker_project["project"].get("version"),
    "worker lock": locked_packages[0].get("version"),
    "worker module": matched_version(
        REPOSITORY / "worker/src/freshrss_semantic/__init__.py",
        r'^__version__\s*=\s*["\']([^"\']+)["\']',
        "worker module",
    ),
    "worker image": matched_version(
        REPOSITORY / "packaging/Containerfile",
        r"^ARG VERSION=([^\s]+)",
        "worker image",
    ).strip('"\''),
}

declared = versions["worker project"]
tag = os.environ.get("TAG", "")
release_version = os.environ.get("RELEASE_VERSION", "")
tag_version = tag.removeprefix("v")
requested_version = release_version.removeprefix("v")
if tag_version and requested_version and tag_version != requested_version:
    raise SystemExit(f"tag={tag_version}, requested release={requested_version}")
expected = tag_version or requested_version or declared
mismatches = [f"{label}={version}" for label, version in versions.items() if version != expected]
if mismatches:
    prefix = f"expected={expected}, " if tag_version or requested_version else ""
    raise SystemExit(prefix + ", ".join(mismatches))

print(f"release version {expected} matches all packaged components")
