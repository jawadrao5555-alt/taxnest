#!/usr/bin/env bash
# Lab 2B — MariaDB-native parity for category/FBR work (NOT Laravel PHPUnit).
#
# tests/TestCase.php intentionally requires sqlite :memory:. Running Feature
# PHPUnit against mysql therefore fails closed by design. This probe instead
# migrates the fixed disposable database taxnest_category_lab and asserts
# schema + tenant isolation + series concurrency on MariaDB itself.
#
# Safe local-only: loopback host, fictional lab DB name, no fiscal endpoints.

set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

LAB_DB=taxnest_category_lab
LAB_USER="${TAXNEST_DEV_USER:-taxnest_dev}"
LAB_PASS="${TAXNEST_DEV_PASSWORD:-taxnest_local_dev_only}"
LAB_HOST="${CATEGORY_LAB_DB_HOST:-127.0.0.1}"
LAB_PORT="${CATEGORY_LAB_DB_PORT:-}"

fail() { printf 'CATEGORY MARIADB LAB FAILED: %s\n' "$*" >&2; exit 1; }

case "$LAB_HOST" in
  127.0.0.1|localhost) ;;
  *) fail "DB host must be loopback, got ${LAB_HOST}" ;;
esac

detect_port() {
  local candidates=()
  [[ -n "${LAB_PORT}" ]] && candidates+=("${LAB_PORT}")
  candidates+=(3306 3307)
  local seen="" p
  for p in "${candidates[@]}"; do
    [[ "$p" =~ ^[0-9]+$ ]] || continue
    case " $seen " in *" $p "*) continue ;; esac
    seen+=" $p"
    if mysqladmin --protocol=tcp -h"$LAB_HOST" -P"$p" ping --silent >/dev/null 2>&1 \
      && mysql --protocol=tcp -h"$LAB_HOST" -P"$p" -u"$LAB_USER" -p"$LAB_PASS" -e 'SELECT 1' >/dev/null 2>&1; then
      printf '%s\n' "$p"
      return 0
    fi
  done
  return 1
}

PORT="$(detect_port | tail -n1)" || fail "no loopback MariaDB accepting ${LAB_USER} on 3306/3307 (set CATEGORY_LAB_DB_PORT)"
[[ "$PORT" =~ ^[0-9]+$ ]] || fail "detected MariaDB port is not numeric: ${PORT}"

echo "MariaDB Lab 2B using ${LAB_HOST}:${PORT} database=${LAB_DB}"

mysql --protocol=tcp -h"$LAB_HOST" -P"$PORT" -u"$LAB_USER" -p"$LAB_PASS" \
  -e "CREATE DATABASE IF NOT EXISTS \`${LAB_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

export APP_ENV=local
export APP_KEY="${APP_KEY:-base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=}"
export DB_CONNECTION=mysql
export DB_HOST="$LAB_HOST"
export DB_PORT="$PORT"
export DB_DATABASE="$LAB_DB"
export DB_USERNAME="$LAB_USER"
export DB_PASSWORD="$LAB_PASS"
export CACHE_STORE=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array

# Destructive only against the fixed disposable lab name.
[[ "$DB_DATABASE" == "taxnest_category_lab" ]] || fail "refusing migrate:fresh on non-lab DB ${DB_DATABASE}"

php artisan migrate:fresh --force --no-interaction
php "$ROOT/scripts/tests/category-native-lab2-mariadb-check.php"
status=$?
exit "$status"
