#!/bin/bash
# monitor-agent-repo-flip.sh
#
# Monitors agent versions for the 4 live POS shops (IDs 23, 26, 27, 28).
# When ALL report >= 1.7.0, flips jawadrao5555-alt/taxnest to private via
# the GitHub API and removes itself from cron.
#
# Context (Aug 2026): releases moved from jawadrao5555-alt/taxnest to the
# public-read jawadrao5555-alt/nestpos-releases repo so the source repo can
# go private. Agents < 1.7.0 host-pin the old taxnest URL; a transition shim
# in AgentController::agentUpdateInfo() rewrites download URLs for those agents
# so they can still self-update to 1.7.0. Once all shops hit 1.7.0 this script
# fires and the repo can safely go private (shim becomes a no-op too).
#
# Installed as a cron entry on the cPanel host (every 15 min):
#   */15 * * * * /bin/bash /home/taxnestc/monitor_agent_versions.sh >> /home/taxnestc/monitor_agent_update.log 2>&1
#
# The live copy on the server has GITHUB_TOKEN substituted with the real token.
# NEVER commit the token here.

LIVE_DIR="/home/taxnestc/public_html"
LOG_FILE="/home/taxnestc/monitor_agent_update.log"
GITHUB_TOKEN="__GITHUB_TOKEN_PLACEHOLDER__"
REPO="jawadrao5555-alt/taxnest"
# Shop company IDs that must all reach >= 1.7.0
REQUIRED_IDS="23,26,27,28"

echo "$(date '+%Y-%m-%d %H:%M:%S') Checking agent versions..." >> "$LOG_FILE"

# Parse .env for DB creds (handles quoted values)
ENV_FILE="$LIVE_DIR/.env"
DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d " ")
DB_NAME=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d " ")
DB_USER=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d " ")
DB_PASS=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d " ")

# Log current versions for the record
mysql -h"${DB_HOST:-localhost}" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
  "SELECT id, name, agent_version, agent_last_seen FROM companies WHERE id IN ($REQUIRED_IDS);" \
  2>/dev/null >> "$LOG_FILE"

if [ $? -ne 0 ]; then
  echo "$(date '+%Y-%m-%d %H:%M:%S') ERROR: DB query failed" >> "$LOG_FILE"
  exit 1
fi

# Count shops NOT yet on >= 1.7.0 (NULL/blank version counts as not ready)
NOT_READY=$(mysql -h"${DB_HOST:-localhost}" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e \
  "SELECT COUNT(*) FROM companies
   WHERE id IN ($REQUIRED_IDS)
     AND (agent_version IS NULL
          OR agent_version = ''
          OR INET_ATON(CONCAT(REPLACE(agent_version,'v',''),'.0'))
             < INET_ATON('1.7.0.0'));" 2>/dev/null)

if [ -z "$NOT_READY" ] || [ "$NOT_READY" -gt 0 ]; then
  echo "$(date '+%Y-%m-%d %H:%M:%S') Not all shops on 1.7.0 yet (not_ready_count=${NOT_READY:-?}). Will retry." \
    >> "$LOG_FILE"
  exit 0
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') All shops confirmed >= 1.7.0. Flipping $REPO to private..." \
  >> "$LOG_FILE"

# Call GitHub API to make the repo private
API_RESP=$(curl -s -w "\nHTTP_STATUS:%{http_code}" \
  -X PATCH \
  -H "Authorization: token $GITHUB_TOKEN" \
  -H "Accept: application/vnd.github+json" \
  -H "Content-Type: application/json" \
  "https://api.github.com/repos/$REPO" \
  -d '{"private":true}')

HTTP_STATUS=$(echo "$API_RESP" | grep "HTTP_STATUS:" | cut -d: -f2)
BODY=$(echo "$API_RESP" | grep -v "HTTP_STATUS:")

echo "$(date '+%Y-%m-%d %H:%M:%S') GitHub API HTTP status: $HTTP_STATUS" >> "$LOG_FILE"

if [ "$HTTP_STATUS" = "200" ]; then
  IS_PRIVATE=$(echo "$BODY" | grep -o '"private":true' | head -1)
  echo "$(date '+%Y-%m-%d %H:%M:%S') SUCCESS: taxnest repo flipped to private. confirmed=$IS_PRIVATE" \
    >> "$LOG_FILE"
  echo "$(date '+%Y-%m-%d %H:%M:%S') NEXT STEP: remove the transition shim from AgentController::agentUpdateInfo() (see task #490)." \
    >> "$LOG_FILE"
  # Self-remove from cron so it never runs again
  crontab -l 2>/dev/null | grep -v "monitor_agent_versions.sh" | crontab -
  echo "$(date '+%Y-%m-%d %H:%M:%S') Cron entry removed." >> "$LOG_FILE"
else
  echo "$(date '+%Y-%m-%d %H:%M:%S') ERROR: GitHub API returned $HTTP_STATUS. Will retry next run." \
    >> "$LOG_FILE"
  echo "$(date '+%Y-%m-%d %H:%M:%S') Response body: $BODY" >> "$LOG_FILE"
fi
