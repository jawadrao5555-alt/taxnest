#!/bin/bash
# dev-mysql-serve.sh — foreground launcher for the "MySQL Staging" workflow.
#
# Replaces the bare `mysqld --defaults-file=...` command so that:
#   1. Stale socket/lock/pid files left behind by a workspace sleep are cleared
#      (ONLY when no mysqld is actually running) — otherwise mysqld aborts with
#      MY-010259 "Another process with pid N is using unix socket file" and the
#      dev database never comes up at all.
#   2. The workflow log gets an explicit READY line once 127.0.0.1:9000 actually
#      accepts connections, so "workflow running" is no longer confused with
#      "database usable" (mysqld needs ~10-20s of InnoDB init before it is).
#   3. mysqld is exec'd, so the workflow keeps tracking the real mysqld process.
#
# Dev container only — the live cPanel MySQL is never touched by this script.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_DIR="$ROOT/.local/mysql_run"
CNF="$RUN_DIR/my.cnf"

if pgrep -x mysqld >/dev/null 2>&1; then
  # An orphan mysqld (e.g. started by scripts/post-merge.sh self-heal) already
  # holds the socket; a second one will abort. Say so loudly instead of leaving
  # a cryptic error in mysql.err.
  echo "NOTE: a mysqld process is already running."
  echo "      If this workflow aborts with 'Another process ... unix socket file', stop the"
  echo "      orphan first:  pkill -x mysqld  (then restart this workflow)."
else
  for f in mysql.sock mysql.sock.lock mysql.pid; do
    if [ -e "$RUN_DIR/$f" ]; then
      echo "Clearing stale $f (no mysqld process is running)."
      rm -f "$RUN_DIR/$f"
    fi
  done
fi

# Readiness announcer: mysqld itself prints nothing to stdout (it logs to
# .local/mysql_log/mysql.err), so this is the line that marks the end of the
# warm-up window in the workflow console.
(
  if bash "$ROOT/scripts/dev-mysql-ready.sh" --wait 120 --quiet; then
    echo "MySQL Staging READY — 127.0.0.1:9000 is accepting connections."
  else
    echo "MySQL Staging did NOT become ready within 120s — see .local/mysql_log/mysql.err"
  fi
) &

exec mysqld --defaults-file="$CNF"
