#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNNER="$ROOT/scripts/category-native-wsl-verify.sh"
SEED="$ROOT/scripts/cloud-local-category-qa-seed.php"
MARIADB_LAB="$ROOT/scripts/tests/category-native-lab2-mariadb-check.sh"
MARIADB_PHP="$ROOT/scripts/tests/category-native-lab2-mariadb-check.php"

failures=0
ok() { printf 'PASS: %s\n' "$*"; }
bad() { printf 'FAIL: %s\n' "$*" >&2; failures=$((failures + 1)); }
has() { grep -Fq -- "$1" "$2" && ok "$3" || bad "$3"; }
lacks() { ! grep -Eqi -- "$1" "$2" && ok "$3" || bad "$3"; }

bash -n "$RUNNER" && ok "WSL runner bash syntax" || bad "WSL runner bash syntax"
bash -n "$MARIADB_LAB" && ok "MariaDB lab bash syntax" || bad "MariaDB lab bash syntax"

has 'LAB_DB=taxnest_category_lab' "$RUNNER" "fixed disposable MariaDB name"
has '127.0.0.1|localhost' "$RUNNER" "non-loopback DB host is refused"
has 'version_compare(PHP_VERSION, "8.4.1", ">=")' "$RUNNER" "PHP 8.4.1 minimum gate"
has 'category-native-lab1-check.sh' "$RUNNER" "Lab 1 is run"
has 'category-native-lab2-sqlite-check.sh' "$RUNNER" "SQLite Lab 2A is run"
has 'category-native-lab2-mariadb-check.sh' "$RUNNER" "MariaDB Lab 2B native probe is run"
has 'detect_loopback_mariadb_port' "$RUNNER" "loopback MariaDB port auto-detection"
has 'cloud-local-category-smoke.mjs' "$RUNNER" "desktop/mobile browser journey is run"
has 'COMPOSER_PROCESS_TIMEOUT=0' "$RUNNER" "full suite disables Composer process timeout"
has 'composer test' "$RUNNER" "full repository test command is final gate"
has "'taxnest_category_lab'" "$SEED" "fictional QA seed accepts the fixed disposable DB"
has 'Subscription' "$SEED" "category QA seed attaches an active subscription"
has 'app_update_seens' "$SEED" "category QA seed pre-marks What's New overlays"
has 'taxnest_category_lab' "$MARIADB_PHP" "MariaDB probe refuses non-lab database names"
has 'PosServiceWorkOrderService' "$MARIADB_PHP" "MariaDB probe exercises work-order service"

# Runner must NOT drive Laravel PHPUnit Feature suites with mysql — TestCase
# requires sqlite :memory:. The old defect generated phpunit-category-mariadb.xml.
lacks 'phpunit-category-mariadb\.xml' "$RUNNER" "runner does not generate mysql PHPUnit XML"
if grep -Eq 'DB_CONNECTION=mysql' "$RUNNER" && grep -Eq 'vendor/bin/phpunit' "$RUNNER"; then
  # Only fail if mysql export is in the same Lab 2B PHPUnit block (legacy defect).
  if grep -n 'vendor/bin/phpunit' "$RUNNER" | grep -qi mariadb; then
    bad "runner must not invoke PHPUnit for MariaDB Feature suites"
  else
    ok "PHPUnit is not used as the MariaDB Feature suite driver"
  fi
else
  ok "runner does not pair DB_CONNECTION=mysql with PHPUnit"
fi
lacks 'TAXNEST_DEV_PORT' "$ROOT/scripts/cloud-dev-start.sh" \
  "cloud-dev-start is not hard-patched with TAXNEST_DEV_PORT"

# Lab 2A must stay on sqlite PHPUnit paths.
has 'DB_CONNECTION=sqlite' "$ROOT/scripts/tests/category-native-lab2-sqlite-check.sh" "Lab 2A stays on sqlite"
has 'vendor/bin/phpunit' "$ROOT/scripts/tests/category-native-lab2-sqlite-check.sh" "Lab 2A still runs PHPUnit"

lacks 'taxnest\.pk|PRODUCTION_|ssh |workflow|gh pr|gh run|git push|git merge' "$RUNNER" \
  "runner has no production, workflow, merge or push path"

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures" >&2
  exit 1
fi
printf 'category-native-wsl-verify-check: ALL PASS\n'
