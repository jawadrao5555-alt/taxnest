#!/usr/bin/env bash
# Isolated synthetic browser fixture. State is always beneath /tmp.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LAB_CTL="$ROOT/scripts/rc-mariadb-lab.sh"
LAB_ROOT="$(realpath -m "${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-browser-${UID}}")"
DB_PORT="${RC_MARIADB_PORT:-33117}"
DATABASE="${RC_BROWSER_DB:-taxnest_rc_browser}"
STATE_ROOT="${RC_BROWSER_STATE_ROOT:-/tmp/taxnest-rc-browser-${UID}}"
FIXTURE="$STATE_ROOT/fixture.json"; SERVER_PID="$STATE_ROOT/php-server.pid"; SERVER_LOG="$STATE_ROOT/php-server.log"
BROWSER_PORT="${RC_BROWSER_PORT:-5911}"
fail(){ printf 'rc-browser-fixture: %s\n' "$*" >&2; exit 2; }
[[ "$LAB_ROOT" == /tmp/taxnest-rc-mariadb-* && "$STATE_ROOT" == /tmp/taxnest-rc-browser-* ]] || fail 'isolated paths required'
[[ "$DATABASE" == taxnest_rc_browser && "$BROWSER_PORT" == 5911 ]] || fail 'exact browser database and port required'
resolve_mariadbd() {
  [[ -n "${RC_MARIADBD:-}" && -x "$RC_MARIADBD" ]] && return
  local p
  for p in "$(command -v mariadbd 2>/dev/null || true)" "$(command -v mysqld 2>/dev/null || true)" /nix/store/*mariadb-server-*/bin/mariadbd; do
    [[ -x "$p" ]] && "$p" --version 2>&1 | grep -q MariaDB && { export RC_MARIADBD="$p"; return; }
  done
  fail 'no local MariaDB server found'
}
configure_app() {
  export APP_ENV=rc-browser APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' APP_DEBUG=false APP_URL='http://127.0.0.1'
  export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$DB_PORT" DB_SOCKET="$SOCKET" DB_DATABASE="$DATABASE" DB_USERNAME=root DB_PASSWORD='' DB_CONNECT_TIMEOUT=3
  export HONOR_DATABASE_URL=0 CACHE_STORE=array SESSION_DRIVER=file QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=null
  export FBR_API_URL='' FBR_SANDBOX_URL='' FBR_PRODUCTION_URL='' FBR_TOKEN='' PRA_API_URL='' PRA_SANDBOX_URL='' PRA_PRODUCTION_URL='' PRA_TOKEN='' TAXNEST_RC_NO_EXTERNAL_FISCAL=1
  export RC_BROWSER_FIXTURE_OUT="$FIXTURE"; unset DATABASE_URL
}
start_server() {
  rm -f "$SERVER_PID"
  php artisan serve --host=127.0.0.1 --port="$BROWSER_PORT" </dev/null >"$SERVER_LOG" 2>&1 & echo "$!" >"$SERVER_PID"
  for _ in $(seq 1 30); do curl --fail --silent --max-time 1 "http://127.0.0.1:$BROWSER_PORT/" >/dev/null && return; sleep 1; done
  fail 'loopback PHP server did not become ready'
}
setup() {
  mkdir -p "$STATE_ROOT"; chmod 700 "$STATE_ROOT"; resolve_mariadbd; bash "$LAB_CTL" start
  SOCKET="$LAB_ROOT/run/mariadb.sock"; [[ -S "$SOCKET" ]] || fail 'MariaDB socket absent'; configure_app; cd "$ROOT"
  CLIENT="$(bash "$LAB_CTL" env | awk -F= '$1=="RC_MARIADB_CLIENT"{print substr($0,index($0,"=")+1)}')"
  "$CLIENT" --protocol=socket --socket="$SOCKET" -uroot -e "DROP DATABASE IF EXISTS \`$DATABASE\`; CREATE DATABASE \`$DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  php artisan migrate --force --no-interaction; php scripts/rc-browser-fixture-seed.php; php scripts/rc-di-browser-fixture.php; start_server
  printf 'RC_BROWSER_FIXTURE=%s\n' "$FIXTURE"
}
resume() {
  mkdir -p "$STATE_ROOT"; chmod 700 "$STATE_ROOT"; resolve_mariadbd; bash "$LAB_CTL" start
  SOCKET="$LAB_ROOT/run/mariadb.sock"; [[ -S "$SOCKET" && -d "$LAB_ROOT/data/$DATABASE" ]] || fail 'resume requires existing isolated database data'
  configure_app; cd "$ROOT"; php scripts/rc-browser-fixture-resume.php; php scripts/rc-di-browser-fixture.php; start_server
  printf 'RC_BROWSER_FIXTURE=%s\n' "$FIXTURE"
}
run() { [[ -s "$FIXTURE" && -s "$SERVER_PID" ]] || fail 'run requires setup or resume'; kill -0 "$(cat "$SERVER_PID")" 2>/dev/null || fail 'loopback PHP server is not running'; cd "$ROOT"; BASE_URL="http://127.0.0.1:$BROWSER_PORT" RC_BROWSER_FIXTURE="$FIXTURE" node scripts/rc-browser-acceptance.mjs; }
serve() { resume; wait "$(cat "$SERVER_PID")"; }
stop() { [[ -s "$SERVER_PID" ]] && kill "$(cat "$SERVER_PID")" 2>/dev/null || true; rm -rf "$STATE_ROOT"; resolve_mariadbd; bash "$LAB_CTL" stop || true; }
case "${1:-}" in setup)setup;; resume)resume;; run)run;; serve)serve;; stop)stop;; *) fail 'usage: setup|resume|serve|run|stop';; esac