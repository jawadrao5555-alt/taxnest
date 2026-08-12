#!/bin/bash
# One-command live (cPanel) deploy for TaxNest.
# Usage: bash scripts/deploy-live.sh
#
# Does the FULL runbook from .agents/memory/cpanel-deployment.md:
#   1. Push workspace HEAD to GitHub main (git push origin HEAD:main)
#   2. Over SSH on the live server (/home/taxnestc/public_html):
#        git pull origin main
#        php artisan migrate --force        (only when the gap includes migrations)
#        config/route/view cache rebuild
#        WEB OPcache reset via temp public/r.php + curl (real PHP-FPM hit)
#   3. Verify: live HEAD == workspace HEAD, and homepage curls 200.
#
# Fails LOUDLY on any step — no silent half-deploys. Safe to re-run.

set -uo pipefail
cd "$(dirname "$0")/.."

KEY="/home/runner/workspace/.local/ssh/cpanel_deploy_key"
# Cloudflare (Aug 2026): taxnest.com.pk A record is proxied — SSH must go to
# the origin cPanel box directly. cpanel.taxnest.com.pk is DNS-only (grey cloud).
HOST="taxnestc@cpanel.taxnest.com.pk"
PORT=22
LIVE_DIR="/home/taxnestc/public_html"
LIVE_URL="https://taxnest.com.pk"   # NOT taxnest.pk (different server, 403)
SSH_OPTS=(-i "$KEY" -p "$PORT" -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new)

fail() { echo ""; echo "DEPLOY FAILED: $*" >&2; exit 1; }
step() { echo ""; echo "==> $*"; }

run_ssh() { timeout 120 ssh "${SSH_OPTS[@]}" "$HOST" "$@"; }

[ -f "$KEY" ] || fail "SSH key not found at $KEY"

# ---------------------------------------------------------------- 0. Preflight
step "Preflight: workspace state"
LOCAL_HEAD=$(git rev-parse HEAD 2>/dev/null) || fail "cannot read workspace HEAD"
echo "workspace HEAD: $LOCAL_HEAD"

if [ -n "$(git status --porcelain 2>/dev/null | grep -v '^??' || true)" ]; then
  echo "WARNING: workspace has uncommitted tracked changes — they will NOT deploy." >&2
  echo "         (Checkpoint commits happen at turn end; deploy again after commit.)" >&2
fi

step "Preflight: POS white-screen check (key pages render + inline JS parses)"
if [ "${SKIP_WHITE_SCREEN_CHECK:-0}" = "1" ]; then
  echo "SKIPPED (SKIP_WHITE_SCREEN_CHECK=1) — only skip for emergency hotfixes." >&2
else
  bash scripts/pos-white-screen-check.sh
  WS_RC=$?
  if [ $WS_RC -eq 2 ]; then
    fail "white-screen check could not run (dev server/MySQL down?) — start the Laravel Server + MySQL Staging workflows, or SKIP_WHITE_SCREEN_CHECK=1 to bypass"
  elif [ $WS_RC -ne 0 ]; then
    fail "white-screen check FAILED — a key POS page is broken; fix before deploying"
  fi
fi

step "Preflight: POS plan-gate matrix check (Starter/Business/Pro/Pro Max/Unlimited)"
if [ "${SKIP_PLAN_GATE_CHECK:-0}" = "1" ]; then
  echo "SKIPPED (SKIP_PLAN_GATE_CHECK=1) — only skip for emergency hotfixes." >&2
else
  env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
    php scripts/plan-gate-check.php
  PG_RC=$?
  if [ $PG_RC -eq 2 ]; then
    fail "plan-gate check could not run (MySQL Staging down / plan rows missing?) — start the MySQL Staging workflow, or SKIP_PLAN_GATE_CHECK=1 to bypass"
  elif [ $PG_RC -ne 0 ]; then
    fail "plan-gate check FAILED — the package gate matrix regressed; fix before deploying"
  fi
fi

step "Preflight: SSH connectivity + live HEAD"
LIVE_HEAD_BEFORE=$(run_ssh "cd $LIVE_DIR && git rev-parse HEAD" 2>/dev/null) \
  || fail "cannot reach live server over SSH (or live git repo broken)"
echo "live HEAD (before): $LIVE_HEAD_BEFORE"

if [ "$LIVE_HEAD_BEFORE" = "$LOCAL_HEAD" ]; then
  # cPanel auto-deploy (.cpanel.yml, triggered by pushes to origin main — e.g. task
  # merges) may have already pulled this HEAD. Racing auto-deploys can leave POISONED
  # compiled views (mtime newer than blade => Laravel never recompiles), and its
  # migrate step is '|| true' (silent failure). So: refresh everything anyway.
  echo "Live is already at workspace HEAD (cPanel auto-deploy likely ran on push)."
  echo "Refreshing migrate + caches + OPcache anyway — racing auto-deploys can leave stale/poisoned state."

  step "Live (refresh): php artisan migrate --force (idempotent)"
  run_ssh "cd $LIVE_DIR && /usr/local/bin/ea-php84 artisan migrate --force 2>&1" \
    || fail "migrate --force failed on live"

  step "Live (refresh): rebuild caches (config/route/view)"
  run_ssh "cd $LIVE_DIR && /usr/local/bin/ea-php84 artisan config:clear && /usr/local/bin/ea-php84 artisan cache:clear && /usr/local/bin/ea-php84 artisan route:clear && /usr/local/bin/ea-php84 artisan view:clear && /usr/local/bin/ea-php84 artisan config:cache && /usr/local/bin/ea-php84 artisan route:cache && /usr/local/bin/ea-php84 artisan view:cache 2>&1" \
    || fail "cache rebuild failed on live"

  step "Live (refresh): reset WEB OPcache"
  OPCACHE_OUT=$(run_ssh "cd $LIVE_DIR && echo '<?php opcache_reset(); echo \"OPCACHE_RESET_OK \".__DIR__; ?>' > public/r.php && curl -s $LIVE_URL/r.php ; RC=\$? ; rm -f public/r.php ; exit \$RC")
  echo "$OPCACHE_OUT"
  echo "$OPCACHE_OUT" | grep -q "OPCACHE_RESET_OK" || fail "web OPcache reset did not confirm"

  HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$LIVE_URL/")
  echo "GET $LIVE_URL/ -> $HTTP_CODE"
  [ "$HTTP_CODE" = "200" ] || fail "homepage returned $HTTP_CODE after refresh"

  echo "DEPLOY OK (refresh-only): live already at workspace HEAD; migrate + caches + OPcache refreshed."
  exit 0
fi

if ! git merge-base --is-ancestor "$LIVE_HEAD_BEFORE" "$LOCAL_HEAD" 2>/dev/null; then
  # Live commit unknown/diverged — do NOT blind-deploy over drift.
  echo "Live HEAD is not an ancestor of workspace HEAD." >&2
  echo "Either the workspace is behind origin, or live has drifted." >&2
  fail "refusing to deploy over divergence — investigate (git fetch; compare $LIVE_HEAD_BEFORE vs $LOCAL_HEAD)"
fi

# Live worktree must be clean for a fast-forward pull (untracked junk is fine).
DIRTY=$(run_ssh "cd $LIVE_DIR && git status --porcelain | grep -v '^??' | head -20" 2>/dev/null || true)
if [ -n "$DIRTY" ]; then
  echo "Live worktree has MODIFIED tracked files:" >&2
  echo "$DIRTY" >&2
  fail "live tree dirty — reconcile first (runbook: verify content is upstream, then git stash && pull). Not auto-stashing."
fi

# Does the gap include migrations / routes / composer changes?
GAP_FILES=$(git diff --name-only "$LIVE_HEAD_BEFORE".."$LOCAL_HEAD" 2>/dev/null || true)
NEED_MIGRATE=0; NEED_COMPOSER=0
echo "$GAP_FILES" | grep -q '^database/migrations/' && NEED_MIGRATE=1
echo "$GAP_FILES" | grep -qE '^composer\.(json|lock)$' && NEED_COMPOSER=1
echo "gap: $(echo "$GAP_FILES" | grep -c .) file(s); migrations=$NEED_MIGRATE composer=$NEED_COMPOSER"

# ------------------------------------------------------------------ 1. Push
step "Push workspace HEAD to origin main"
if ! git push origin HEAD:main 2>&1; then
  # Stale ref lock can make push error AFTER succeeding — verify true remote state.
  REMOTE_MAIN=$(git ls-remote origin main 2>/dev/null | awk '{print $1}')
  if [ "$REMOTE_MAIN" = "$LOCAL_HEAD" ]; then
    echo "push reported an error but origin/main already == workspace HEAD (stale ref lock) — continuing."
  else
    fail "git push origin HEAD:main failed (remote main=$REMOTE_MAIN)"
  fi
fi

# ------------------------------------------------------------------ 2. Deploy over SSH
step "Live: git pull origin main"
PULL_OUT=$(run_ssh "cd $LIVE_DIR && git pull origin main 2>&1"); PULL_RC=$?
echo "$PULL_OUT"
[ $PULL_RC -eq 0 ] || fail "git pull failed on live (see output above; untracked-overwrite blockers? see runbook)"

LIVE_HEAD_AFTER=$(run_ssh "cd $LIVE_DIR && git rev-parse HEAD" 2>/dev/null)
[ "$LIVE_HEAD_AFTER" = "$LOCAL_HEAD" ] \
  || fail "pull ran but live HEAD ($LIVE_HEAD_AFTER) != workspace HEAD ($LOCAL_HEAD) — pull may have silently aborted"
echo "live HEAD (after): $LIVE_HEAD_AFTER — matches workspace."

if [ $NEED_COMPOSER -eq 1 ]; then
  step "Live: composer install (composer.json/lock changed in gap)"
  # Composer MUST run under /usr/local/bin/php (CloudLinux alt-php — the SAME
  # runtime as the lsphp web handler, has gd/iconv). ea-php84 CLI lacks gd+iconv
  # → mPDF/PhpSpreadsheet platform checks fail (discovered 4 Aug 2026).
  run_ssh "cd $LIVE_DIR && /usr/local/bin/php \$(command -v composer || echo composer.phar) install --no-interaction --prefer-dist --no-dev 2>&1" \
    || fail "composer install failed on live"
fi

if [ $NEED_MIGRATE -eq 1 ]; then
  step "Live: php artisan migrate --force (gap includes migrations)"
  run_ssh "cd $LIVE_DIR && /usr/local/bin/ea-php84 artisan migrate --force 2>&1" \
    || fail "migrate --force failed on live"
else
  step "No new migrations in gap — skipping migrate (idempotent anyway; re-run manually if unsure)"
fi

step "Live: rebuild caches (config/route/view)"
run_ssh "cd $LIVE_DIR && /usr/local/bin/ea-php84 artisan config:clear && /usr/local/bin/ea-php84 artisan cache:clear && /usr/local/bin/ea-php84 artisan route:clear && /usr/local/bin/ea-php84 artisan view:clear && /usr/local/bin/ea-php84 artisan config:cache && /usr/local/bin/ea-php84 artisan route:cache && /usr/local/bin/ea-php84 artisan view:cache 2>&1" \
  || fail "cache rebuild failed on live"

step "Live: reset WEB OPcache (temp public/r.php + web hit)"
OPCACHE_OUT=$(run_ssh "cd $LIVE_DIR && echo '<?php opcache_reset(); echo \"OPCACHE_RESET_OK \".__DIR__; ?>' > public/r.php && curl -s $LIVE_URL/r.php ; RC=\$? ; rm -f public/r.php ; exit \$RC")
echo "$OPCACHE_OUT"
echo "$OPCACHE_OUT" | grep -q "OPCACHE_RESET_OK" || fail "web OPcache reset did not confirm (r.php output above) — live may serve stale compiled code"

# ------------------------------------------------------------------ 3. Verify
step "Verify: homepage returns 200"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$LIVE_URL/")
echo "GET $LIVE_URL/ -> $HTTP_CODE"
[ "$HTTP_CODE" = "200" ] || fail "homepage returned $HTTP_CODE after deploy — investigate immediately (live log: storage/logs/laravel.log)"

step "Verify: no fresh production errors in live log"
FRESH_ERRORS=$(run_ssh "cd $LIVE_DIR && grep 'production.ERROR' storage/logs/laravel.log 2>/dev/null | grep \"\$(date +%Y-%m-%d)\" | tail -5" 2>/dev/null || true)
if [ -n "$FRESH_ERRORS" ]; then
  echo "NOTE: today's production.ERROR lines (may be pre-existing noise, e.g. 02:00 Compliance cron):"
  echo "$FRESH_ERRORS"
fi

echo ""
echo "==============================================================="
echo "DEPLOY OK: live HEAD == workspace HEAD ($LOCAL_HEAD)"
echo "           pull + caches + web OPcache reset done; homepage 200."
echo "==============================================================="
exit 0
