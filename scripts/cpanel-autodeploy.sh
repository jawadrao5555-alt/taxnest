#!/bin/bash
# cPanel AUTO-DEPLOY runner — invoked by .cpanel.yml on every push to origin main
# (including platform task merges). Runs ON THE cPANEL SERVER with cwd = the
# cPanel repository clone (the directory .cpanel.yml executes from).
#
# Why this exists (Task 711): the old .cpanel.yml copied new views/layouts into
# public_html FIRST and only rebuilt the route cache minutes later (after
# composer). Any user hitting the site in that window got a real 500
# ("Route [...] not defined"). Racing auto-deploys could also leave POISONED
# compiled views (compiled mtime newer than blade => never recompiled).
#
# Contract (fail-closed):
#   1. Enter a 200 maintenance window (`artisan down --render=errors::deploying
#      --status=200 --refresh=4`) BEFORE touching any live file. If the window
#      cannot be established, ABORT with live untouched — never copy code
#      outside the window.
#   2. Inside the window: copy code, clear ALL caches (kills the stale route
#      cache and poisoned compiled views), composer, migrate, rebuild caches,
#      reset the WEB OPcache. EVERY step is checked; any failure exits nonzero.
#   3. `artisan up` runs ONLY after a fully successful release. On failure the
#      site STAYS on the friendly 200 maintenance page (never a broken app) —
#      fix by running `bash scripts/deploy-live.sh` (it fails loudly per step),
#      then `artisan up` on live.
#
# deploy-live.sh remains the authoritative manual deploy; this script only
# makes the automatic push-triggered deploy safe for live users.

set -u

DEPLOYPATH="/home/$USER/public_html"
PHP="/usr/local/bin/php"
LIVE_URL="https://taxnest.com.pk"
REPO_DIR="$(pwd)"
LOCKFILE="/home/$USER/.taxnest-deploy.lock"

log()  { echo "[autodeploy] $*"; }
# Deploy failed mid-window: keep the 200 maintenance page up (users must never
# see a half-deployed app), exit nonzero so the cPanel deploy log shows FAILURE.
die_down() {
  echo "[autodeploy] FAILED: $*" >&2
  echo "[autodeploy] Site left in MAINTENANCE (200 'Updating…' page)." >&2
  echo "[autodeploy] Recover: bash scripts/deploy-live.sh from the workspace, then 'php artisan up' on live." >&2
  exit 1
}

cd "$DEPLOYPATH" || { echo "[autodeploy] FATAL: cannot cd $DEPLOYPATH — live untouched" >&2; exit 1; }

# 0. SERIALIZE deploys: rapid task-merge pushes trigger overlapping cPanel
#    auto-deploys (the historical poisoned-view race). Take an exclusive lock
#    BEFORE staging/copying anything; a second run waits (up to 10 min) for
#    the first to finish completely — including its `artisan up`. The manual
#    deploy path (deploy-live.sh) takes the SAME lock for its live mutations.
#    The fd stays open for the whole script; the kernel releases the lock on
#    ANY exit (success, failure, or kill), so it can never stay stuck.
exec 9>"$LOCKFILE" \
  || { echo "[autodeploy] FATAL: cannot open lock file $LOCKFILE — live untouched" >&2; exit 1; }
if ! flock -w 600 9; then
  echo "[autodeploy] FATAL: another deploy is still running after 600s — aborting, live untouched." >&2
  exit 1
fi
log "deploy lock acquired"

# 0.5 SW CACHE_VERSION freshness (Task 713): every deploy must ship a NEW
#     public/sw.js CACHE_VERSION so devices purge old RUNTIME/STATIC caches and
#     get the SW update badge. deploy-live.sh auto-bumps + commits before its
#     push, but PLAIN pushes to origin main (e.g. platform task merges) reach
#     this script WITHOUT a bump. Decide here (read-only) whether this push
#     already carries a fresh version; the actual bump happens after the copy.
#
#     Idempotency = state file with the REPO version shipped by the LAST
#     deploy. If the incoming repo version differs, the push bumped it itself
#     (deploy-live.sh / manual) — never double-bump. If it is identical, the
#     push had no bump — server-bump with a unique timestamped version.
#     First run (no state file): fall back to comparing repo vs currently-live
#     sw.js version.
SW_STATE="/home/$USER/.taxnest-last-shipped-sw-version"
SW_VER_RE="^const CACHE_VERSION = '"
sw_ver() { grep -m1 "$SW_VER_RE" "$1" 2>/dev/null | sed "s/^const CACHE_VERSION = '\([^']*\)'.*/\1/"; }
REPO_SW_VER=$(sw_ver "$REPO_DIR/public/sw.js")
LIVE_SW_VER=$(sw_ver "public/sw.js")
NEED_SW_BUMP=0
if [ -z "$REPO_SW_VER" ]; then
  log "WARNING: could not read CACHE_VERSION from repo public/sw.js — skipping SW bump decision"
elif [ -f "$SW_STATE" ]; then
  LAST_SHIPPED=$(cat "$SW_STATE" 2>/dev/null || true)
  if [ "$REPO_SW_VER" = "$LAST_SHIPPED" ]; then
    NEED_SW_BUMP=1
    log "push did NOT change sw.js CACHE_VERSION ($REPO_SW_VER == last shipped) — will server-bump after copy"
  else
    log "push already carries a fresh sw.js CACHE_VERSION ($REPO_SW_VER) — no server bump"
  fi
else
  if [ -n "$LIVE_SW_VER" ] && [ "$REPO_SW_VER" = "$LIVE_SW_VER" ]; then
    NEED_SW_BUMP=1
    log "no SW state file yet; repo CACHE_VERSION == live ($REPO_SW_VER) — will server-bump after copy"
  else
    log "no SW state file yet; repo CACHE_VERSION ($REPO_SW_VER) != live ($LIVE_SW_VER) — treating as already bumped"
  fi
fi

# 1. Make sure the maintenance view exists BEFORE `down` pre-renders it
#    (first deploy after this change: public_html doesn't have it yet).
mkdir -p resources/views/errors \
  || { echo "[autodeploy] FATAL: cannot create errors view dir — live untouched" >&2; exit 1; }
/bin/cp -f "$REPO_DIR/resources/views/errors/deploying.blade.php" resources/views/errors/deploying.blade.php \
  || { echo "[autodeploy] FATAL: cannot stage deploying.blade.php — live untouched" >&2; exit 1; }

# 2. Enter the maintenance window: pre-rendered page, HTTP 200 (never a
#    500/503 to users), auto-refresh every 4s. If the 200 window cannot be
#    established, ABORT — do NOT copy code outside the window.
if ! $PHP artisan down --render=errors::deploying --status=200 --refresh=4 2>&1; then
  echo "[autodeploy] FATAL: could not enter 200 maintenance mode — ABORTING, live untouched." >&2
  echo "[autodeploy] Deploy manually with: bash scripts/deploy-live.sh" >&2
  exit 1
fi
log "maintenance window OPEN (HTTP 200 'Updating…' page)"

# ------- from here on, any failure leaves the site DOWN (maintenance) -------

# 3. Copy new code in (same semantics as the old .cpanel.yml cp -R).
/bin/cp -R "$REPO_DIR/." "$DEPLOYPATH/" || die_down "code copy (cp -R) failed"

# 3.5 Server-side SW CACHE_VERSION bump (Task 713) — only when the push itself
#     did not bump (decision made pre-copy above). Version-string-only sed;
#     no node smoke check here (the string swap cannot break handlers, and the
#     cPanel box has no node). deploy-live.sh knows this file can be locally
#     modified on live (CACHE_VERSION line only) and restores it before pull.
if [ "$NEED_SW_BUMP" = 1 ]; then
  NEW_SW_VERSION="taxnest-$(date +%Y%m%d-%H%M%S)-auto$(git -C "$REPO_DIR" rev-parse --short HEAD 2>/dev/null || echo push)"
  sed -i "s|^const CACHE_VERSION = '[^']*';.*$|const CACHE_VERSION = '$NEW_SW_VERSION'; // server-side auto-bump by cpanel-autodeploy.sh (Task 713) — push gap had no CACHE_VERSION change|" public/sw.js \
    || die_down "server-side sw.js CACHE_VERSION bump failed (sed error)"
  grep -q "const CACHE_VERSION = '$NEW_SW_VERSION'" public/sw.js \
    || die_down "server-side sw.js CACHE_VERSION bump not applied (CACHE_VERSION line pattern not matched — did sw.js header change?)"
  log "sw.js CACHE_VERSION server-bumped to $NEW_SW_VERSION"
fi
# Record what THIS push shipped (the repo's version, not the server-bumped
# one) so the next auto-deploy can tell whether its push bumped or not.
if [ -n "$REPO_SW_VER" ]; then
  printf '%s\n' "$REPO_SW_VER" > "$SW_STATE" \
    || log "WARNING: could not record shipped SW version to $SW_STATE (next deploy falls back to live-file comparison)"
fi

# 4. IMMEDIATELY kill every stale/poisoned cache. With caches cleared Laravel
#    falls back to dynamic loading, so new code + no cache = consistent.
#    view:clear also fixes the racing-merge poisoned-compiled-view scenario.
$PHP artisan config:clear 2>&1 || die_down "config:clear failed"
$PHP artisan route:clear  2>&1 || die_down "route:clear failed"
$PHP artisan view:clear   2>&1 || die_down "view:clear failed"
$PHP artisan cache:clear  2>&1 || die_down "cache:clear failed (app cache store unreachable?)"

# 5. Dependencies + schema (inside the maintenance window, so slow is fine).
#    Composer location varies per host (this cPanel box has it at ~/bin/composer,
#    NOT /usr/local/bin/composer — verified 15 Aug 2026). Resolve it, fail-closed.
COMPOSER=""
for C in "/home/$USER/bin/composer" /usr/local/bin/composer /opt/cpanel/composer/bin/composer "$(command -v composer 2>/dev/null)"; do
  [ -n "$C" ] && [ -f "$C" ] && { COMPOSER="$C"; break; }
done
[ -n "$COMPOSER" ] || die_down "composer binary not found (looked in ~/bin, /usr/local/bin, /opt/cpanel/composer/bin, PATH)"
$PHP "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction 2>&1 \
  || die_down "composer install failed (vendor may be incomplete)"
$PHP artisan migrate --force 2>&1 \
  || die_down "migrate --force failed (new code against old schema would 500)"
# storage:link errors when the link already exists (idempotent case) — that is
# the ONLY tolerated failure: afterwards public/storage MUST exist either way.
$PHP artisan storage:link 2>&1 || true
[ -e public/storage ] || die_down "public/storage missing after storage:link (uploads would 404)"

# 6. Rebuild caches fresh (still inside the window).
$PHP artisan config:cache 2>&1 || die_down "config:cache failed"
$PHP artisan route:cache  2>&1 || die_down "route:cache failed"
$PHP artisan view:cache   2>&1 || die_down "view:cache failed"

chmod -R 775 storage bootstrap/cache 2>&1 \
  || die_down "chmod on storage/bootstrap-cache failed (web writes could break)"

# 7. Reset the WEB (PHP-FPM) OPcache — CLI clears never touch it; without this
#    the web server can keep serving OLD opcode against NEW cached routes/views
#    (exactly the mixed-release 500 this task exists to prevent). FAIL-CLOSED:
#    the reset must be CONFIRMED via a real web hit or the site stays in
#    maintenance. Cloudflare fronting the domain can flake, so also try the
#    origin webserver directly (localhost + Host header, cert check off) and
#    retry each route before giving up.
#    The probe file gets an UNGUESSABLE one-time name (never plain r.php — an
#    unauthenticated client must not be able to trigger opcache_reset) and a
#    trap guarantees cleanup even if this script is interrupted mid-probe.
RTOKEN=$( { tr -dc 'a-z0-9' </dev/urandom | head -c 32; } 2>/dev/null )
[ ${#RTOKEN} -ge 16 ] || RTOKEN="$(date +%s%N)$$${RANDOM}${RANDOM}"
RPROBE="opr-$RTOKEN.php"
trap 'rm -f "$DEPLOYPATH/public/$RPROBE"' EXIT INT TERM
echo '<?php opcache_reset(); echo "OPCACHE_RESET_OK"; ?>' > "public/$RPROBE" \
  || die_down "could not write OPcache reset probe"
OP_OK=0
for TRY in 1 2 3; do
  OP_OUT=$(curl -s --max-time 15 "$LIVE_URL/$RPROBE" || true)
  case "$OP_OUT" in *OPCACHE_RESET_OK*) OP_OK=1; break ;; esac
  # Origin-direct fallback: bypasses Cloudflare, still a real PHP-FPM hit.
  OP_OUT=$(curl -sk --max-time 15 -H "Host: taxnest.com.pk" "https://127.0.0.1/$RPROBE" || true)
  case "$OP_OUT" in *OPCACHE_RESET_OK*) OP_OK=1; break ;; esac
  OP_OUT=$(curl -s --max-time 15 -H "Host: taxnest.com.pk" "http://127.0.0.1/$RPROBE" || true)
  case "$OP_OUT" in *OPCACHE_RESET_OK*) OP_OK=1; break ;; esac
  sleep 3
done
rm -f "public/$RPROBE"
if [ "$OP_OK" -eq 1 ]; then
  log "web OPcache reset CONFIRMED"
else
  die_down "web OPcache reset NOT confirmed after retries — old opcode could serve against new caches"
fi

# 8. Everything succeeded — reopen the site.
$PHP artisan up 2>&1 || die_down "artisan up failed after successful release (run 'php artisan up' on live)"
log "auto-deploy COMPLETE — site is UP"
exit 0
