#!/usr/bin/env bash
# Isolated synthetic browser fixture. State is always beneath /tmp.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LAB_CTL="$ROOT/scripts/rc-mariadb-lab.sh"
STATE_BASE="$(realpath -m "${RC_BROWSER_STATE_ROOT:-/tmp/taxnest-rc-browser-${UID}}")"
SAFE_RUNTIME="$STATE_BASE/safe-runtime"
LAB_ROOT="$(realpath -m "${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-browser-${UID}}")"
DB_PORT="${RC_MARIADB_PORT:-33117}"
DATABASE="${RC_BROWSER_DB:-taxnest_rc_browser}"
STATE_ROOT="$SAFE_RUNTIME/browser-state"
FIXTURE="$STATE_ROOT/fixture.json"; SERVER_PID="$STATE_ROOT/php-server.pid"; SERVER_LOG="$STATE_ROOT/php-server.log"
BROWSER_PORT="${RC_BROWSER_PORT:-5911}"
fail(){ printf 'rc-browser-fixture: %s\n' "$*" >&2; exit 2; }
[[ "$STATE_BASE" == /tmp/taxnest-rc-browser-* && "$LAB_ROOT" == /tmp/taxnest-rc-mariadb-browser-* && "$STATE_ROOT" == "$STATE_BASE/safe-runtime/browser-state" ]] || fail 'isolated paths required'
[[ "$DATABASE" == taxnest_rc_browser && "$BROWSER_PORT" == 5911 ]] || fail 'exact browser database and port required'
resolve_mariadbd() {
  [[ -n "${RC_MARIADBD:-}" && -x "$RC_MARIADBD" ]] && return
  local p
  for p in "$(command -v mariadbd 2>/dev/null || true)" "$(command -v mysqld 2>/dev/null || true)" /nix/store/*mariadb-server-*/bin/mariadbd; do
    [[ -x "$p" ]] && "$p" --version 2>&1 | grep -q MariaDB && { export RC_MARIADBD="$p"; return; }
  done
  fail 'no local MariaDB server found'
}
safe_browser() { "$ROOT/scripts/rc-safe-run" --browser --runtime "$SAFE_RUNTIME" --mariadb-root "$LAB_ROOT" -- "$@"; }
lab() { RC_MARIADB_ROOT="$LAB_ROOT" RC_MARIADB_PORT="$DB_PORT" bash "$LAB_CTL" "$@"; }
start_server() {
  rm -f "$SERVER_PID"
  safe_browser php artisan serve --host=127.0.0.1 --port="$BROWSER_PORT" </dev/null >"$SERVER_LOG" 2>&1 & echo "$!" >"$SERVER_PID"
  for _ in $(seq 1 30); do curl --fail --silent --max-time 1 "http://127.0.0.1:$BROWSER_PORT/" >/dev/null && return; sleep 1; done
  fail 'loopback PHP server did not become ready'
}
setup() {
  mkdir -p "$STATE_ROOT"; chmod 700 "$STATE_ROOT"; resolve_mariadbd; lab start
  SOCKET="$LAB_ROOT/run/mariadb.sock"; [[ -S "$SOCKET" ]] || fail 'MariaDB socket absent'; cd "$ROOT"
  CLIENT="$(lab env | awk -F= '$1=="RC_MARIADB_CLIENT"{print substr($0,index($0,"=")+1)}')"
  "$CLIENT" --protocol=socket --socket="$SOCKET" -uroot -e "DROP DATABASE IF EXISTS \`$DATABASE\`; CREATE DATABASE \`$DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  safe_browser env RC_BROWSER_FIXTURE_FRESH=1 php artisan migrate --force --no-interaction
  safe_browser env RC_BROWSER_FIXTURE_FRESH=1 php scripts/rc-browser-fixture-seed.php
  safe_browser php scripts/rc-di-browser-fixture.php
  start_server
  printf 'RC_BROWSER_FIXTURE=%s\n' "$FIXTURE"
}
resume() {
  mkdir -p "$STATE_ROOT"; chmod 700 "$STATE_ROOT"; resolve_mariadbd; lab start
  SOCKET="$LAB_ROOT/run/mariadb.sock"; [[ -S "$SOCKET" && -d "$LAB_ROOT/data/$DATABASE" ]] || fail 'resume requires existing isolated database data'
  cd "$ROOT"; safe_browser php scripts/rc-browser-fixture-resume.php; safe_browser php scripts/rc-di-browser-fixture.php; start_server
  printf 'RC_BROWSER_FIXTURE=%s\n' "$FIXTURE"
}
run() { [[ -s "$FIXTURE" && -s "$SERVER_PID" ]] || fail 'run requires setup or resume'; kill -0 "$(cat "$SERVER_PID")" 2>/dev/null || fail 'loopback PHP server is not running'; cd "$ROOT"; safe_browser env BASE_URL="http://127.0.0.1:$BROWSER_PORT" RC_BROWSER_FIXTURE="$FIXTURE" node scripts/rc-browser-acceptance.mjs; }
serve() { resume; wait "$(cat "$SERVER_PID")"; }
stop() { [[ -s "$SERVER_PID" ]] && kill "$(cat "$SERVER_PID")" 2>/dev/null || true; rm -rf "$STATE_BASE"; resolve_mariadbd; lab stop || true; }
case "${1:-}" in setup)setup;; resume)resume;; run)run;; serve)serve;; stop)stop;; *) fail 'usage: setup|resume|serve|run|stop';; esac