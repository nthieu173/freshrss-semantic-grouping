.PHONY: check php-check worker-check integration

check: php-check worker-check

php-check:
	php extension/tests/run.php
	find extension -type f \( -name '*.php' -o -name '*.phtml' \) -print0 | xargs -0 -n1 php -l

worker-check:
	cd worker && uv sync --locked --extra dev
	cd worker && uv run pytest
	cd worker && uv run ruff check src tests
	cd worker && uv run mypy src

integration:
	./tests/integration/run.sh
