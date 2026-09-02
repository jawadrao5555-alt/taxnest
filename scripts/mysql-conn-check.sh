#!/bin/bash
# mysql-conn-check.sh — verify MySQL connection-limit variables on live.
#
# Run after the hosting owner raises max_connections / wait_timeout / max_user_connections
# via WHM → SQL Services → MySQL/MariaDB Configuration.
#
# Usage: bash scripts/mysql-conn-check.sh
#
# Prints the five key variables + current high-water mark and exits 0 on success.
# Fails loudly (exit 1) if the SSH connection or MySQL query cannot run.
#
# Background: live MySQL hit Max_used_connections=401 (cap 400) on 17 Aug 2026.
# See .agents/memory/mysql-connection-headroom.md for full context and the WHM
# changes the owner must make.

set -uo pipefail

# shellcheck source=scripts/lib/live-host.sh
source "$(dirname "$0")/lib/live-host.sh"
live_host_assert_not_retired || exit 1
KEY="$LIVE_SSH_KEY"
HOST="$LIVE_SSH_HOST"
SSH_OPTS=("${LIVE_SSH_OPTS[@]}")

fail() { echo ""; echo "FAILED: $*" >&2; exit 1; }

[ -f "$KEY" ] || fail "deploy key not found at $KEY — run from the Replit workspace"

echo "==> Querying live MySQL connection variables ..."
echo ""

OUT=$(timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" 'bash -s' 2>&1 <<'REMOTE'
set -u
# cPanel SSH sessions export DB_USER / DB_PASS / DB_DATABASE from the
# account's .bashrc — use them directly.
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_DATABASE" --silent --skip-column-names \
  -e "
SELECT '--- VARIABLES ---'                                          AS '';
SHOW VARIABLES LIKE 'max_connections';
SHOW VARIABLES LIKE 'wait_timeout';
SHOW VARIABLES LIKE 'interactive_timeout';
SHOW VARIABLES LIKE 'max_user_connections';
SELECT '--- STATUS ------'                                          AS '';
SHOW STATUS   LIKE 'Max_used_connections';
SHOW STATUS   LIKE 'Threads_connected';
SHOW STATUS   LIKE 'Threads_running';
" 2>&1
REMOTE
) || fail "SSH/MySQL query failed (output: ${OUT:-empty})"

echo "$OUT"
echo ""

# Parse max_connections value for a quick sanity check.
MAX_CONN=$(echo "$OUT" | awk '/^max_connections/{print $2}')
if [ -n "$MAX_CONN" ] && [ "$MAX_CONN" -ge 600 ] 2>/dev/null; then
  echo "==> OK: max_connections = $MAX_CONN (>= 600 target)"
elif [ -n "$MAX_CONN" ]; then
  echo "!!! WARNING: max_connections = $MAX_CONN — still below the 600 target." >&2
  echo "!!!          Ask the host to raise it in WHM → SQL Services → MySQL/MariaDB Configuration." >&2
else
  echo "!!! Could not parse max_connections from output above — verify manually." >&2
fi

exit 0
