# The FreshRSS release every test, CI job and release note uses. Change it here
# only; the scripts read it from the environment.
FRESHRSS_VERSION ?= 1.30.4
IMAGE_METADATA ?= image-metadata.json

.PHONY: test test/all test/fast test/php test/worker test/postgres test/compatibility test/smoke test/integration
.PHONY: hooks/install hooks/check
.PHONY: ci ci/all ci/check ci/verify-release-version ci/extension-artifact ci/release-notes

test: test/all

ci: ci/all

# Install the git hooks in prek.toml (direnv provides prek and the tools).
hooks/install:
	prek install

# Run every hook on every tracked file.
hooks/check:
	prek run --all-files

test/fast: test/php test/worker

test/all: test/fast test/postgres test/compatibility test/integration test/smoke

test/php:
	php extension/tests/run.php
	find extension -type f \( -name '*.php' -o -name '*.phtml' \) -print0 | xargs -0 -n1 php -l

test/worker:
	cd worker && uv sync --locked --extra dev
	cd worker && uv run pytest
	cd worker && uv run ruff check src tests
	cd worker && uv run mypy src
	cd worker && uv run python -c "import importlib.util; assert importlib.util.find_spec('sqlite_vec') is None"

# The extension and worker suites against a disposable PostgreSQL container.
test/postgres:
	./tests/postgres/run.sh

test/compatibility:
	FRESHRSS_VERSION="$(FRESHRSS_VERSION)" ./tests/compatibility/run.sh

test/smoke:
	./tests/smoke/run.sh

test/integration:
	FRESHRSS_VERSION="$(FRESHRSS_VERSION)" ./tests/integration/run.sh

ci/verify-release-version:
	python3 scripts/ci/verify-release-version.py

ci/check: ci/verify-release-version test/fast

ci/all: ci/check test/all ci/extension-artifact ci/release-notes

ci/extension-artifact: ci/verify-release-version
	./scripts/ci/build-extension-artifact.sh

ci/release-notes:
	@FRESHRSS_VERSION="$(FRESHRSS_VERSION)" python3 scripts/ci/release-notes.py "$(IMAGE_METADATA)"
