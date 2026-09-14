#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNNER="$ROOT/scripts/category-native-wsl-verify.sh"
SEED="$ROOT/scripts/cloud-local-category-qa-seed.php"

failures=0
ok() { printf 'PASS: %s\n' "$*"; }
bad() { printf 'FAIL: %s\n' "$*" >&2; failures=$((failures + 1)); }
has() { grep -Fq -- "$1" "$2" && ok "$3" || bad "$3"; }
lacks() { ! grep -Eqi -- "$1" "$2" && ok "$3" || bad "$3"; }

bash -n "$RUNNER" && ok "WSL runner bash syntax" || bad "WSL runner bash syntax"
has 'LAB_DB=taxnest_category_lab' "$RUNNER" "fixed disposable MariaDB name"
has '127.0.0.1|localhost' "$RUNNER" "non-loopback DB host is refused"
has 'version_compare(PHP_VERSION, "8.4.1", ">=")' "$RUNNER" "PHP 8.4.1 minimum gate"
has 'category-native-lab1-check.sh' "$RUNNER" "Lab 1 is run"
has 'category-native-lab2-sqlite-check.sh' "$RUNNER" "SQLite Lab 2 is run"
has 'phpunit-category-mariadb.xml' "$RUNNER" "MariaDB PHPUnit configuration is isolated"
has 'cloud-local-category-smoke.mjs' "$RUNNER" "desktop/mobile browser journey is run"
has 'composer test' "$RUNNER" "full repository test command is final gate"
has "'taxnest_category_lab'" "$SEED" "fictional QA seed accepts the fixed disposable DB"
lacks 'taxnest\.pk|PRODUCTION_|ssh |workflow|gh pr|gh run|git push|git merge' "$RUNNER" \
  "runner has no production, workflow, merge or push path"

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures" >&2
  exit 1
fi
printf 'category-native-wsl-verify-check: ALL PASS\n'
