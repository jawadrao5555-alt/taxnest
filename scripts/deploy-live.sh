#!/bin/bash
# One-command live deploy for TaxNest — Islamabad VPS origin.
# Usage: bash scripts/deploy-live.sh [--no-elaan] [--allow-settings=a,b]
#
# Does the FULL runbook from .agents/memory/islamabad-vps-stack.md:
#   1. Push workspace HEAD to GitHub main (git push origin HEAD:main)
#   2. Over SSH on the live server ($LIVE_DIR):
#        git pull origin main
#        php artisan migrate --force        (only when the gap includes migrations)
#        config/route/view cache rebuild
#        WEB OPcache reset by reloading PHP-FPM (proved by fresh worker PIDs)
#        queue worker restarted so it stops running the OLD code
#   3. Verify: live HEAD == workspace HEAD, and homepage curls 200.
#
# The host, key, paths and PHP binary all come from scripts/lib/live-host.sh —
# never hardcode them here again.
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
#   After each successful deploy, a marker is written to $LIVE_DEPLOY_MARKER
#   (format: EPOCH|COMMIT_SHA). This lets the next deploy know exactly what
#   window to check.

set -uo pipefail

# ---------------------------------------------------------------- Parse flags
NO_ELAAN=0
# Settings-regression guard (owner rule, Sep 2026): a deploy must change ONLY
# the feature it ships. Columns this deploy INTENDS to change are declared here
# — everything else that moves fails the deploy loudly.
#   bash scripts/deploy-live.sh --allow-settings=pos_theme,kot_align_center
ALLOW_SETTINGS=""
for _arg in "$@"; do
  case "$_arg" in
    --no-elaan) NO_ELAAN=1 ;;
    --allow-settings=*) ALLOW_SETTINGS="${_arg#--allow-settings=}" ;;
  esac
done
# Only column-name characters ever reach the remote shell.
case "$ALLOW_SETTINGS" in
  *[!a-zA-Z0-9_,]*) echo "Invalid --allow-settings (letters, digits, _ and , only)" >&2; exit 1 ;;
esac
cd "$(dirname "$0")/.."

# The live origin — host, key, app path, PHP binary, URLs, state files.
# shellcheck source=scripts/lib/live-host.sh
source "$(dirname "$0")/lib/live-host.sh"

fail() { echo ""; echo "DEPLOY FAILED: $*" >&2; exit 1; }
step() { echo ""; echo "==> $*"; }

live_host_assert_not_retired || exit 1

KEY="$LIVE_SSH_KEY"
HOST="$LIVE_SSH_HOST"
SSH_OPTS=("${LIVE_SSH_OPTS[@]}")

run_ssh() { timeout 120 ssh "${SSH_OPTS[@]}" "$HOST" "$@"; }

# Post-deploy cache-freshness tripwire (Task 1053): after a deploy, NO source
# file that feeds Laravel's caches may be newer than the built route cache on
# live. If any is, the cache rebuild silently didn't take — the exact state
# that 500'd every admin page on Aug 17 2026 ("Route [...] not defined").
# HARD FAIL: a deploy that leaves stale caches is not a successful deploy.
verify_live_cache_fresh() {
  step "Verify: live caches fresh (no source file newer than route cache — Task 1053)"
  local OUT
  OUT=$(run_ssh "LIVE_DIR='$LIVE_DIR' bash -s" <<'FRESHPROBE' 2>/dev/null
cd "$LIVE_DIR" || { echo PROBE_CD_FAIL; exit 0; }
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

# ALL live-mutating work (pull/composer/migrate/caches/OPcache/queue) runs as
# ONE remote payload under a single exclusive flock, so two people deploying
# at once cannot interleave their stages.
DEPLOY_LOCK="$LIVE_DEPLOY_LOCK"

# remote_apply DO_PULL DO_COMPOSER DO_MIGRATE
# Executes the full mutation sequence on live inside one held lock AND inside
# a pre-rendered HTTP-200 maintenance window: down(200) BEFORE any mutation,
# up ONLY after caches + a CONFIRMED OPcache reset. On any failure the site
# STAYS on the 200 maintenance page (fail closed).
# Exit codes: 90 cd, 91 pull, 92 composer, 93 migrate, 94 caches,
# 96 down failed (live untouched), 97 up failed, 98 opcache reset unconfirmed,
# 99 queue worker did not come back. Prints REMOTE_* markers.
remote_apply() {
  local DO_PULL=$1 DO_COMPOSER=$2 DO_MIGRATE=$3
  timeout 900 ssh "${SSH_OPTS[@]}" "$HOST" \
    "LIVE_DIR='$LIVE_DIR' LIVE_PHP='$LIVE_PHP' LIVE_WEB_GROUP='$LIVE_WEB_GROUP' \
     LIVE_FPM_SERVICE='$LIVE_FPM_SERVICE' LIVE_QUEUE_SERVICE='$LIVE_QUEUE_SERVICE' \
     LIVE_SETTINGS_BASE='$LIVE_SETTINGS_BASE' LIVE_SSH_USER='$LIVE_SSH_USER' \
     flock -w 300 $DEPLOY_LOCK bash -s -- $DO_PULL $DO_COMPOSER $DO_MIGRATE '${ALLOW_SETTINGS:-}'" <<'REMOTE'
set -u
DO_PULL=$1; DO_COMPOSER=$2; DO_MIGRATE=$3; ALLOW_SETTINGS=${4:-}
PHP="$LIVE_PHP"
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
$PHP artisan down --render=errors::deploying --status=200 --refresh=4 2>&1 || exit 96
# From here on, any failure exits WITHOUT artisan up — site stays on the
# friendly 200 page; recover manually ('php artisan up' after fixing).

if [ "$DO_PULL" = 1 ]; then
  echo "REMOTE_STEP: git pull origin main"
  git pull origin main 2>&1 || exit 91
fi
if [ "$DO_COMPOSER" = 1 ]; then
  echo "REMOTE_STEP: composer install"
  composer install --no-interaction --prefer-dist --no-dev 2>&1 || exit 92
fi
# Settings baseline BEFORE any migration touches a column. Taken on the code
# that is already live, so it reflects what the shops actually had. Never fatal:
# a host that cannot snapshot must still be able to deploy a hotfix.
SETTINGS_BASE="$LIVE_SETTINGS_BASE"
SETTINGS_BASE_OK=0
if $PHP artisan pos:settings-snapshot --out="$SETTINGS_BASE" >/dev/null 2>&1; then
  SETTINGS_BASE_OK=1
  echo "REMOTE_STEP: settings baseline captured"
else
  # Not a remote exit code (the site is mid-maintenance and the release itself
  # is fine), but the deploy is NOT clean: nothing is watching the settings this
  # time. The local script turns this marker into a loud failure.
  echo "REMOTE_SETTINGS_BASELINE_FAILED"
  echo "REMOTE_STEP: WARNING could not capture the settings baseline — regression guard is DISARMED for this deploy"
fi

if [ "$DO_MIGRATE" = 1 ]; then
  echo "REMOTE_STEP: migrate --force"
  $PHP artisan migrate --force 2>&1 || exit 93
fi
echo "REMOTE_STEP: cache rebuild"
{ $PHP artisan config:clear && $PHP artisan cache:clear && $PHP artisan route:clear \
  && $PHP artisan view:clear && $PHP artisan config:cache && $PHP artisan route:cache \
  && $PHP artisan view:cache; } 2>&1 || exit 94

# The web server writes into these as apache; artisan just rewrote them as us.
# Left unfixed, the next request that needs to write a cache or a log hits a
# permission error, and SELinux refuses the read outright. These MUST NOT be
# best-effort: a silent failure here brings the site back UP with logging and
# cache writes broken, which is worse than staying in maintenance.
echo "REMOTE_STEP: repair ownership + SELinux contexts"
sudo -n chown -R "$LIVE_SSH_USER:$LIVE_WEB_GROUP" storage bootstrap/cache 2>&1 || exit 95
sudo -n restorecon -R storage bootstrap/cache public 2>&1 || exit 95

# --- web OPcache reset -------------------------------------------------------
# The old host had no privileged access, so it dropped a one-time PHP file into
# public/ and curled it. That put an executable script in the web root, which is
# exactly how a throwaway debug endpoint outlives its incident. Here we have
# sudo, so we reload PHP-FPM instead: the master gracefully respawns every
# worker, and a fresh worker cannot be holding old opcode.
#
# Reloading is not proof, so we PROVE it: the worker PIDs must all be new. An
# unproven OPcache reset is how live silently serves last week's code.
echo "REMOTE_STEP: web OPcache reset (PHP-FPM graceful reload)"
fpm_worker_pids() { pgrep -f 'php-fpm: pool' 2>/dev/null | sort | tr '\n' ' '; }
PIDS_BEFORE=$(fpm_worker_pids)
RELOAD_TS=$(date '+%Y-%m-%d %H:%M:%S')
sleep 1
sudo -n systemctl reload "$LIVE_FPM_SERVICE" 2>&1 || exit 98

# What must be true is that the MASTER re-executed: that is what rebuilds the
# shared opcode memory. Do NOT demand that every old worker is gone — a
# graceful reload lets a worker finish its current request first, and our own
# long-polling endpoints can hold one for a while. Demanding zero survivors
# would fail a perfectly good deploy and strand the shops in maintenance.
OP_OK=0
for TRY in 1 2 3 4 5; do
  sleep 2
  PIDS_AFTER=$(fpm_worker_pids)
  # Evidence A: fresh workers exist that did not exist before the reload.
  FRESH=0
  for p in $PIDS_AFTER; do
    case " $PIDS_BEFORE " in *" $p "*) ;; *) FRESH=1 ;; esac
  done
  # Evidence B: php-fpm's OWN log says the master came back up. Deliberately
  # NOT systemd's "Reloaded ..." line — systemd prints that whenever it
  # delivered the signal, whether or not php-fpm did anything with it. These
  # two phrases are emitted by the re-executed master itself.
  JOURNAL=$(sudo -n journalctl -u "$LIVE_FPM_SERVICE" --since "$RELOAD_TS" --no-pager 2>/dev/null \
            | grep -ciE 'inherited socket|ready to handle connections' || true)
  if [ "$FRESH" = 1 ] && [ "${JOURNAL:-0}" -gt 0 ]; then OP_OK=1; break; fi
done
[ "$OP_OK" = 1 ] || exit 98
echo "OPCACHE_RESET_OK php-fpm master reloaded (before:[$PIDS_BEFORE] after:[$PIDS_AFTER])"

# --- queue worker ------------------------------------------------------------
# On the old host the queue ran from a cron line, so every minute spawned a
# fresh process that picked up new code by itself. Here it is a long-lived
# systemd unit: skip this and the worker keeps executing the code it booted
# with, forever. Bills, ZIP exports and regulator filings would silently run
# last release while the website runs this one.
#
# Deliberately BEFORE 'artisan up'. If the worker cannot come back we want the
# shops on the maintenance page, not on a site that takes bills whose
# background half is dead.
echo "REMOTE_STEP: restart queue worker ($LIVE_QUEUE_SERVICE)"
sudo -n systemctl restart "$LIVE_QUEUE_SERVICE" 2>&1 || exit 99
sleep 3
systemctl is-active --quiet "$LIVE_QUEUE_SERVICE" || exit 99
echo "REMOTE_STEP: queue worker active on the new code"

# Realtime Agent wake gateway is optional on older releases/hosts. Once
# installed, every deploy must restart it so it cannot keep serving the
# previous checkout's JavaScript while Laravel has moved forward.
if sudo -n systemctl cat taxnest-agent-realtime.service >/dev/null 2>&1; then
  echo "REMOTE_STEP: restart Agent realtime gateway"
  sudo -n systemctl restart taxnest-agent-realtime.service 2>&1 || exit 93
  sleep 2
  systemctl is-active --quiet taxnest-agent-realtime.service || exit 93
  curl --fail --silent http://127.0.0.1:6101/health >/dev/null || exit 93
  echo "REMOTE_STEP: Agent realtime gateway active on the new code"
fi

echo "REMOTE_STEP: artisan up"
$PHP artisan up 2>&1 || exit 97

# Settings-regression check, AFTER the site is back up. Deliberately not a
# remote exit code: the release already shipped, and dropping the shops back
# into maintenance would punish them for our bug. Instead it prints a marker
# the local script turns into a loud DEPLOY FAILED, so a human must look.
if [ "$SETTINGS_BASE_OK" = 1 ]; then
  echo "REMOTE_STEP: settings regression check"
  if [ -n "$ALLOW_SETTINGS" ]; then
    SET_OUT=$($PHP artisan pos:settings-snapshot --compare="$SETTINGS_BASE" --allow="$ALLOW_SETTINGS" 2>&1)
  else
    SET_OUT=$($PHP artisan pos:settings-snapshot --compare="$SETTINGS_BASE" 2>&1)
  fi
  SET_RC=$?
  echo "$SET_OUT"
  [ "$SET_RC" = 0 ] || echo "REMOTE_SETTINGS_REGRESSION"
  rm -f "$SETTINGS_BASE"
fi

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
    93) echo "Agent realtime gateway did not restart or pass health — SITE LEFT IN MAINTENANCE (old/new wake path mismatch risk)" ;;
    95) echo "could not repair storage ownership / SELinux contexts — SITE LEFT IN MAINTENANCE (bringing it up would break logging and cache writes)" ;;
    96) echo "could not open 200 maintenance window — ABORTED, live untouched" ;;
    97) echo "artisan up failed after successful release — run 'php artisan up' on live" ;;
    98) echo "web OPcache reset NOT confirmed (php-fpm reload) — SITE LEFT IN MAINTENANCE (old opcode risk)" ;;
    99) echo "the site is live on the new code but the QUEUE WORKER did not come back — background jobs (bills, ZIPs, FBR filings) are NOT running. Fix now: sudo systemctl restart $LIVE_QUEUE_SERVICE" ;;
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
  ELAAN_OUT=$(timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" \
    "LIVE_DIR='$LIVE_DIR' MARKER_FILE='$LIVE_DEPLOY_MARKER' bash -s" 2>&1 <<'EOFELAAN'
if [ ! -f "$MARKER_FILE" ]; then
  echo "ELAAN_NO_MARKER"
  exit 0
fi
MARKER=$(head -1 "$MARKER_FILE" 2>/dev/null || echo "")
MARKER_TS=$(echo "$MARKER" | cut -d'|' -f1)
MARKER_COMMIT=$(echo "$MARKER" | cut -d'|' -f2)
# Validate that MARKER_TS is a plain integer epoch
if ! echo "$MARKER_TS" | grep -qE '^[0-9]+$'; then
  echo "ELAAN_MARKER_PARSE_ERROR content=$(head -1 "$MARKER_FILE" 2>/dev/null)"
  exit 0
fi
SINCE=$(date -d "@$MARKER_TS" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
  || date -r "$MARKER_TS" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
  || echo "")
if [ -z "$SINCE" ]; then
  echo "ELAAN_MARKER_PARSE_ERROR ts=$MARKER_TS"
  exit 0
fi
cd "$LIVE_DIR"
DB_HOST=$(grep '^DB_HOST=' .env | head -1 | sed 's/^DB_HOST=//' | tr -d "\"'")
[ -z "$DB_HOST" ] && DB_HOST=127.0.0.1   # VPS .env omits it; MariaDB is local
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
      echo "!!!   printf 'EPOCH|COMMIT\n' > $LIVE_DEPLOY_MARKER  !!!" >&2
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
# Format: EPOCH|COMMIT_SHA  →  $LIVE_DEPLOY_MARKER
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
  run_ssh "printf '%s\n' '${NOW_TS}|${COMMIT}' > '$LIVE_DEPLOY_MARKER' && echo MARKER_WRITTEN" 2>/dev/null \
    | grep -q "MARKER_WRITTEN" \
    || fail "deploy marker write FAILED on live — the deploy code is live but the marker was not recorded. Fix manually: SSH to live and run: printf '${NOW_TS}|${COMMIT}\n' > $LIVE_DEPLOY_MARKER — then re-verify the next elaan check will pass."
  echo "Deploy marker recorded: ${NOW_TS}|${COMMIT} → $LIVE_DEPLOY_MARKER"
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

step "Preflight: Blade script-escape check (escaped JSON inside inline <script>)"
bash scripts/blade-script-escape-check.sh \
  || fail "escaped JSON inside a <script> block — that page's JavaScript dies with a syntax error (buttons dead, x-cloak stuck hidden). Switch the flagged {{ json_encode(...) }} to @json(...) and re-run."

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

step "Preflight: Summary X/Z thermal print check (Roman Urdu + Urdu at 80mm)"
node scripts/summary-print-check.mjs
SP_RC=$?
if [ $SP_RC -eq 2 ]; then
  fail "Summary X/Z thermal print check could not run (POS server/browser/fixture unavailable) — start the Laravel Server or set SUMMARY_X_URL/SUMMARY_Z_URL"
elif [ $SP_RC -ne 0 ]; then
  fail "Summary X/Z thermal print check FAILED — compact report overflow/state header/A4 filename regressed; fix before deploying"
fi

step "Preflight: final-bill KOT safety net (unseen lines reach the kitchen, no duplicate slips)"
node scripts/kot-on-final-check.mjs \
  || fail "kot-on-final check FAILED — a finalized bill either leaves the kitchen with no ticket (owner's Table 02 bug, Task 1356) or prints a duplicate slip; fix before deploying"

# The .mjs check above only covers the CLIENT half (the sale screen's print
# chain). These two suites cover the SERVER half and — crucially — the wire
# between them: the kot_pending / kot_order_id keys in the payment endpoints'
# JSON. Without this step a refactor could drop or rename those keys, pass
# every other check here, and silently stop the kitchen from getting orders
# (Task 1369). sqlite :memory:, no MySQL/dev-server dependency.
step "Preflight: final-bill KOT server contract (pay responses still carry kot_pending/kot_order_id)"
env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
  APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' CACHE_STORE=array \
  php vendor/bin/phpunit \
    tests/Feature/PosKotOnFinalSafetyNetTest.php \
    tests/Feature/PosKotOnFinalPayResponseTest.php \
  || fail "kot-on-final SERVER check FAILED — the payment endpoints no longer report an owed kitchen ticket (kot_pending/kot_order_id) or the KotPrintService signal regressed; fix before deploying"

step "Preflight: PWA refresh-button check (slow-install wait, no-update reload+toast, offline, timeout)"
node scripts/pwa-refresh-check.mjs \
  || fail "pwa-refresh check FAILED — the header update icon click contract regressed (Task 706); fix before deploying"

step "Preflight: multi-word product search check (cheese loaded half → Cheese Loaded Fries (Half); blade sync)"
node scripts/pos-search-rank-test.mjs \
  || fail "pos-search check FAILED — nameMatchRank/searchTokens regressed or universal↔waiter blade sync broken (Task 1045); fix before deploying"

step "Preflight: receipt-settings preview check (preview follows the open tab; Menu-QR gates the LOCAL bill only)"
node scripts/receipt-preview-tab-check.mjs \
  || fail "receipt-preview check FAILED — the Receipt Settings live preview no longer follows the open tab, or the Menu-QR toggle leaked onto the PRA fiscal QR (Task 1377); fix before deploying"

step "Preflight: POS plan-gate matrix check (Starter/Business/Pro/Unlimited)"
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

# Every other POS check above is HTML/JS-level. This one drives a REAL browser
# and actually CLICKS "Call back" on the sale screen, because that button lives
# in universal.blade.php — a file edited constantly for unrelated work — and a
# renamed method or a broken x-data leaves the whole PHP suite green while the
# cashier gets a button that does nothing mid-rush (Task 1396). It also proves
# the button never swallows the keyboard (guided Enter + plain-letter shortcuts)
# and that a shop with no phone paired gets the amber number card, not an error.
step "Preflight: Caller ID call-back check (real click: dials, attaches customer, amber fallback, keyboard intact)"
if [ "${SKIP_CALLER_DIAL_CHECK:-0}" = "1" ]; then
  echo "SKIPPED (SKIP_CALLER_DIAL_CHECK=1) — only skip for emergency hotfixes." >&2
else
  node scripts/pos-caller-dial-check.mjs
  CD_RC=$?
  if [ $CD_RC -eq 2 ]; then
    fail "call-back check could not run (dev server/MySQL/chromium missing?) — start the Laravel Server + MySQL Staging workflows, or SKIP_CALLER_DIAL_CHECK=1 to bypass"
  elif [ $CD_RC -ne 0 ]; then
    fail "call-back check FAILED — the sale screen's Call back button regressed; fix before deploying"
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
  if echo "$REFRESH_OUT" | grep -q "REMOTE_SETTINGS_REGRESSION"; then
    fail "the refresh CHANGED existing shops' saved settings (table above) — investigate before shipping anything else."
  fi

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
#
# core.fileMode is forced off first. The cutover rsynced the app from the old
# host and flipped the executable bit on ~39 tracked scripts, so git reports
# them as modified forever. They would abort a pull the day an incoming commit
# happens to touch one of them — a deploy failing on a permission bit that
# means nothing on this box. Setting it here (not once by hand) keeps a
# rebuilt server from quietly reintroducing the trap.
timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" \
  "cd '$LIVE_DIR' && git config core.fileMode false" >/dev/null 2>&1 || true

DIRTY=$(timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" "LIVE_DIR='$LIVE_DIR' bash -s" <<'DIRTYCHECK' 2>/dev/null || true
cd "$LIVE_DIR" || exit 0
git status --porcelain | grep -v '^??' | head -20
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
# One PHP on this box (/usr/bin/php 8.4) serves both CLI and FPM, so the old
# host's split — a separate CLI binary for composer because ea-php84 lacked
# gd/iconv — no longer applies.
step "Live: pull + composer($NEED_COMPOSER) + migrate($NEED_MIGRATE) + caches + OPcache + queue under ONE held deploy lock"
APPLY_OUT=$(remote_apply 1 "$NEED_COMPOSER" "$NEED_MIGRATE"); APPLY_RC=$?
echo "$APPLY_OUT"
[ $APPLY_RC -eq 0 ] || fail "$(apply_fail_reason $APPLY_RC)"
echo "$APPLY_OUT" | grep -q "OPCACHE_RESET_OK" \
  || fail "web OPcache reset did not confirm (probe output above) — live may serve stale compiled code"

# Owner rule: this deploy may change the feature it ships and NOTHING else.
# The site is already back up by now, so this fails the DEPLOY, not the shops:
# a human must read the table above and decide repair vs. --allow-settings.
if echo "$APPLY_OUT" | grep -q "REMOTE_SETTINGS_REGRESSION"; then
  fail "this deploy CHANGED existing shops' saved settings (table above). Repair the migration/backfill, or re-run with --allow-settings=<columns> if every listed change was intended."
fi
if echo "$APPLY_OUT" | grep -q "REMOTE_SETTINGS_BASELINE_FAILED"; then
  fail "the settings baseline could not be captured, so NOTHING checked whether this deploy reset shops' settings. Fix 'php artisan pos:settings-snapshot' on live and verify the shops by hand before trusting this release."
fi

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
