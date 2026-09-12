#!/usr/bin/env python3
"""Build the generated release-note line from authoritative artifacts."""

from __future__ import annotations

import argparse
import json
import os
import re
from pathlib import Path

REPOSITORY = Path(__file__).resolve().parents[2]

parser = argparse.ArgumentParser()
parser.add_argument("image_metadata", type=Path)
args = parser.parse_args()

metadata = json.loads(args.image_metadata.read_text())
digest = metadata.get("containerimage.digest")
if not isinstance(digest, str) or not digest:
    raise SystemExit("image metadata does not contain containerimage.digest")

database_source = REPOSITORY / "extension/xExtension-SemanticGrouping/Models/SemanticDatabase.php"
match = re.search(r"public const SCHEMA_VERSION = (\d+);", database_source.read_text())
if match is None:
    raise SystemExit("could not read the semantic database schema version")

freshrss_version = os.environ.get("FRESHRSS_VERSION", "1.30.0")
print(
    f"Supported FreshRSS: {freshrss_version}. "
    f"Semantic database schema: {match.group(1)}. OCI digest: {digest}"
)
