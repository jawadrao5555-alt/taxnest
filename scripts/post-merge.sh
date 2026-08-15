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

# 1b. Self-heal dev MySQL (recurring: workspace sleep/wake kills mysqld and the
# whole post-merge dies at the first artisan DB query — "Connection refused
# 127.0.0.1:9000"). If the port is closed: clear stale socket/lock files (only
# when no mysqld process exists) and start mysqld with the same defaults-file
# the "MySQL Staging" workflow uses, then wait for the port.
mysql_up() { (echo > /dev/tcp/127.0.0.1/9000) 2>/dev/null; }
if ! mysql_up; then
  echo "Dev MySQL down — self-healing before migrations..."
  if ! pgrep -x mysqld >/dev/null 2>&1; then
    rm -f .local/mysql_run/mysql.sock .local/mysql_run/mysql.sock.lock .local/mysql_run/mysql.pid
    nohup mysqld --defaults-file="$PWD/.local/mysql_run/my.cnf" >/dev/null 2>&1 &
  fi
  for _ in $(seq 1 45); do mysql_up && break; sleep 2; done
  if mysql_up; then
    echo "Dev MySQL is up (self-healed). NOTE: the 'MySQL Staging' workflow may show"
    echo "as failed/stopped — before restarting it, kill this orphan mysqld first"
    echo "(pkill -x mysqld; rm stale socket/lock files) or the restart will abort"
    echo "with 'Another process is using unix socket file'."
  else
    echo "ERROR: dev MySQL still unreachable on 127.0.0.1:9000 after 90s." >&2
    exit 1
  fi
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
  # non-zero status (1 = live behind, 2 = SSH unreachable/real drift,
  # 3 = reconcilable lineage divergence) abort the merge.
  GAP_RC=0
  bash scripts/check-live-deploy.sh || GAP_RC=$?
  if [ "$GAP_RC" -eq 1 ] || [ "$GAP_RC" -eq 3 ]; then
    [ "$GAP_RC" -eq 3 ] && echo "Reconcilable lineage divergence (Task 703) — deploy-live.sh will merge -s ours and deploy..."
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
