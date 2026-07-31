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

# 4. Auto-deploy to live: merged code only lands in the WORKSPACE — the live
# cPanel site needs push + pull + caches + OPcache reset. If live is behind,
# run the full one-command deploy (scripts/deploy-live.sh). NEVER fails the
# merge (best-effort: SSH may be unavailable) — but prints a LOUD warning if
# the deploy could not complete, so the gap is never silent.
if [ -f scripts/check-live-deploy.sh ] && [ -f scripts/deploy-live.sh ]; then
  # NOTE: set -e is active — capture the checker's exit code without letting a
  # non-zero status (1 = live behind, 2 = SSH unreachable) abort the merge.
  GAP_RC=0
  bash scripts/check-live-deploy.sh || GAP_RC=$?
  if [ "$GAP_RC" -eq 1 ]; then
    echo "Live is behind — running one-command deploy (scripts/deploy-live.sh)..."
    bash scripts/deploy-live.sh || {
      echo "==============================================================="
      echo "WARNING: AUTO-DEPLOY FAILED — live (cPanel) does NOT have this"
      echo "merged code yet. Fix and re-run: bash scripts/deploy-live.sh"
      echo "(runbook: .agents/memory/cpanel-deployment.md)"
      echo "==============================================================="
      true
    }
  elif [ "$GAP_RC" -eq 2 ]; then
    echo "==============================================================="
    echo "REMINDER: could not verify live HEAD (SSH issue?). Live may NOT"
    echo "have this merged code. Run: bash scripts/deploy-live.sh"
    echo "==============================================================="
  fi
fi
