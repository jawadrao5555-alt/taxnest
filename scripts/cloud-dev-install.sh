#!/bin/bash
# Idempotent Cloud Agent / local install step for TaxNest.
# Safe to re-run. Does NOT deploy, SSH, or touch production.
#
# Installs/verifies:
#   - PHP 8.4 MySQL (+ intl) extensions when apt is available
#   - MariaDB server/client when apt is available
#   - Composer dependencies
#   - npm dependencies (public registry lockfile)
#
# Usage: bash scripts/cloud-dev-install.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

step() { echo ""; echo "==> $*"; }

have_sudo() { sudo -n true 2>/dev/null; }

ensure_apt_packages() {
  local need=()
  php -m 2>/dev/null | grep -qi '^pdo_mysql$' || need+=(php8.4-mysql)
  php -m 2>/dev/null | grep -qi '^intl$' || need+=(php8.4-intl)
  command -v mysqld >/dev/null 2>&1 || command -v mariadbd >/dev/null 2>&1 || need+=(mariadb-server)
  command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1 || need+=(mariadb-client)

  if [ "${#need[@]}" -eq 0 ]; then
    echo "Required apt packages already present."
    return 0
  fi

  if ! have_sudo; then
    echo "WARNING: missing packages (${need[*]}) and sudo is unavailable." >&2
    echo "         Install manually, then re-run." >&2
    return 1
  fi

  step "Installing apt packages: ${need[*]}"
  export DEBIAN_FRONTEND=noninteractive
  sudo -n apt-get update -qq
  sudo -n apt-get install -y -qq "${need[@]}"
}

step "PHP / Composer"
php -v | head -1
composer -V | head -1
php -m | grep -qi '^pdo_mysql$' && echo "pdo_mysql: yes" || echo "pdo_mysql: missing (will try apt)"
php -m | grep -qi '^intl$' && echo "intl: yes" || echo "intl: missing (will try apt)"

step "System packages (PHP MySQL + MariaDB)"
ensure_apt_packages || true

step "Composer install"
composer install --no-interaction --prefer-dist

step "npm ci"
if ! command -v npm >/dev/null 2>&1; then
  echo "npm not found — install Node.js 20+ then re-run." >&2
  exit 1
fi
if grep -q 'package-firewall.replit.local' package-lock.json 2>/dev/null; then
  echo "FATAL: package-lock.json still references package-firewall.replit.local" >&2
  exit 1
fi
npm ci --no-fund --no-audit

step "cloud-dev-install complete"
echo "Next: bash scripts/cloud-dev-start.sh && bash scripts/cloud-dev-bootstrap.sh"
