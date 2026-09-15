#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
mkdir -p .local
LAB_DB="$(mktemp "$ROOT/.local/category-native-lab2.XXXXXX.sqlite")"
cleanup() { rm -f "$LAB_DB"; }
trap cleanup EXIT

export APP_KEY="${APP_KEY:-base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=}"
export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="$LAB_DB"
export CACHE_STORE=array
export SESSION_DRIVER=array

# This exact path is newly created above and removed on exit. No shared or
# customer database can be selected by environment inheritance.
php artisan migrate:fresh --force --no-interaction
php vendor/bin/phpunit \
  tests/Feature/FbrPosSubmissionEvidenceTest.php \
  tests/Feature/PosServiceWorkOrderTest.php \
  tests/Feature/PosCustomAccessJsonBlockTest.php \
  tests/Feature/PosBranchIsolationTest.php \
  tests/Feature/PosMultiBranchScopeTest.php
