#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." &>/dev/null && pwd)"
cd "$ROOT"

docker compose up -d --quiet-pull mysql >/dev/null
docker compose run --rm app php vendor/bin/phpunit --configuration phpunit.xml.dist "$@"

