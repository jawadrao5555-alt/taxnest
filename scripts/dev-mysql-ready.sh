#!/bin/bash
# dev-mysql-ready.sh — DEV-ONLY readiness probe for the "MySQL Staging" service.
#
# WHY THIS EXISTS
#   The "MySQL Staging" workflow reports "running" the moment mysqld is spawned,
#   but mysqld needs ~10-20s (InnoDB init + XA crash recovery) before it accepts
#   connections on 127.0.0.1:9000. Any request that lands in that warm-up window
#   hits a dead DB, so a normal browser login renders the friendly db-down page
#   (resources/views/errors/db-down.blade.php) and a perfectly healthy preview
#   looks broken. The "Laravel Server" workflow therefore gates `artisan serve`
#   on this probe, and scripts/post-merge.sh uses it before migrating.
#
# USAGE
#   bash scripts/dev-mysql-ready.sh                    # probe once: exit 0 ready / 1 not ready
#   bash scripts/dev-mysql-ready.sh --wait 15          # wait up to 15s for readiness
#   bash scripts/dev-mysql-ready.sh --wait 90 --heal   # also clear stale locks + start mysqld
#   bash scripts/dev-mysql-ready.sh --quiet            # exit code only, no output
#
# NOTES
#   * KEEP THE "Laravel Server" GATE SHORT (15s). A workflow that has not opened
#     its waitForPort within ~24s is KILLED by the platform (DIDNT_OPEN_A_PORT),
#     so a long gate would trade a db-down page for no server at all. A cold
#     mysqld here reaches "accepting connections" in ~7-9s, so 15s covers the
#     warm-up with margin; if it ever expires, artisan serve still starts and
#     the db-down page (auto-refreshing) takes over exactly as before.
#   * `ss -ltn` lies in this sandbox (shows no listeners even when the port is
#     open) — readiness is proven with /dev/tcp + a real `SELECT 1` handshake.
#   * PRODUCTION IS NEVER TOUCHED: this only ever talks to 127.0.0.1:9000 using
#     the dev defaults-file .local/mysql_run/my.cnf, both of which exist only in
#     the Replit dev container. The live cPanel MySQL is unaffected.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_DIR="$ROOT/.local/mysql_run"
CNF="$RUN_DIR/my.cnf"
ERR_LOG="$ROOT/.local/mysql_log/mysql.err"
DB_HOST=127.0.0.1
DB_PORT=9000

WAIT=0
HEAL=0
QUIET=0

usage() {
  sed -n '2,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
  case "$1" in
    --wait)   WAIT="${2:-0}"; shift 2 ;;
    --wait=*) WAIT="${1#*=}"; shift ;;
    --heal)   HEAL=1; shift ;;
    --quiet|-q) QUIET=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "dev-mysql-ready: unknown option '$1'" >&2; usage >&2; exit 2 ;;
  esac
done

say()  { [ "$QUIET" -eq 1 ] || echo "$@"; }
warn() { echo "$@" >&2; }

# TCP port check — cheap first gate (mysqld opens 9000 only late in startup).
port_open() { (echo > "/dev/tcp/$DB_HOST/$DB_PORT") 2>/dev/null; }

# Real handshake: an open port is not the same as "accepting connections".
accepts_connections() {
  port_open || return 1
  if command -v mysql >/dev/null 2>&1; then
    mysql --defaults-file="$CNF" --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" \
      -u root --connect-timeout=3 -N -e 'SELECT 1' >/dev/null 2>&1 || return 1
  fi
  return 0
}

# --heal: only ever starts mysqld when nothing is running (a booting mysqld must
# be left alone, and a second mysqld would abort on the socket lock anyway).
heal_start() {
  if pgrep -x mysqld >/dev/null 2>&1; then
    say "  mysqld process exists — still warming up, waiting..."
    return 0
  fi
  say "  no mysqld process — clearing stale socket/lock/pid and starting mysqld..."
  rm -f "$RUN_DIR/mysql.sock" "$RUN_DIR/mysql.sock.lock" "$RUN_DIR/mysql.pid"
  nohup mysqld --defaults-file="$CNF" >/dev/null 2>&1 &
}

timeout_help() {
  warn "WARNING: dev MySQL is NOT accepting connections on $DB_HOST:$DB_PORT after ${WAIT}s."
  warn "         Browser checks will show the db-down page until it is up. Usual fix:"
  warn "           1. pgrep -x mysqld                # empty? a stale socket lock is the cause"
  warn "           2. rm -f .local/mysql_run/mysql.sock .local/mysql_run/mysql.sock.lock .local/mysql_run/mysql.pid"
  warn "           3. restart the 'MySQL Staging' workflow (error log: $ERR_LOG)"
}

if accepts_connections; then
  say "dev MySQL ready — $DB_HOST:$DB_PORT accepting connections."
  exit 0
fi

[ "$HEAL" -eq 1 ] && heal_start

if [ "$WAIT" -le 0 ]; then
  say "dev MySQL NOT ready — $DB_HOST:$DB_PORT is not accepting connections."
  exit 1
fi

say "Waiting up to ${WAIT}s for dev MySQL ($DB_HOST:$DB_PORT) to accept connections..."
START=$SECONDS
while [ $((SECONDS - START)) -lt "$WAIT" ]; do
  sleep 1
  if accepts_connections; then
    say "dev MySQL ready after $((SECONDS - START))s — $DB_HOST:$DB_PORT accepting connections."
    exit 0
  fi
done

timeout_help
exit 1
