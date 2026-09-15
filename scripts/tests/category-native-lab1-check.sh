#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

export APP_KEY="${APP_KEY:-base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=}"
export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE=:memory:
export CACHE_STORE=array
export SESSION_DRIVER=array

php vendor/bin/phpunit \
  tests/Unit/PosCategoryProfilesTest.php \
  tests/Feature/PosCustomAccessInvariantsTest.php \
  tests/Feature/FbrPosSubmissionEvidenceTest.php \
  tests/Feature/PosServiceWorkOrderTest.php
