#!/usr/bin/env bash
# Run ProSe Core PHPUnit tests (Unix).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f vendor/bin/phpunit ]]; then
	echo "PHPUnit not found. Run: composer install" >&2
	exit 1
fi

php vendor/bin/phpunit -c phpunit.xml.dist "$@"
