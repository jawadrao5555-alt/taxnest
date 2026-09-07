#!/bin/bash
# Per-boot Cloud Agent start: ensure local MariaDB is running and the
# local-only development database/user exist.
#
# Safe to re-run. Does NOT deploy or touch production hosts.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

step() { echo ""; echo "==> $*"; }

DB_NAME="${TAXNEST_DEV_DB:-taxnest_dev}"
DB_USER="${TAXNEST_DEV_USER:-taxnest_dev}"
# Local-only placeholder password — must match .env.example. NOT production.
DB_PASS="${TAXNEST_DEV_PASSWORD:-taxnest_local_dev_only}"

have_sudo() { sudo -n true 2>/dev/null; }

start_mariadb() {
  if mysqladmin --protocol=tcp -h127.0.0.1 -P3306 ping --silent 2>/dev/null; then
    echo "MariaDB already accepting connections on 127.0.0.1:3306"
    return 0
  fi
  if ! have_sudo; then
    echo "MariaDB is not running and sudo is unavailable." >&2
    return 1
  fi
  step "Starting MariaDB"
  if command -v service >/dev/null 2>&1; then
    sudo -n service mariadb start || sudo -n service mysql start || true
  fi
  if command -v systemctl >/dev/null 2>&1; then
    sudo -n systemctl start mariadb 2>/dev/null || sudo -n systemctl start mysql 2>/dev/null || true
  fi
  # Wait up to ~30s
  local i
  for i in $(seq 1 30); do
    if mysqladmin --protocol=tcp -h127.0.0.1 -P3306 ping --silent 2>/dev/null \
      || sudo -n mysqladmin ping --silent 2>/dev/null; then
      echo "MariaDB is up."
      return 0
    fi
    sleep 1
  done
  echo "MariaDB failed to become ready." >&2
  return 1
}

ensure_dev_database() {
  step "Ensuring local database ${DB_NAME} and user ${DB_USER}"
  if ! have_sudo; then
    # Try connecting as the app user already
    if mysql --protocol=tcp -h127.0.0.1 -P3306 -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1; then
      echo "Local DB already reachable as ${DB_USER}."
      return 0
    fi
    echo "Cannot create DB without sudo, and ${DB_USER} cannot connect yet." >&2
    return 1
  fi
  sudo -n mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  mysql --protocol=tcp -h127.0.0.1 -P3306 -u"$DB_USER" -p"$DB_PASS" -e "SELECT 'OK' AS cloud_dev_db" "$DB_NAME"
}

start_mariadb
ensure_dev_database
echo "cloud-dev-start complete"
