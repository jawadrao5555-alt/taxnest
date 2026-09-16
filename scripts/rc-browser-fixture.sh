#!/usr/bin/env bash
# Isolated synthetic browser fixture. State is always beneath /tmp.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LAB_CTL="$ROOT/scripts/rc-mariadb-lab.sh"
STATE_INPUT="${RC_BROWSER_STATE_ROOT:-/tmp/taxnest-rc-browser-${UID}}"
LAB_INPUT="${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-browser-${UID}}"
STATE_BASE="$(realpath -m -- "$STATE_INPUT")"
SAFE_RUNTIME="$STATE_BASE/safe-runtime"
LAB_ROOT="$(realpath -m -- "$LAB_INPUT")"
DB_PORT="${RC_MARIADB_PORT:-33117}"
DATABASE="${RC_BROWSER_DB:-taxnest_rc_browser}"
STATE_ROOT="$SAFE_RUNTIME/browser-state"
FIXTURE="$STATE_ROOT/fixture.json"; SERVER_PID="$STATE_ROOT/php-server.pid"; SERVER_LOG="$STATE_ROOT/php-server.log"
BROWSER_PORT="${RC_BROWSER_PORT:-5911}"
STATE_RECORD="$STATE_BASE/browser-fixture.state"
STATE_BASE_RE='^/tmp/taxnest-rc-browser-[0-9]+(-[A-Za-z0-9_.-]+)?$'
LAB_ROOT_RE='^/tmp/taxnest-rc-mariadb-browser-[0-9]+$'
ACTION="${1:-}"
fail(){ printf 'rc-browser-fixture: %s\n' "$*" >&2; exit 2; }
if [[ "$ACTION" == run || "$ACTION" == resume || "$ACTION" == serve || "$ACTION" == stop ]]; then
  if [[ -e "$STATE_RECORD" || -L "$STATE_RECORD" ]]; then
    [[ -f "$STATE_RECORD" && ! -L "$STATE_RECORD" ]] || fail 'browser fixture state record is unsafe'
    persisted_lab_root="$(awk -F= '
      $1 == "mariadb_root" { count++; value=substr($0, index($0, "=") + 1) }
      END { if (count != 1) exit 1; print value }
    ' "$STATE_RECORD")" || fail 'browser fixture state record has no unique MariaDB root'
    [[ "$persisted_lab_root" == /* && "$persisted_lab_root" =~ $LAB_ROOT_RE && ! -L "$persisted_lab_root" ]] ||
      fail 'persisted MariaDB root is outside the exact isolated allowlist'
    LAB_ROOT="$(realpath -m -- "$persisted_lab_root")"
  fi
fi
[[ "$STATE_INPUT" == /* && "$LAB_INPUT" == /* && ! -L "$STATE_INPUT" && ! -L "$LAB_INPUT" ]] || fail 'isolated paths required'
[[ "$STATE_BASE" =~ $STATE_BASE_RE && "$LAB_ROOT" =~ $LAB_ROOT_RE && "$STATE_ROOT" == "$STATE_BASE/safe-runtime/browser-state" ]] || fail 'isolated paths required'
[[ "$DATABASE" == taxnest_rc_browser && "$BROWSER_PORT" == 5911 ]] || fail 'exact browser database and port required'
assert_no_symlink() {
  local path="$1" label="$2"
  [[ ! -L "$path" ]] || fail "$label must not be a symlink"
}
assert_state_tree() {
  assert_no_symlink "$STATE_BASE" 'browser state root'
  assert_no_symlink "$SAFE_RUNTIME" 'browser safe runtime'
  assert_no_symlink "$STATE_ROOT" 'browser fixture state'
  if [[ -d "$STATE_BASE" ]]; then
    local link
    link="$(find -P "$STATE_BASE" -type l -print -quit)" || fail 'cannot inspect browser state symlinks'
    [[ -z "$link" ]] || fail "browser state contains symlink: $link"
  fi
}
assert_lab_tree() {
  assert_no_symlink "$LAB_ROOT" 'MariaDB root'
  if [[ -d "$LAB_ROOT" ]]; then
    local link
    link="$(find -P "$LAB_ROOT" -type l -print -quit)" || fail 'cannot inspect MariaDB root symlinks'
    [[ -z "$link" ]] || fail "MariaDB root contains symlink: $link"
  fi
}
assert_socket() {
  assert_lab_tree
  [[ "$SOCKET" == "$LAB_ROOT/run/mariadb.sock" && -S "$SOCKET" && ! -L "$SOCKET" ]] ||
    fail 'exact isolated MariaDB socket is required'
}
assert_fixture() {
  [[ "$FIXTURE" == "$STATE_ROOT/fixture.json" && -f "$FIXTURE" && ! -L "$FIXTURE" ]] ||
    fail 'exact isolated fixture target is required'
}
write_state() {
  local temporary="$STATE_RECORD.tmp.$$"
  assert_state_tree
  assert_no_symlink "$STATE_RECORD" 'browser fixture state record'
  assert_no_symlink "$temporary" 'temporary browser fixture state record'
  [[ ! -e "$temporary" ]] || fail 'temporary browser fixture state record already exists'
  umask 077
  {
    printf 'version=1\n'
    printf 'state_base=%s\n' "$STATE_BASE"
    printf 'safe_runtime=%s\n' "$SAFE_RUNTIME"
    printf 'state_root=%s\n' "$STATE_ROOT"
    printf 'mariadb_root=%s\n' "$LAB_ROOT"
    printf 'mariadb_port=%s\n' "$DB_PORT"
    printf 'socket=%s\n' "$SOCKET"
    printf 'fixture=%s\n' "$FIXTURE"
    printf 'server_pid=%s\n' "$SERVER_PID"
    printf 'database=%s\n' "$DATABASE"
    printf 'browser_port=%s\n' "$BROWSER_PORT"
  } >"$temporary"
  chmod 600 "$temporary"
  mv -f -- "$temporary" "$STATE_RECORD"
}
load_state() {
  local key value
  local state_version='' state_base='' safe_runtime='' state_root='' mariadb_root=''
  local mariadb_port='' socket='' fixture='' server_pid='' database='' browser_port=''
  declare -A seen=()
  assert_state_tree
  [[ -f "$STATE_RECORD" && ! -L "$STATE_RECORD" ]] || fail 'setup state record is absent or unsafe'
  while IFS='=' read -r key value || [[ -n "$key$value" ]]; do
    [[ -n "$key" && -n "$value" && "$key" =~ ^[a-z_]+$ ]] || fail 'malformed browser fixture state record'
    [[ -z "${seen[$key]+present}" ]] || fail 'duplicate browser fixture state record key'
    seen["$key"]=1
    case "$key" in
      version) state_version="$value" ;;
      state_base) state_base="$value" ;;
      safe_runtime) safe_runtime="$value" ;;
      state_root) state_root="$value" ;;
      mariadb_root) mariadb_root="$value" ;;
      mariadb_port) mariadb_port="$value" ;;
      socket) socket="$value" ;;
      fixture) fixture="$value" ;;
      server_pid) server_pid="$value" ;;
      database) database="$value" ;;
      browser_port) browser_port="$value" ;;
      *) fail "unknown browser fixture state record key: $key" ;;
    esac
  done <"$STATE_RECORD"
  [[ "$state_version" == 1 && "$state_base" == "$STATE_BASE" && "$safe_runtime" == "$SAFE_RUNTIME" &&
    "$state_root" == "$STATE_ROOT" && "$mariadb_root" == "$LAB_ROOT" &&
    "$mariadb_port" =~ ^[0-9]+$ && "$mariadb_port" == "$DB_PORT" &&
    "$socket" == "$LAB_ROOT/run/mariadb.sock" && "$fixture" == "$STATE_ROOT/fixture.json" &&
    "$server_pid" == "$STATE_ROOT/php-server.pid" && "$database" == "$DATABASE" &&
    "$browser_port" == "$BROWSER_PORT" ]] || fail 'browser fixture state does not match this isolated contract'
  SOCKET="$socket"; FIXTURE="$fixture"; SERVER_PID="$server_pid"
  assert_socket
  assert_fixture
}
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
ensure_fixture_storage_link() {
  local public_storage="$ROOT/public/storage" fixture_storage="$ROOT/storage/app/public"
  [[ -r "$fixture_storage/app-updates/return-elaan-fbr.png" ]] || fail 'fresh fixture announcement image is absent'
  if [[ -e "$public_storage" || -L "$public_storage" ]]; then
    [[ -L "$public_storage" && "$(realpath -m "$public_storage")" == "$fixture_storage" ]] || fail 'public storage target is not this isolated worktree'
  else
    ln -s ../storage/app/public "$public_storage"
  fi
  [[ -r "$public_storage/app-updates/return-elaan-fbr.png" ]] || fail 'fixture announcement image is not browser-readable'
}
start_server() {
  assert_state_tree
  assert_no_symlink "$SERVER_PID" 'PHP server PID target'
  rm -f "$SERVER_PID"
  safe_browser php artisan serve --host=127.0.0.1 --port="$BROWSER_PORT" </dev/null >"$SERVER_LOG" 2>&1 & echo "$!" >"$SERVER_PID"
  for _ in $(seq 1 30); do curl --fail --silent --max-time 1 "http://127.0.0.1:$BROWSER_PORT/" >/dev/null && return; sleep 1; done
  fail 'loopback PHP server did not become ready'
}
setup() {
  mkdir -p "$STATE_ROOT"; chmod 700 "$STATE_BASE" "$SAFE_RUNTIME" "$STATE_ROOT"; assert_state_tree
  assert_no_symlink "$STATE_RECORD" 'browser fixture state record'
  resolve_mariadbd; lab start
  SOCKET="$LAB_ROOT/run/mariadb.sock"; assert_socket; cd "$ROOT"
  CLIENT="$(lab env | awk -F= '$1=="RC_MARIADB_CLIENT"{print substr($0,index($0,"=")+1)}')"
  "$CLIENT" --protocol=socket --socket="$SOCKET" -uroot -e "DROP DATABASE IF EXISTS \`$DATABASE\`; CREATE DATABASE \`$DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  safe_browser env RC_BROWSER_FIXTURE_FRESH=1 php artisan migrate --force --no-interaction
  safe_browser env RC_BROWSER_FIXTURE_FRESH=1 php scripts/rc-browser-fixture-seed.php
  safe_browser php scripts/rc-di-browser-fixture.php
  ensure_fixture_storage_link
  start_server
  assert_fixture
  write_state
  printf 'RC_BROWSER_FIXTURE=%s\n' "$FIXTURE"
}
resume() {
  mkdir -p "$STATE_ROOT"; chmod 700 "$STATE_BASE" "$SAFE_RUNTIME" "$STATE_ROOT"; assert_state_tree
  if [[ -e "$STATE_RECORD" || -L "$STATE_RECORD" ]]; then load_state; fi
  resolve_mariadbd; lab start
  SOCKET="$LAB_ROOT/run/mariadb.sock"; assert_socket
  [[ -d "$LAB_ROOT/data/$DATABASE" ]] || fail 'resume requires existing isolated database data'
  cd "$ROOT"; safe_browser php scripts/rc-browser-fixture-resume.php; safe_browser php scripts/rc-di-browser-fixture.php; ensure_fixture_storage_link; start_server
  assert_fixture
  write_state
  printf 'RC_BROWSER_FIXTURE=%s\n' "$FIXTURE"
}
run() {
  load_state
  [[ -s "$SERVER_PID" && ! -L "$SERVER_PID" ]] || fail 'run requires setup or resume'
  local server_pid
  server_pid="$(cat "$SERVER_PID")"
  [[ "$server_pid" =~ ^[0-9]+$ ]] || fail 'browser PHP server PID record is malformed'
  resolve_mariadbd; lab start; assert_socket
  kill -0 "$server_pid" 2>/dev/null || fail 'loopback PHP server is not running'
  cd "$ROOT"
  safe_browser env BASE_URL="http://127.0.0.1:$BROWSER_PORT" RC_BROWSER_FIXTURE="$FIXTURE" node scripts/rc-browser-acceptance.mjs
}
serve() { resume; wait "$(cat "$SERVER_PID")"; }
stop() {
  if [[ -s "$SERVER_PID" && ! -L "$SERVER_PID" ]]; then
    local server_pid
    server_pid="$(cat "$SERVER_PID")"
    [[ "$server_pid" =~ ^[0-9]+$ ]] && kill "$server_pid" 2>/dev/null || true
  fi
  rm -rf "$STATE_BASE"
  resolve_mariadbd
  lab stop || true
}
case "${1:-}" in setup)setup;; resume)resume;; run)run;; serve)serve;; stop)stop;; *) fail 'usage: setup|resume|serve|run|stop';; esac