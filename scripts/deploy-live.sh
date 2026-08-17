#!/bin/bash
# One-command live (cPanel) deploy for TaxNest.
# Usage: bash scripts/deploy-live.sh [--no-elaan]
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
#
# ELAAN ENFORCEMENT (Task 999):
#   Every deploy MUST have a What's New announcement (AppUpdate row, audience
#   pos/all, is_published=1) created since the last deploy marker. If none is
#   found, the script fails with a loud message BEFORE deploying.
#
#   To create an announcement:  bash scripts/elaan-insert.sh --title "..." --point "..."
#   Emergency bypass (hotfixes): bash scripts/deploy-live.sh --no-elaan
#
#   After each successful deploy, a marker is written to:
#   /home/taxnestc/.taxnest-last-deploy-marker  (format: EPOCH|COMMIT_SHA)
#   This lets the next deploy know exactly what window to check.

set -uo pipefail

# ---------------------------------------------------------------- Parse flags
NO_ELAAN=0
for _arg in "$@"; do
  case "$_arg" in
    --no-elaan) NO_ELAAN=1 ;;
  esac
done
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

# Post-deploy cache-freshness tripwire (Task 1053): after a deploy, NO source
# file that feeds Laravel's caches may be newer than the built route cache on
# live. If any is, the cache rebuild silently didn't take — the exact state
# that 500'd every admin page on Aug 17 2026 ("Route [...] not defined").
# HARD FAIL: a deploy that leaves stale caches is not a successful deploy.
verify_live_cache_fresh() {
  step "Verify: live caches fresh (no source file newer than route cache — Task 1053)"
  local OUT
  OUT=$(run_ssh bash -s <<'FRESHPROBE' 2>/dev/null
cd /home/taxnestc/public_html || { echo PROBE_CD_FAIL; exit 0; }
RC=$(ls -t bootstrap/cache/routes-*.php 2>/dev/null | head -1)
[ -z "$RC" ] && { echo PROBE_NO_ROUTE_CACHE; exit 0; }
NEWER=$(find routes app config resources/views bootstrap/app.php \
          -name '*.php' -newer "$RC" -print 2>/dev/null | head -5)
[ composer.lock -nt "$RC" ] && NEWER="composer.lock
$NEWER"
if [ -n "$NEWER" ]; then
  echo PROBE_STALE
  echo "$NEWER"
else
  echo PROBE_FRESH
fi
FRESHPROBE
)
  case "$OUT" in
    PROBE_FRESH*) echo "Live caches fresh: route cache is newer than every source file." ;;
    PROBE_STALE*)
      echo "$OUT" | tail -n +2 | sed 's/^/  newer than route cache: /' >&2
      fail "live caches STALE after deploy — source files are newer than the route cache; re-run cache rebuild on live (config/route/view:cache + OPcache reset)" ;;
    PROBE_NO_ROUTE_CACHE*)
      fail "no route cache found on live after deploy — route:cache did not run/take" ;;
    *)
      fail "cache-freshness probe could not run over SSH (output: ${OUT:-empty}) — verify live caches manually" ;;
  esac
}

# Post-deploy live screen smoke (Task 714): login as QA company 35 and grep
# feature markers on key pages. BEST-EFFORT — warning-only, never blocks or
# fails the deploy (deploy already succeeded when this runs).
post_deploy_screen_smoke() {
  step "Post-deploy: live screen smoke test (QA 35 feature markers — warning-only)"
  if bash scripts/live-screen-smoke.sh; then
    echo "Live screen smoke: PASS."
  else
    echo "" >&2
    echo "!!! WARNING: live screen smoke test FAILED or could not run (see above). !!!" >&2
    echo "!!! Deploy itself succeeded — but a feature marker may be missing on live. !!!" >&2
    echo "!!! Re-run manually: bash scripts/live-screen-smoke.sh                     !!!" >&2
    echo "" >&2
  fi
  return 0
}

# ALL live-mutating work (pull/composer/migrate/caches/OPcache) runs as ONE
# remote payload under a single exclusive flock — the SAME lock the cPanel
# auto-deploy (scripts/cpanel-autodeploy.sh) holds for its whole critical
# section. Neither deploy can interleave between the other's stages.
DEPLOY_LOCK="/home/taxnestc/.taxnest-deploy.lock"

# remote_apply DO_PULL DO_COMPOSER DO_MIGRATE
# Executes the full mutation sequence on live inside one held lock AND inside
# the same pre-rendered HTTP-200 maintenance window the auto-deploy uses:
# down(200) BEFORE any mutation, up ONLY after caches + confirmed OPcache
# reset. On any failure the site STAYS on the 200 maintenance page (fail
# closed — identical lifecycle semantics to scripts/cpanel-autodeploy.sh).
# Exit codes: 90 cd, 91 pull, 92 composer, 93 migrate, 94 caches,
# 95 opcache-probe-write, 96 down failed (live untouched), 97 up failed,
# 98 opcache reset unconfirmed. Prints REMOTE_* markers.
remote_apply() {
  local DO_PULL=$1 DO_COMPOSER=$2 DO_MIGRATE=$3
  local RPROBE="opr-$(date +%s%N)$RANDOM$RANDOM.php"   # unguessable one-time probe name
  timeout 900 ssh "${SSH_OPTS[@]}" "$HOST" \
    "flock -w 300 $DEPLOY_LOCK bash -s -- $DO_PULL $DO_COMPOSER $DO_MIGRATE $RPROBE" <<'REMOTE'
set -u
DO_PULL=$1; DO_COMPOSER=$2; DO_MIGRATE=$3; RPROBE=$4
LIVE_DIR=/home/taxnestc/public_html
PHP84=/usr/local/bin/ea-php84
trap 'rm -f "$LIVE_DIR/public/$RPROBE"' EXIT INT TERM
cd "$LIVE_DIR" || exit 90
echo "REMOTE_LOCK_HELD"

# Maintenance window FIRST — live must never serve mid-mutation state.
# Bootstrap a minimal 200 page if the committed one isn't on live yet.
if [ ! -f resources/views/errors/deploying.blade.php ]; then
  mkdir -p resources/views/errors || exit 96
  printf '%s' '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="4"><title>Updating</title></head><body style="font-family:sans-serif;text-align:center;padding-top:20vh;background:#0A4D5C;color:#fff"><h1>System update in progress&hellip;</h1><p>This page refreshes automatically.</p></body></html>' \
    > resources/views/errors/deploying.blade.php || exit 96
fi
echo "REMOTE_STEP: artisan down (200 maintenance window)"
$PHP84 artisan down --render=errors::deploying --status=200 --refresh=4 2>&1 || exit 96
# From here on, any failure exits WITHOUT artisan up — site stays on the
# friendly 200 page; recover manually ('php artisan up' after fixing).

if [ "$DO_PULL" = 1 ]; then
  # Task 713: cPanel auto-deploys server-bump public/sw.js CACHE_VERSION when a
  # plain push carried no bump, leaving the live worktree with a CACHE_VERSION-
  # only local modification that would abort the pull. Restore it ONLY when the
  # diff touches nothing but CACHE_VERSION lines — this deploy ships its own
  # fresh CACHE_VERSION anyway. Any other local sw.js change still fails loudly.
  if ! git diff --quiet -- public/sw.js 2>/dev/null; then
    if git diff -- public/sw.js | grep '^[+-]' | grep -v '^+++' | grep -v '^---' | grep -qv 'CACHE_VERSION'; then
      echo "REMOTE_STEP: public/sw.js locally modified beyond CACHE_VERSION — NOT auto-restoring"
    else
      echo "REMOTE_STEP: restoring server-bumped public/sw.js (CACHE_VERSION-only diff) before pull"
      git checkout -- public/sw.js || exit 91
    fi
  fi
  echo "REMOTE_STEP: git pull origin main"
  git pull origin main 2>&1 || exit 91
  # Task 728: record the repo sw.js CACHE_VERSION this deploy ships, in the SAME
  # state file cpanel-autodeploy.sh uses. Without this, a deploy-live push (which
  # does NOT trigger the cPanel webhook autodeploy) leaves the state stale, and
  # the NEXT plain task-merge push would compare against the wrong version and
  # wrongly skip its server-side bump (devices would keep old caches).
  SHIPPED_SW=$(grep -m1 "^const CACHE_VERSION = '" public/sw.js | sed "s/^const CACHE_VERSION = '\([^']*\)'.*/\1/")
  if [ -n "$SHIPPED_SW" ]; then
    printf '%s\n' "$SHIPPED_SW" > /home/taxnestc/.taxnest-last-shipped-sw-version \
      && echo "REMOTE_STEP: recorded shipped SW version $SHIPPED_SW" \
      || echo "REMOTE_STEP: WARNING could not record shipped SW version (autodeploy falls back to live-file comparison)"
  else
    echo "REMOTE_STEP: WARNING could not read CACHE_VERSION from pulled public/sw.js — state file NOT updated"
  fi
fi
if [ "$DO_COMPOSER" = 1 ]; then
  echo "REMOTE_STEP: composer install"
  /usr/local/bin/php $(command -v composer || echo composer.phar) install --no-interaction --prefer-dist --no-dev 2>&1 || exit 92
fi
if [ "$DO_MIGRATE" = 1 ]; then
  echo "REMOTE_STEP: migrate --force"
  $PHP84 artisan migrate --force 2>&1 || exit 93
fi
echo "REMOTE_STEP: cache rebuild"
{ $PHP84 artisan config:clear && $PHP84 artisan cache:clear && $PHP84 artisan route:clear \
  && $PHP84 artisan view:clear && $PHP84 artisan config:cache && $PHP84 artisan route:cache \
  && $PHP84 artisan view:cache; } 2>&1 || exit 94
echo "REMOTE_STEP: web OPcache reset"
echo '<?php opcache_reset(); echo "OPCACHE_RESET_OK ".__DIR__; ?>' > "public/$RPROBE" || exit 95
OP_OK=0
for TRY in 1 2 3; do
  OP_OUT=$(curl -s --max-time 15 "https://taxnest.com.pk/$RPROBE" || true)
  case "$OP_OUT" in *OPCACHE_RESET_OK*) OP_OK=1; break ;; esac
  OP_OUT=$(curl -sk --max-time 15 -H "Host: taxnest.com.pk" "https://127.0.0.1/$RPROBE" || true)
  case "$OP_OUT" in *OPCACHE_RESET_OK*) OP_OK=1; break ;; esac
  sleep 3
done
rm -f "public/$RPROBE"
[ "$OP_OK" = 1 ] || exit 98
echo "$OP_OUT"
echo "REMOTE_STEP: artisan up"
$PHP84 artisan up 2>&1 || exit 97
echo "REMOTE_DONE"
exit 0
REMOTE
}

apply_fail_reason() {
  case "$1" in
    90) echo "cd to live dir failed" ;;
    91) echo "git pull failed on live — SITE LEFT IN MAINTENANCE (fix, then 'php artisan up' on live)" ;;
    92) echo "composer install failed on live — SITE LEFT IN MAINTENANCE" ;;
    93) echo "migrate --force failed on live — SITE LEFT IN MAINTENANCE" ;;
    94) echo "cache rebuild failed on live — SITE LEFT IN MAINTENANCE" ;;
    95) echo "could not write OPcache probe on live — SITE LEFT IN MAINTENANCE" ;;
    96) echo "could not open 200 maintenance window — ABORTED, live untouched" ;;
    97) echo "artisan up failed after successful release — run 'php artisan up' on live" ;;
    98) echo "web OPcache reset NOT confirmed — SITE LEFT IN MAINTENANCE (old opcode risk)" ;;
    124) echo "remote apply timed out (or lock held >300s by another deploy) — check live maintenance state" ;;
    *)  echo "remote apply failed with exit $1" ;;
  esac
}

# Live logging health: LOG_LEVEL must be 'warning' or lower (debug/info) so
# scheduler/guard Log::warning lines actually land in laravel.log
# (13 Aug 2026: LOG_LEVEL=error silently swallowed them all). Also flag a
# frozen laravel.log (no writes for hours = logging likely dead).
# Loud WARNING only — never blocks the deploy.
check_live_logging() {
  step "Verify: live logging health (LOG_LEVEL + laravel.log freshness)"
  local OUT
  OUT=$(run_ssh "cd $LIVE_DIR && grep -E '^LOG_LEVEL=' .env | head -1; echo \"MTIME=\$(stat -c %Y storage/logs/laravel.log 2>/dev/null || echo MISSING)\"; echo \"NOW=\$(date +%s)\"" 2>/dev/null)
  if [ -z "$OUT" ]; then
    echo "WARNING: could not read live .env/log over SSH — verify LOG_LEVEL manually." >&2
    return 0
  fi
  local LEVEL_LINE MTIME NOW
  LEVEL_LINE=$(echo "$OUT" | sed -n '1{/^LOG_LEVEL=/p}')
  MTIME=$(echo "$OUT" | sed -n 's/^MTIME=//p' | head -1)
  NOW=$(echo "$OUT" | sed -n 's/^NOW=//p' | head -1)

  local LEVEL="${LEVEL_LINE#LOG_LEVEL=}"
  LEVEL=$(echo "$LEVEL" | tr -d "\"' \r" | tr 'A-Z' 'a-z')
  case "$LEVEL" in
    debug|info|notice|warning)
      echo "LOG_LEVEL=$LEVEL — OK (warnings will be logged)."
      ;;
    "")
      # No LOG_LEVEL line: Laravel defaults to debug — fine, but note it.
      echo "LOG_LEVEL not set in live .env (defaults to debug) — OK."
      ;;
    *)
      echo "" >&2
      echo "!!! WARNING: live .env has LOG_LEVEL=$LEVEL — Log::warning lines are being SILENTLY DROPPED !!!" >&2
      echo "!!! Scheduler/guard warnings (Cloudflare guards, day-close, trial reminders) will vanish.      !!!" >&2
      echo "!!! Fix on live: set LOG_LEVEL=warning in $LIVE_DIR/.env then artisan config:cache.            !!!" >&2
      echo "" >&2
      ;;
  esac

  if [ "$MTIME" = "MISSING" ] || [ -z "$MTIME" ]; then
    echo "" >&2
    echo "!!! WARNING: live laravel.log is MISSING or unreadable — logging may be silently dead !!!" >&2
    echo "!!! Check LOG_CHANNEL/permissions on $LIVE_DIR/storage/logs.                          !!!" >&2
    echo "" >&2
  elif [ -n "$NOW" ] && [ "$MTIME" -eq "$MTIME" ] 2>/dev/null && [ "$NOW" -eq "$NOW" ] 2>/dev/null; then
    local AGE=$(( NOW - MTIME ))
    if [ "$AGE" -gt 21600 ]; then  # 6 hours
      echo "" >&2
      echo "!!! WARNING: live laravel.log last written $((AGE/3600))h ago — logging may be silently dead !!!" >&2
      echo "!!! Check LOG_LEVEL/LOG_CHANNEL in live .env and file permissions on storage/logs.           !!!" >&2
      echo "" >&2
    else
      echo "laravel.log last write: $((AGE/60)) min ago — logging alive."
    fi
  else
    echo "NOTE: could not stat live laravel.log mtime — skip freshness check." >&2
  fi
  return 0
}

# Task 999: Check that a What's New announcement (AppUpdate, audience pos/all,
# is_published=1) exists on live that was created AFTER the last deploy marker.
# Runs after SSH connectivity is confirmed. ALL error paths FAIL LOUDLY unless
# NO_ELAAN=1 — this is a hard gate, not a best-effort warning.
#
# First-use bootstrap: no marker on live → FAIL with instructions. Use
# --no-elaan on the very first deploy to seed the initial marker; after that
# every deploy requires an elaan.
check_elaan_freshness() {
  step "Preflight: Elaan freshness check (What's New announcement required per deploy)"
  if [ "$NO_ELAAN" = "1" ]; then
    echo "" >&2
    echo "!!! ELAAN SKIPPED (--no-elaan flag) !!!" >&2
    echo "!!! Acceptable only for: emergency hotfixes OR the very first deploy  !!!" >&2
    echo "!!! (first-use seeds the marker; future deploys enforce it).          !!!" >&2
    echo "!!! After this deploy run: bash scripts/elaan-insert.sh               !!!" >&2
    echo "!!!   --title '...' --point '...'                                     !!!" >&2
    echo "" >&2
    return 0
  fi

  local ELAAN_OUT ELAAN_RC
  ELAAN_OUT=$(timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" bash -s 2>&1 <<'EOFELAAN'
MARKER_FILE=/home/taxnestc/.taxnest-last-deploy-marker
if [ ! -f "$MARKER_FILE" ]; then
  echo "ELAAN_NO_MARKER"
  exit 0
fi
MARKER=$(head -1 "$MARKER_FILE" 2>/dev/null || echo "")
MARKER_TS=$(echo "$MARKER" | cut -d'|' -f1)
MARKER_COMMIT=$(echo "$MARKER" | cut -d'|' -f2)
# Validate that MARKER_TS is a plain integer epoch
if ! echo "$MARKER_TS" | grep -qE '^[0-9]+$'; then
  echo "ELAAN_MARKER_PARSE_ERROR content=$(cat /home/taxnestc/.taxnest-last-deploy-marker 2>/dev/null | head -1)"
  exit 0
fi
SINCE=$(date -d "@$MARKER_TS" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
  || date -r "$MARKER_TS" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
  || echo "")
if [ -z "$SINCE" ]; then
  echo "ELAAN_MARKER_PARSE_ERROR ts=$MARKER_TS"
  exit 0
fi
cd /home/taxnestc/public_html
DB_HOST=$(grep '^DB_HOST=' .env | head -1 | sed 's/^DB_HOST=//' | tr -d "\"'")
DB_USER=$(grep '^DB_USERNAME=' .env | head -1 | sed 's/^DB_USERNAME=//' | tr -d "\"'")
DB_PASS=$(grep '^DB_PASSWORD=' .env | head -1 | sed 's/^DB_PASSWORD=//' | tr -d "\"'")
DB_NAME=$(grep '^DB_DATABASE=' .env | head -1 | sed 's/^DB_DATABASE=//' | tr -d "\"'")
if [ -z "$DB_HOST" ] || [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
  echo "ELAAN_DB_CREDS_MISSING"
  exit 0
fi
COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN \
  -e "SELECT COUNT(*) FROM app_updates WHERE audience IN ('pos','all') AND is_published=1 AND created_at > '$SINCE'" 2>&1)
MYSQL_RC=$?
if [ $MYSQL_RC -ne 0 ] || ! echo "$COUNT" | grep -qE '^[0-9]+$'; then
  echo "ELAAN_DB_ERROR mysql_rc=$MYSQL_RC output=$(echo "$COUNT" | head -1)"
  exit 0
fi
echo "ELAAN_COUNT=$COUNT MARKER_TS=$MARKER_TS MARKER_COMMIT=$MARKER_COMMIT SINCE=$SINCE"
EOFELAAN
  )
  ELAAN_RC=$?

  # SSH itself failed (non-zero exit or empty output from the SSH command).
  if [ $ELAAN_RC -ne 0 ] || [ -z "$ELAAN_OUT" ]; then
    echo "" >&2
    echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
    echo "!!! ELAAN CHECK FAILED — SSH error or empty response (rc=$ELAAN_RC) !!!" >&2
    echo "!!! Cannot verify announcement without reaching live DB.             !!!" >&2
    echo "!!! Fix SSH connectivity, then re-run.                               !!!" >&2
    echo "!!! Emergency only: bash scripts/deploy-live.sh --no-elaan           !!!" >&2
    echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
    fail "elaan check: SSH failed (rc=$ELAAN_RC) — cannot verify announcement; fix connectivity or use --no-elaan"
  fi

  case "$ELAAN_OUT" in
    ELAAN_NO_MARKER*)
      echo "" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      echo "!!! ELAAN CHECK: NO MARKER FOUND (first deploy with this system)    !!!" >&2
      echo "!!! The deploy marker file does not exist on live yet.              !!!" >&2
      echo "!!!                                                                  !!!" >&2
      echo "!!! Bootstrap procedure (one-time):                                 !!!" >&2
      echo "!!!   1. bash scripts/deploy-live.sh --no-elaan  (seeds the marker) !!!" >&2
      echo "!!!   2. bash scripts/elaan-insert.sh --title '...' --point '...'   !!!" >&2
      echo "!!!   3. From now on: every deploy requires an elaan first.          !!!" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      fail "elaan check: no deploy marker on live — bootstrap with --no-elaan (see message above)"
      ;;
    ELAAN_MARKER_PARSE_ERROR*)
      echo "" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      echo "!!! ELAAN CHECK: MARKER FILE MALFORMED — $ELAAN_OUT" >&2
      echo "!!! Fix: SSH to live and run:                                        !!!" >&2
      echo "!!!   printf 'EPOCH|COMMIT\n' > /home/taxnestc/.taxnest-last-deploy-marker  !!!" >&2
      echo "!!! Or use --no-elaan to re-seed the marker with this deploy.       !!!" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      fail "elaan check: marker file malformed ($ELAAN_OUT) — fix or re-seed with --no-elaan"
      ;;
    ELAAN_DB_CREDS_MISSING*)
      echo "" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      echo "!!! ELAAN CHECK: DB CREDENTIALS MISSING from live .env              !!!" >&2
      echo "!!! Cannot query app_updates without DB access.                     !!!" >&2
      echo "!!! Fix live .env (DB_HOST/DB_USERNAME/DB_DATABASE), then re-run.  !!!" >&2
      echo "!!! Emergency only: bash scripts/deploy-live.sh --no-elaan          !!!" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      fail "elaan check: live DB credentials missing — cannot query app_updates; fix .env or use --no-elaan"
      ;;
    ELAAN_DB_ERROR*)
      echo "" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      echo "!!! ELAAN CHECK: DB QUERY FAILED — $ELAAN_OUT" >&2
      echo "!!! Cannot verify announcement without DB access.                   !!!" >&2
      echo "!!! Fix MySQL connectivity on live, then re-run.                   !!!" >&2
      echo "!!! Emergency only: bash scripts/deploy-live.sh --no-elaan          !!!" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      fail "elaan check: live DB query failed ($ELAAN_OUT) — fix MySQL or use --no-elaan"
      ;;
    *ELAAN_COUNT=*)
      local COUNT MARKER_C SINCE_DT
      COUNT=$(echo "$ELAAN_OUT" | grep -oE 'ELAAN_COUNT=[0-9]+' | cut -d= -f2)
      MARKER_C=$(echo "$ELAAN_OUT" | grep -oE 'MARKER_COMMIT=[^ ]+' | cut -d= -f2)
      SINCE_DT=$(echo "$ELAAN_OUT" | grep -oE 'SINCE=[^ ]+.*' | sed 's/SINCE=//' | sed 's/ MARKER.*//')
      echo "Last deploy marker: commit ${MARKER_C:-?} at ${SINCE_DT:-?}"
      if [ "${COUNT:-0}" -ge 1 ] 2>/dev/null; then
        echo "Elaan check: PASSED ($COUNT published update(s) since last deploy)."
        return 0
      else
        echo "" >&2
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
        echo "!!! ELAAN MISSING — deploy blocked                                 !!!" >&2
        echo "!!! No published What's New found since: ${SINCE_DT:-?}" >&2
        echo "!!!                                                                   !!!" >&2
        echo "!!! POS users will not know what changed. Create elaan first:      !!!" >&2
        echo "!!!   bash scripts/elaan-insert.sh --title '...' --point '...'     !!!" >&2
        echo "!!! Then re-run: bash scripts/deploy-live.sh                       !!!" >&2
        echo "!!!                                                                   !!!" >&2
        echo "!!! Emergency hotfix only: bash scripts/deploy-live.sh --no-elaan  !!!" >&2
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
        fail "elaan missing — create announcement (scripts/elaan-insert.sh) then redeploy, or use --no-elaan for emergency hotfixes"
      fi
      ;;
    *)
      echo "" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      echo "!!! ELAAN CHECK: UNEXPECTED REMOTE OUTPUT — cannot verify           !!!" >&2
      echo "!!! Output: $ELAAN_OUT" >&2
      echo "!!! Emergency only: bash scripts/deploy-live.sh --no-elaan          !!!" >&2
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
      fail "elaan check: unexpected remote output — cannot verify; use --no-elaan for emergencies: $ELAAN_OUT"
      ;;
  esac
}

# Task 999: Record a deploy marker on live after a successful deploy.
# Format: EPOCH|COMMIT_SHA  →  /home/taxnestc/.taxnest-last-deploy-marker
# The next deploy's check_elaan_freshness reads this to know the window to check.
# HARD FAIL if the write does not succeed — without a marker the next deploy's
# elaan check has no baseline and would fail on "no marker" immediately.
record_deploy_marker() {
  local COMMIT="$1"
  local NOW_TS
  NOW_TS=$(date +%s)
  step "Recording deploy marker (${NOW_TS}|${COMMIT})"
  # Task 1053: a successful manual deploy also clears the cron watcher's
  # failure marker (the failure state it flagged has been remediated).
  run_ssh "rm -f /home/taxnestc/.taxnest-autodeploy-FAILED; printf '%s\n' '${NOW_TS}|${COMMIT}' > /home/taxnestc/.taxnest-last-deploy-marker && echo MARKER_WRITTEN" 2>/dev/null \
    | grep -q "MARKER_WRITTEN" \
    || fail "deploy marker write FAILED on live — the deploy code is live but the marker was not recorded. Fix manually: SSH to live and run: printf '${NOW_TS}|${COMMIT}\n' > /home/taxnestc/.taxnest-last-deploy-marker — then re-verify the next elaan check will pass."
  echo "Deploy marker recorded: ${NOW_TS}|${COMMIT} → /home/taxnestc/.taxnest-last-deploy-marker"
}

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

step "Preflight: service-worker smoke check (sw.js fetch/message handlers)"
node scripts/sw-smoke-check.mjs \
  || fail "sw smoke check FAILED — public/sw.js handler broken (offline-first + logout cache hygiene would silently die on every client); fix before deploying"

step "Preflight: silent print-order check (receipt enqueues before KOT, incl. pending-PRA grace)"
node scripts/print-order-check.mjs \
  || fail "print-order check FAILED — silent fast path enqueues KOT before the receipt (invoice-first invariant); fix before deploying"

step "Preflight: print-confirm check (\"No\" = receipt-only skip, KOT alive; tables return dine-in-only)"
node scripts/print-confirm-check.mjs \
  || fail "print-confirm check FAILED — the Yes/No print dialog or the tables-first return regressed (Task 1025); fix before deploying"

step "Preflight: PWA refresh-button check (slow-install wait, no-update reload+toast, offline, timeout)"
node scripts/pwa-refresh-check.mjs \
  || fail "pwa-refresh check FAILED — the header update icon click contract regressed (Task 706); fix before deploying"

step "Preflight: multi-word product search check (cheese loaded half → Cheese Loaded Fries (Half); blade sync)"
node scripts/pos-search-rank-test.mjs \
  || fail "pos-search check FAILED — nameMatchRank/searchTokens regressed or universal↔waiter blade sync broken (Task 1045); fix before deploying"

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

check_elaan_freshness

if [ "$LIVE_HEAD_BEFORE" = "$LOCAL_HEAD" ]; then
  # cPanel auto-deploy (.cpanel.yml, triggered by pushes to origin main — e.g. task
  # merges) may have already pulled this HEAD. Racing auto-deploys can leave POISONED
  # compiled views (mtime newer than blade => Laravel never recompiles), and its
  # migrate step is '|| true' (silent failure). So: refresh everything anyway.
  echo "Live is already at workspace HEAD (cPanel auto-deploy likely ran on push)."
  # NOTE (Task 710): NO CACHE_VERSION bump on this refresh-only path — by design.
  # No commit gap means live already serves exactly this code, including whatever
  # CACHE_VERSION shipped with it (auto-bumped when it was originally deployed).
  # Bumping here would mint a new commit + trigger another deploy cycle for a
  # zero-code change. The auto-bump applies only to deploys with a live→local gap.
  echo "Refreshing migrate + caches + OPcache anyway — racing auto-deploys can leave stale/poisoned state."

  step "Live (refresh): migrate + caches + OPcache under ONE held deploy lock"
  REFRESH_OUT=$(remote_apply 0 0 1); APPLY_RC=$?
  echo "$REFRESH_OUT"
  [ $APPLY_RC -eq 0 ] || fail "$(apply_fail_reason $APPLY_RC)"
  echo "$REFRESH_OUT" | grep -q "OPCACHE_RESET_OK" || fail "web OPcache reset did not confirm"

  HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$LIVE_URL/")
  echo "GET $LIVE_URL/ -> $HTTP_CODE"
  [ "$HTTP_CODE" = "200" ] || fail "homepage returned $HTTP_CODE after refresh"

  verify_live_cache_fresh
  check_live_logging
  post_deploy_screen_smoke

  record_deploy_marker "$LOCAL_HEAD"

  echo "DEPLOY OK (refresh-only): live already at workspace HEAD; migrate + caches + OPcache refreshed."
  exit 0
fi

if ! git merge-base --is-ancestor "$LIVE_HEAD_BEFORE" "$LOCAL_HEAD" 2>/dev/null; then
  # Live commit unknown/diverged. The COMMON benign cause (seen 3x, last
  # 14 Aug 2026 / Task 702): platform task-merges commit to the workspace with
  # NEW SHAs while content-identical commits already sit on origin/main (live
  # auto-pulled them via .cpanel.yml) — lineage diverges, content does not.
  # Task 703 automates the manual reconcile pattern from
  # .agents/memory/cpanel-deployment.md via scripts/lib/deploy-reconcile.sh:
  # tree-identical origin/main => `git merge -s ours`; anything else fails LOUD
  # (origin unique content is NEVER auto-overwritten).
  step "Divergence detected — attempting safe auto-reconcile (Task 703)"
  . scripts/lib/deploy-reconcile.sh
  reconcile_fetch_origin_main \
    || fail "git fetch origin +main:refs/remotes/origin/main failed — cannot evaluate divergence"
  echo "origin/main: $(git rev-parse refs/remotes/origin/main)"
  echo "live HEAD:   $LIVE_HEAD_BEFORE"

  CLASS_LINE=$(reconcile_classify "$LOCAL_HEAD" "$LIVE_HEAD_BEFORE") \
    || fail "could not classify divergence (git error) — reconcile manually"
  CLASS=${CLASS_LINE%% *}
  case "$CLASS" in
    ANCESTOR)
      echo "After fetch, live HEAD is an ancestor of workspace HEAD — continuing normally."
      ;;
    RECONCILABLE)
      MATCH_COMMIT=${CLASS_LINE#RECONCILABLE }
      echo "origin/main tree is byte-identical to workspace commit $MATCH_COMMIT — origin has NO unique content."
      step "Auto-reconcile: git merge -s ours origin/main"
      reconcile_merge_ours "$MATCH_COMMIT" 2>&1 \
        || fail "git merge -s ours origin/main failed — reconcile manually"
      LOCAL_HEAD=$(git rev-parse HEAD)
      echo "reconciled; workspace HEAD now $LOCAL_HEAD"
      git merge-base --is-ancestor "$LIVE_HEAD_BEFORE" "$LOCAL_HEAD" 2>/dev/null \
        || fail "live HEAD still not an ancestor after reconcile — investigate manually"
      ;;
    ORIGIN_UNIQUE)
      echo "origin/main tree matches NO commit in workspace lineage —" >&2
      echo "origin carries UNIQUE content. NEVER auto-overwriting it." >&2
      fail "refusing to deploy over divergence — reconcile manually per .agents/memory/cpanel-deployment.md (inspect 'git diff HEAD origin/main' first)"
      ;;
    LIVE_DRIFT)
      echo "Live HEAD is neither origin/main nor an ancestor of it — live has REAL drift." >&2
      fail "refusing to deploy over divergence — investigate (compare live $LIVE_HEAD_BEFORE vs origin/main $(git rev-parse refs/remotes/origin/main) vs workspace $LOCAL_HEAD)"
      ;;
    *)
      fail "unexpected divergence classification '$CLASS_LINE' — reconcile manually"
      ;;
  esac
fi

# ---------------------------------------------- 0.5 SW cache-version auto-bump (Task 710)
# Every deploy must change public/sw.js CACHE_VERSION so devices purge old
# RUNTIME/STATIC caches and get the SW update badge. Idempotent: if the gap
# (live..workspace) already changes the CACHE_VERSION line (manual bump or a
# re-run after a previous auto-bump), do NOT bump again.
step "SW cache auto-bump: ensure CACHE_VERSION changes in this deploy"
if git diff "$LIVE_HEAD_BEFORE".."$LOCAL_HEAD" -- public/sw.js | grep -q '^+const CACHE_VERSION'; then
  echo "CACHE_VERSION already bumped in this deploy gap — skipping (idempotent)."
else
  # Unique per deploy even for back-to-back runs in the same minute:
  # seconds-precision timestamp + the short hash of the commit being deployed.
  NEW_SW_VERSION="taxnest-$(date +%Y%m%d-%H%M%S)-$(git rev-parse --short "$LOCAL_HEAD")"
  sed -i "s|^const CACHE_VERSION = '[^']*';.*$|const CACHE_VERSION = '$NEW_SW_VERSION'; // auto-bumped by deploy-live.sh — purges old caches + triggers SW update badge on every deploy (Task 710)|" public/sw.js
  grep -q "const CACHE_VERSION = '$NEW_SW_VERSION'" public/sw.js \
    || fail "sw.js CACHE_VERSION auto-bump failed (CACHE_VERSION line pattern not matched — did sw.js header change?)"
  node scripts/sw-smoke-check.mjs \
    || fail "sw smoke check FAILED after CACHE_VERSION auto-bump — sw.js broken, not deploying"
  git add public/sw.js
  # sed must have actually changed the file — an empty commit would silently
  # ship the OLD cache version (or abort the deploy). Verify a staged diff.
  git diff --cached --quiet -- public/sw.js \
    && fail "sw.js CACHE_VERSION bump produced no staged change (version collision?) — refusing to deploy without a fresh SW version"
  # Pathspec-scoped commit: ONLY public/sw.js is committed, even if other files
  # happen to be staged (they stay staged, uncommitted — the preflight warning
  # about undeployed tracked changes still applies to them).
  git commit -m "sw: auto-bump CACHE_VERSION to $NEW_SW_VERSION (deploy-live.sh)" -- public/sw.js >/dev/null 2>&1 \
    || fail "could not commit sw.js CACHE_VERSION bump"
  LOCAL_HEAD=$(git rev-parse HEAD)
  echo "CACHE_VERSION bumped to $NEW_SW_VERSION (workspace HEAD now $LOCAL_HEAD)"
fi

# Live worktree must be clean for a fast-forward pull (untracked junk is fine).
# Exception (Task 713): a public/sw.js diff that touches ONLY the CACHE_VERSION
# line is the cPanel auto-deploy's server-side bump — expected; remote_apply
# restores it just before the pull. Anything else in sw.js still counts dirty.
DIRTY=$(timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" bash -s <<'DIRTYCHECK' 2>/dev/null || true
cd /home/taxnestc/public_html || exit 0
git status --porcelain | grep -v '^??' | grep -v ' public/sw\.js$' | head -20
if ! git diff --quiet -- public/sw.js 2>/dev/null; then
  if git diff -- public/sw.js | grep '^[+-]' | grep -v '^+++' | grep -v '^---' | grep -qv 'CACHE_VERSION'; then
    echo ' M public/sw.js (changes beyond CACHE_VERSION auto-bump)'
  fi
fi
DIRTYCHECK
)
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
# Composer note: MUST run under /usr/local/bin/php (CloudLinux alt-php — the
# SAME runtime as the lsphp web handler, has gd/iconv). ea-php84 CLI lacks
# gd+iconv → mPDF/PhpSpreadsheet platform checks fail (discovered 4 Aug 2026).
step "Live: pull + composer($NEED_COMPOSER) + migrate($NEED_MIGRATE) + caches + OPcache under ONE held deploy lock"
APPLY_OUT=$(remote_apply 1 "$NEED_COMPOSER" "$NEED_MIGRATE"); APPLY_RC=$?
echo "$APPLY_OUT"
[ $APPLY_RC -eq 0 ] || fail "$(apply_fail_reason $APPLY_RC)"
echo "$APPLY_OUT" | grep -q "OPCACHE_RESET_OK" \
  || fail "web OPcache reset did not confirm (probe output above) — live may serve stale compiled code"

LIVE_HEAD_AFTER=$(run_ssh "cd $LIVE_DIR && git rev-parse HEAD" 2>/dev/null)
[ "$LIVE_HEAD_AFTER" = "$LOCAL_HEAD" ] \
  || fail "apply ran but live HEAD ($LIVE_HEAD_AFTER) != workspace HEAD ($LOCAL_HEAD) — pull may have silently aborted"
echo "live HEAD (after): $LIVE_HEAD_AFTER — matches workspace."

# ------------------------------------------------------------------ 3. Verify
step "Verify: homepage returns 200"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$LIVE_URL/")
echo "GET $LIVE_URL/ -> $HTTP_CODE"
[ "$HTTP_CODE" = "200" ] || fail "homepage returned $HTTP_CODE after deploy — investigate immediately (live log: storage/logs/laravel.log)"

step "Verify: no fresh production errors in live log (probe-aware triage)"
# Task 734: classify errors WITH stack frames — /tmp/*.php or "Command line
# code" frames = manual CLI probe, NOT app error / schema drift. Twice such
# probes were mistaken for drift and spawned phantom fix-tasks (Task 729).
# Fetch to a temp file FIRST and check the SSH exit status — never let a
# failed fetch masquerade as a clean "APP ERRORS: 0" triage. First line of
# the fetch = the LIVE server's date, so "today" matches the server TZ.
TRIAGE_TMP=$(mktemp)
if run_ssh "cd $LIVE_DIR && date +%Y-%m-%d && tail -c 2000000 storage/logs/laravel.log" \
     > "$TRIAGE_TMP" 2>/dev/null && [ -s "$TRIAGE_TMP" ]; then
  LIVE_DAY=$(head -1 "$TRIAGE_TMP")
  TRIAGE=$(tail -n +2 "$TRIAGE_TMP" | bash scripts/live-error-triage.sh "$LIVE_DAY") || true
  echo "NOTE: today's live error triage (APP ERRORS may still be pre-existing noise, e.g. 02:00 Compliance cron;"
  echo "      CLI PROBE entries are someone's /tmp probe script — NOT drift, do NOT open fix-tasks for them):"
  echo "$TRIAGE"
else
  echo "!!! WARNING: error triage could not run (SSH/log fetch failed or empty log) — check storage/logs/laravel.log manually. !!!" >&2
fi
rm -f "$TRIAGE_TMP"

verify_live_cache_fresh
check_live_logging
post_deploy_screen_smoke

record_deploy_marker "$LOCAL_HEAD"

echo ""
echo "---------------------------------------------------------------"
echo "DEPLOY OK: live HEAD == workspace HEAD ($LOCAL_HEAD)"
echo "           pull + caches + web OPcache reset done; homepage 200."
echo "           Next deploy requires a What's New elaan — create one:"
echo "             bash scripts/elaan-insert.sh --title '...' --point '...'"
echo "---------------------------------------------------------------"
exit 0
