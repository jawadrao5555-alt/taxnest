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

# 4. Deploy-gap reminder: merged code only lands in the WORKSPACE — the live
# cPanel site needs a push + pull. Compare workspace HEAD vs live HEAD and
# print a loud reminder if live is behind. NEVER fails the merge (best-effort:
# SSH may be unavailable), hence the trailing `|| true`.
if [ -f scripts/check-live-deploy.sh ]; then
  bash scripts/check-live-deploy.sh || {
    echo "==============================================================="
    echo "REMINDER: live (cPanel) may NOT have this merged code yet."
    echo "Run: bash scripts/check-live-deploy.sh   (and deploy if behind —"
    echo "see .agents/memory/cpanel-deployment.md runbook)."
    echo "==============================================================="
    true
  }
fi
