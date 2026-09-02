#!/bin/bash
# monitor-agent-repo-flip.sh
#
# Monitors agent versions for the 4 live POS shops (IDs 23, 26, 27, 28).
# When ALL FOUR rows are present AND report >= 1.7.0, in this fail-safe order:
#   1. points the server release feed (SystemSetting agent_release_repo) at
#      jawadrao5555-alt/nestpos-releases + clears the cached release info,
#      and VERIFIES the switch persisted (read-back) and the new feed serves
#      a release (GitHub API 200) — aborts (cron stays) on any failure;
#   2. flips jawadrao5555-alt/taxnest to private via the GitHub API and
#      verifies the response actually reports "private": true;
#   3. only then removes itself from cron.
# Every step is idempotent, so a failed run safely retries in 15 min.
#
# Context (Aug 2026): releases moved from jawadrao5555-alt/taxnest to the
# public-read jawadrao5555-alt/nestpos-releases repo so the source repo can
# go private. Agents < 1.7.0 host-pin the old taxnest URL; a transition shim
# in AgentController::agentUpdateInfo() rewrites download URLs for those agents
# so they can still self-update to 1.7.0. Once all shops hit 1.7.0 this script
# fires and the repo can safely go private (shim becomes a no-op too).
#
# Installed as a cron entry on the live origin (every 15 min), e.g.:
#   */15 * * * * /bin/bash /home/jawadrao5555/monitor_agent_versions.sh >> /home/jawadrao5555/monitor_agent_update.log 2>&1
#
# The live copy on the server has GITHUB_TOKEN substituted with the real token.
# NEVER commit the token here.
#
# NOTE (16 Aug 2026): the original live install was quote-mangled (heredoc
# stripping) and errored every run. Install via scp (byte-exact) + bash -n
# + one manual run, never via heredoc.

# Runs ON the live origin, so it cannot source scripts/lib/live-host.sh (that
# file describes how to REACH the origin from the workspace). Keep these in
# step with it. Overridable so a rebuilt server does not need a code change.
LIVE_DIR="${LIVE_DIR:-/var/www/taxnest}"
LOG_FILE="${LOG_FILE:-/home/jawadrao5555/monitor_agent_update.log}"
PHP="${PHP:-/usr/bin/php}"
GITHUB_TOKEN="__GITHUB_TOKEN_PLACEHOLDER__"
OLD_REPO="jawadrao5555-alt/taxnest"
NEW_REPO="jawadrao5555-alt/nestpos-releases"
# Shop company IDs that must ALL be present and >= 1.7.0
REQUIRED_IDS="23,26,27,28"
REQUIRED_COUNT=4

ts() { date "+%Y-%m-%d %H:%M:%S"; }
log() { echo "$(ts) $*" >> "$LOG_FILE"; }

log "Checking agent versions..."

# Parse .env for DB creds (values may be quoted)
ENV_FILE="$LIVE_DIR/.env"
envval() {
  grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | sed -e "s/^[\"']//" -e "s/[\"']$//" -e "s/[[:space:]]*$//"
}
DB_HOST=$(envval DB_HOST)
DB_NAME=$(envval DB_DATABASE)
DB_USER=$(envval DB_USERNAME)
DB_PASS=$(envval DB_PASSWORD)

# Log current versions for the record
RESULT=$(mysql -h"${DB_HOST:-localhost}" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
  "SELECT id, name, agent_version, agent_last_seen FROM companies WHERE id IN ($REQUIRED_IDS);" 2>/dev/null)

if [ $? -ne 0 ]; then
  log "ERROR: DB query failed"
  exit 1
fi

echo "$RESULT" >> "$LOG_FILE"

# Count shops that are present AND on >= 1.7.0. Requiring READY == 4 (instead
# of counting the not-ready ones) means a MISSING company row can never look
# "ready" — COUNT over absent rows would otherwise silently pass.
READY=$(mysql -h"${DB_HOST:-localhost}" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e \
  "SELECT COUNT(*) FROM companies
   WHERE id IN ($REQUIRED_IDS)
     AND agent_version IS NOT NULL
     AND agent_version != ''
     AND INET_ATON(CONCAT(REPLACE(agent_version,'v',''),'.0'))
         >= INET_ATON('1.7.0.0');" 2>/dev/null)

if [ -z "$READY" ] || [ "$READY" -ne "$REQUIRED_COUNT" ] 2>/dev/null; then
  log "Not all $REQUIRED_COUNT shops on 1.7.0 yet (ready_count=${READY:-?}). Will retry."
  exit 0
fi

log "All $REQUIRED_COUNT shops confirmed >= 1.7.0."

# ── Step 1: switch the release feed FIRST (nestpos-releases is public, so
# this is safe regardless of the old repo's visibility) and verify it stuck.
# If this fails we abort WITHOUT privatizing — the old repo must stay public
# while the server feed still points at it.
FEED_OUT=$($PHP -d display_errors=1 -r "
  require '$LIVE_DIR/vendor/autoload.php';
  \$app = require '$LIVE_DIR/bootstrap/app.php';
  \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  \App\Models\SystemSetting::set('agent_release_repo', '$NEW_REPO');
  Illuminate\Support\Facades\Cache::forget('taxnest_agent_latest_release');
  \$check = \App\Models\SystemSetting::get('agent_release_repo');
  echo 'FEED_NOW=', \$check, PHP_EOL;
" 2>&1)
FEED_RC=$?
echo "$FEED_OUT" >> "$LOG_FILE"
if [ $FEED_RC -ne 0 ] || ! echo "$FEED_OUT" | grep -q "FEED_NOW=$NEW_REPO"; then
  log "ERROR: release-feed switch did not persist (rc=$FEED_RC). NOT privatizing. Will retry."
  exit 1
fi

# Verify the new feed actually serves a latest release (anonymous, like the app).
NEW_FEED_HTTP=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Accept: application/vnd.github+json" \
  "https://api.github.com/repos/$NEW_REPO/releases/latest")
if [ "$NEW_FEED_HTTP" != "200" ]; then
  log "ERROR: $NEW_REPO releases/latest returned $NEW_FEED_HTTP. NOT privatizing. Will retry."
  exit 1
fi
log "Release feed switched to $NEW_REPO and verified (releases/latest HTTP 200)."

# ── Step 2: flip the old repo to private, verify GitHub confirms it.
API_RESP=$(curl -s -w "\nHTTP_STATUS:%{http_code}" \
  -X PATCH \
  -H "Authorization: token $GITHUB_TOKEN" \
  -H "Accept: application/vnd.github+json" \
  -H "Content-Type: application/json" \
  "https://api.github.com/repos/$OLD_REPO" \
  -d '{"private":true}')

HTTP_STATUS=$(echo "$API_RESP" | grep "HTTP_STATUS:" | cut -d: -f2)
BODY=$(echo "$API_RESP" | grep -v "HTTP_STATUS:")

log "GitHub API HTTP status: $HTTP_STATUS"

if [ "$HTTP_STATUS" != "200" ] || ! echo "$BODY" | grep -q "\"private\": *true"; then
  log "ERROR: privatize not confirmed (HTTP $HTTP_STATUS). Feed already on $NEW_REPO (safe). Will retry."
  log "Body head: $(echo "$BODY" | head -c 300)"
  exit 1
fi
log "SUCCESS: $OLD_REPO flipped to private (response confirms private:true)."
log "NEXT STEP: remove the transition shim from AgentController::agentUpdateInfo()."

# ── Step 3: everything verified — self-remove from cron.
crontab -l 2>/dev/null | grep -v "monitor_agent_versions.sh" | crontab -
log "Cron entry removed. Task complete."
