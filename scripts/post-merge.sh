#!/bin/bash
set -euo pipefail

# Post-merge reconciliation for TaxNest (Laravel 12 / PHP 8.4).
# Runs automatically after a task agent's work is merged into the main env.
# Must be idempotent and non-interactive (stdin is closed during post-merge).

# Always operate from the project root (scripts/ is one level down).
cd "$(dirname "$0")/.."

# Dev DB is MySQL `taxnest_staging`. Unset the Replit Postgres vars so artisan
# and composer's Laravel scripts target MySQL — same prefix the workflows use.
ENVUNSET="env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE"

# 1. Reconcile PHP dependencies (fast no-op when composer.lock is unchanged).
if [ -f composer.json ]; then
  $ENVUNSET composer install --no-interaction --prefer-dist --no-progress
fi

# 2. Apply any new database migrations against the dev MySQL staging DB.
$ENVUNSET php artisan migrate --force

# 3. Rebuild caches so merged config / views / routes take effect.
$ENVUNSET php artisan config:clear
$ENVUNSET php artisan view:clear
$ENVUNSET php artisan route:clear
