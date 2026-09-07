#!/bin/bash
# Shared remote apply for TaxNest live (Islamabad VPS).
#
# Sourced by:
#   - scripts/deploy-live.sh  (manual / Replit path — after local preflights + push)
#   - scripts/ci-deploy-production.sh  (GitHub Actions — already-merged main SHA only)
#
# Expects scripts/lib/live-host.sh to have been sourced first, and:
#   HOST, SSH_OPTS[], DEPLOY_LOCK, ALLOW_SETTINGS (optional)
#
# remote_apply DO_PULL DO_COMPOSER DO_MIGRATE [TARGET_SHA]
#   When DO_PULL=1, TARGET_SHA is required (40-char hex preferred). Live checks
#   out that exact commit — never "whatever origin/main tip is right now" alone,
#   and never pushes from this helper.
#
# Exit codes: 90 cd, 91 fetch/checkout, 92 composer, 93 migrate, 94 caches,
# 95 ownership/SELinux, 96 down failed (live untouched), 97 up failed,
# 98 opcache reset unconfirmed, 99 queue worker did not come back.

# remote_apply DO_PULL DO_COMPOSER DO_MIGRATE [TARGET_SHA]
remote_apply() {
  local DO_PULL=$1 DO_COMPOSER=$2 DO_MIGRATE=$3
  local TARGET_SHA=${4:-}

  if [ "$DO_PULL" = 1 ]; then
    case "$TARGET_SHA" in
      ""|*[!0-9a-fA-F]*)
        echo "REMOTE_APPLY_BAD_TARGET_SHA" >&2
        return 91
        ;;
    esac
    if [ "${#TARGET_SHA}" -lt 7 ] || [ "${#TARGET_SHA}" -gt 40 ]; then
      echo "REMOTE_APPLY_BAD_TARGET_SHA_LEN" >&2
      return 91
    fi
  fi

  # ALLOW_SETTINGS / TARGET_SHA are single-quoted into the remote argv so they
  # cannot expand on the SSH command line. Only safe characters reach here.
  timeout 900 ssh "${SSH_OPTS[@]}" "$HOST" \
    "LIVE_DIR='$LIVE_DIR' LIVE_PHP='$LIVE_PHP' LIVE_WEB_GROUP='$LIVE_WEB_GROUP' \
     LIVE_FPM_SERVICE='$LIVE_FPM_SERVICE' LIVE_QUEUE_SERVICE='$LIVE_QUEUE_SERVICE' \
     LIVE_SETTINGS_BASE='$LIVE_SETTINGS_BASE' LIVE_SSH_USER='$LIVE_SSH_USER' \
     flock -w 300 $DEPLOY_LOCK bash -s -- $DO_PULL $DO_COMPOSER $DO_MIGRATE '${ALLOW_SETTINGS:-}' '${TARGET_SHA}'" <<'REMOTE'
set -u
DO_PULL=$1; DO_COMPOSER=$2; DO_MIGRATE=$3; ALLOW_SETTINGS=${4:-}; TARGET_SHA=${5:-}
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
  echo "REMOTE_STEP: git fetch + checkout exact $TARGET_SHA"
  case "$TARGET_SHA" in
    ""|*[!0-9a-fA-F]*) exit 91 ;;
  esac
  # core.fileMode off: cutover left executable-bit noise on tracked scripts.
  git config core.fileMode false >/dev/null 2>&1 || true
  git fetch origin --prune 2>&1 || exit 91
  if ! git rev-parse --verify "${TARGET_SHA}^{commit}" >/dev/null 2>&1; then
    git fetch origin "$TARGET_SHA" 2>&1 || exit 91
  fi
  git rev-parse --verify "${TARGET_SHA}^{commit}" >/dev/null 2>&1 || exit 91
  # Stay on main at the exact commit (not a floating detached HEAD).
  git checkout -B main "$TARGET_SHA" 2>&1 || exit 91
  CURRENT=$(git rev-parse HEAD)
  EXPECTED=$(git rev-parse "${TARGET_SHA}^{commit}")
  [ "$CURRENT" = "$EXPECTED" ] || exit 91
  echo "REMOTE_HEAD=$CURRENT"
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
# Reload PHP-FPM and PROVE the master re-executed (fresh workers + journal).
echo "REMOTE_STEP: web OPcache reset (PHP-FPM graceful reload)"
fpm_worker_pids() { pgrep -f 'php-fpm: pool' 2>/dev/null | sort | tr '\n' ' '; }
PIDS_BEFORE=$(fpm_worker_pids)
RELOAD_TS=$(date '+%Y-%m-%d %H:%M:%S')
sleep 1
sudo -n systemctl reload "$LIVE_FPM_SERVICE" 2>&1 || exit 98

OP_OK=0
for TRY in 1 2 3 4 5; do
  sleep 2
  PIDS_AFTER=$(fpm_worker_pids)
  FRESH=0
  for p in $PIDS_AFTER; do
    case " $PIDS_BEFORE " in *" $p "*) ;; *) FRESH=1 ;; esac
  done
  JOURNAL=$(sudo -n journalctl -u "$LIVE_FPM_SERVICE" --since "$RELOAD_TS" --no-pager 2>/dev/null \
            | grep -ciE 'inherited socket|ready to handle connections' || true)
  if [ "$FRESH" = 1 ] && [ "${JOURNAL:-0}" -gt 0 ]; then OP_OK=1; break; fi
done
[ "$OP_OK" = 1 ] || exit 98
echo "OPCACHE_RESET_OK php-fpm master reloaded (before:[$PIDS_BEFORE] after:[$PIDS_AFTER])"

# --- queue worker ------------------------------------------------------------
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
    91) echo "git fetch/checkout of target SHA failed on live — SITE LEFT IN MAINTENANCE (fix, then 'php artisan up' on live)" ;;
    92) echo "composer install failed on live — SITE LEFT IN MAINTENANCE" ;;
    93) echo "migrate --force failed on live (or Agent realtime gateway health failed) — SITE LEFT IN MAINTENANCE" ;;
    94) echo "cache rebuild failed on live — SITE LEFT IN MAINTENANCE" ;;
    95) echo "could not repair storage ownership / SELinux contexts — SITE LEFT IN MAINTENANCE (bringing it up would break logging and cache writes)" ;;
    96) echo "could not open 200 maintenance window — ABORTED, live untouched" ;;
    97) echo "artisan up failed after successful release — run 'php artisan up' on live" ;;
    98) echo "web OPcache reset NOT confirmed (php-fpm reload) — SITE LEFT IN MAINTENANCE (old opcode risk)" ;;
    99) echo "the site is live on the new code but the QUEUE WORKER did not come back — background jobs (bills, ZIPs, FBR filings) are NOT running. Fix now: sudo systemctl restart $LIVE_QUEUE_SERVICE" ;;
    124) echo "remote apply timed out (or lock held >300s by another deploy) — check live maintenance state" ;;
    *)  echo "remote apply failed with exit $1" ;;
  esac
}
