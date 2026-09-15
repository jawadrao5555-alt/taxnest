#!/usr/bin/env bash
# One-command, local-only promotion gate for PR #72 category/FBR work.
#
# It creates/DESTROYS only the fixed disposable database
# `taxnest_category_lab`. It refuses non-loopback DB hosts and never deploys,
# calls a fiscal endpoint, or reads production credentials.
#
# Lab layout (intentional):
#   Lab 1  — contract PHPUnit (sqlite :memory: via phpunit.xml)
#   Lab 2A — Feature PHPUnit on disposable sqlite file / :memory: overrides
#   Lab 2B — MariaDB-native migrate + isolation/concurrency probes (NOT PHPUnit)
#            because tests/TestCase.php requires sqlite :memory: by design
#   Lab 3  — fictional desktop/mobile Chromium journeys
#   Final  — full repository composer/php artisan test (sqlite)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

LAB_DB=taxnest_category_lab
LAB_USER="${TAXNEST_DEV_USER:-taxnest_dev}"
LAB_PASS="${TAXNEST_DEV_PASSWORD:-taxnest_local_dev_only}"
LAB_HOST="${CATEGORY_LAB_DB_HOST:-127.0.0.1}"
LAB_PORT_HINT="${CATEGORY_LAB_DB_PORT:-}"
SERVE_PORT="${CATEGORY_LAB_HTTP_PORT:-8872}"
APP_KEY_VALUE="${APP_KEY:-base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=}"

step() { printf '\n==> %s\n' "$*"; }
fail() { printf 'CATEGORY WSL VERIFY FAILED: %s\n' "$*" >&2; exit 1; }

case "$LAB_HOST" in
  127.0.0.1|localhost) ;;
  *) fail "DB host must be loopback, got ${LAB_HOST}" ;;
esac
[[ "$SERVE_PORT" =~ ^[0-9]+$ ]] || fail "HTTP port must be numeric"

command -v php >/dev/null || fail "php is missing (PHP 8.4.1+ required)"
command -v composer >/dev/null || fail "composer is missing"
command -v node >/dev/null || fail "node is missing"
command -v npm >/dev/null || fail "npm is missing"
command -v mysql >/dev/null || fail "mysql client is missing"
command -v mysqladmin >/dev/null || fail "mysqladmin is missing"

php -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' \
  || fail "PHP $(php -r 'echo PHP_VERSION;') is below required 8.4.1"
for ext in pdo_sqlite pdo_mysql mbstring xml curl zip openssl fileinfo tokenizer ctype json bcmath; do
  php -m | grep -qi "^${ext}$" || fail "missing PHP extension: ${ext}"
done

detect_loopback_mariadb_port() {
  local candidates=() p seen=""
  [[ -n "$LAB_PORT_HINT" ]] && candidates+=("$LAB_PORT_HINT")
  candidates+=(3306 3307)
  for p in "${candidates[@]}"; do
    [[ "$p" =~ ^[0-9]+$ ]] || continue
    case " $seen " in *" $p "*) continue ;; esac
    seen+=" $p"
    if mysqladmin --protocol=tcp -h"$LAB_HOST" -P"$p" ping --silent >/dev/null 2>&1 \
      && mysql --protocol=tcp -h"$LAB_HOST" -P"$p" -u"$LAB_USER" -p"$LAB_PASS" -e 'SELECT 1' >/dev/null 2>&1; then
      printf '%s' "$p"
      return 0
    fi
  done
  return 1
}

step "Locked dependencies"
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi
composer validate --no-check-publish
composer check-platform-reqs
if [ ! -d node_modules ]; then
  npm ci
fi
node --check pra-agent/src/agent.js
node --check scripts/cloud-local-category-smoke.mjs
bash scripts/tests/category-native-wsl-verify-check.sh

step "Lab 1 — contracts, profiles, callback matrix"
# Clear any inherited DB_* so phpunit.xml sqlite :memory: wins.
unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET || true
bash scripts/tests/category-native-lab1-check.sh

step "Lab 2A — isolated SQLite integration (PHPUnit / TestCase)"
unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET || true
bash scripts/tests/category-native-lab2-sqlite-check.sh

step "Lab 2B — disposable MariaDB parity (native probes, not PHPUnit)"
LAB_PORT="$(detect_loopback_mariadb_port)" \
  || fail "no loopback MariaDB accepting ${LAB_USER} (tried hint/3306/3307)"
export CATEGORY_LAB_DB_HOST="$LAB_HOST"
export CATEGORY_LAB_DB_PORT="$LAB_PORT"
# Optionally ensure default cloud-dev DB tooling when port 3306 is the match;
# never rewrite cloud-dev-start.sh to hard-force another port.
if [[ "$LAB_PORT" == "3306" ]]; then
  TAXNEST_DEV_DB="$LAB_DB" \
  TAXNEST_DEV_USER="$LAB_USER" \
  TAXNEST_DEV_PASSWORD="$LAB_PASS" \
    bash scripts/cloud-dev-start.sh || true
fi
bash scripts/tests/category-native-lab2-mariadb-check.sh

step "Lab 3 — fictional desktop and mobile Chromium journeys"
export APP_ENV=local
export APP_KEY="$APP_KEY_VALUE"
export DB_CONNECTION=mysql
export DB_HOST="$LAB_HOST"
export DB_PORT="$LAB_PORT"
export DB_DATABASE="$LAB_DB"
export DB_USERNAME="$LAB_USER"
export DB_PASSWORD="$LAB_PASS"
export CACHE_STORE=file
export SESSION_DRIVER=file
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array

[[ "$DB_DATABASE" == "taxnest_category_lab" ]] || fail "refusing Lab 3 migrate on ${DB_DATABASE}"
php artisan migrate:fresh --force --no-interaction
php scripts/cloud-local-category-qa-seed.php

mkdir -p .local
SERVE_LOG=.local/category-wsl-serve.log
SERVER_PID=""
cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

php artisan serve --host=127.0.0.1 --port="$SERVE_PORT" >"$SERVE_LOG" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 60); do
  if curl --silent --fail "http://127.0.0.1:${SERVE_PORT}/pos/login" >/dev/null; then
    break
  fi
  kill -0 "$SERVER_PID" 2>/dev/null || {
    tail -n 80 "$SERVE_LOG" >&2 || true
    fail "local Laravel server exited"
  }
  sleep 1
done
curl --silent --fail "http://127.0.0.1:${SERVE_PORT}/pos/login" >/dev/null \
  || fail "local Laravel server did not become ready"

BASE_URL="http://127.0.0.1:${SERVE_PORT}" \
  node scripts/cloud-local-category-smoke.mjs

cleanup
SERVER_PID=""
trap - EXIT

step "Full application regression (repository test command)"
unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET || true
export SESSION_DRIVER=array
export CACHE_STORE=array
# Full suite exceeds Composer's default 300s process timeout on this tree.
COMPOSER_PROCESS_TIMEOUT=0 composer test

printf '\nCATEGORY WSL VERIFY: ALL AVAILABLE GATES PASSED\n'
printf 'Evidence: .local/browser-evidence/category-* and %s\n' "$SERVE_LOG"
