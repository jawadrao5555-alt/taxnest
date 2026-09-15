#!/usr/bin/env bash
# One exact WSL verification command for Hotel / Guest House final delivery.
# Loopback + disposable DB only. Does not merge, deploy, or call FBR/DI.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

php artisan test --filter=HotelGuestHouse
VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-qa-seed.php
php artisan serve --host=127.0.0.1 --port=8000 >/tmp/hotel-final-serve.log 2>&1 &
SERVE_PID=$!
cleanup() { kill "$SERVE_PID" 2>/dev/null || true; }
trap cleanup EXIT
for i in $(seq 1 60); do
  curl -sf -o /dev/null http://127.0.0.1:8000/pos/login && break
  sleep 1
done
BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-hotel-smoke.mjs
php scripts/cloud-local-hotel-http-smoke.php

# WSL: system MariaDB on 3306 often uses unix_socket for localhost, so a
# default `mysql -h127.0.0.1` probe skips even when workspace MariaDB on
# 3307 / .local/mariadb socket is healthy. Probe socket + TCP 3306/3307.
hotel_mariadb_reachable() {
  local user="${TAXNEST_DEV_USER:-taxnest_dev}"
  local pass="${TAXNEST_DEV_PASSWORD:-taxnest_local_dev_only}"
  local host="${HOTEL_MARIADB_HOST:-127.0.0.1}"
  local sock="${HOTEL_MARIADB_SOCKET:-$ROOT/.local/mariadb/run/mysqld.sock}"
  local p seen=""
  if [[ -S "$sock" ]] \
    && mysqladmin --socket="$sock" ping --silent >/dev/null 2>&1 \
    && mysql --socket="$sock" -u"$user" -p"$pass" -e 'SELECT 1' >/dev/null 2>&1; then
    export HOTEL_MARIADB_SOCKET="$sock"
    return 0
  fi
  case "$host" in
    127.0.0.1|localhost) ;;
    *) return 1 ;;
  esac
  local candidates=()
  [[ -n "${HOTEL_MARIADB_PORT:-}" ]] && candidates+=("$HOTEL_MARIADB_PORT")
  candidates+=(3306 3307)
  for p in "${candidates[@]}"; do
    [[ "$p" =~ ^[0-9]+$ ]] || continue
    case " $seen " in *" $p "*) continue ;; esac
    seen+=" $p"
    if mysqladmin --protocol=tcp -h"$host" -P"$p" ping --silent >/dev/null 2>&1 \
      && mysql --protocol=tcp -h"$host" -P"$p" -u"$user" -p"$pass" -e 'SELECT 1' >/dev/null 2>&1; then
      export DB_PORT="$p"
      return 0
    fi
  done
  return 1
}

if command -v mysql >/dev/null && hotel_mariadb_reachable; then
  bash scripts/tests/hotel-mariadb-concurrency-check.sh
  bash scripts/tests/hotel-mariadb-isolation-board-check.sh
else
  echo "SKIP: local MariaDB hotel probes (taxnest_dev not reachable)"
fi
php artisan test
