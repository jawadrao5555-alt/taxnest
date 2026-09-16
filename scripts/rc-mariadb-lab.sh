#!/usr/bin/env bash
# Isolated RC-only MariaDB control. Never reads application .env.
set -Eeuo pipefail

ROOT="$(realpath -m "${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-${UID}}")"
PORT="${RC_MARIADB_PORT:-33116}"
SERVER="${RC_MARIADBD:-${RC_MARIADB_SERVER:-}}"
if [[ -z "$SERVER" ]]; then SERVER="$(command -v mariadbd 2>/dev/null || command -v mysqld 2>/dev/null || true)"; fi
CLIENT="${RC_MARIADB_CLIENT:-$(command -v mariadb 2>/dev/null || command -v mysql 2>/dev/null || true)}"
ADMIN="${RC_MARIADB_ADMIN:-$(command -v mariadb-admin 2>/dev/null || command -v mysqladmin 2>/dev/null || true)}"
DATA="$ROOT/data"; RUN="$ROOT/run"; LOG="$ROOT/log"; SOCKET="$RUN/mariadb.sock"; PID="$RUN/mariadb.pid"; CNF="$ROOT/my.cnf"

fail() { printf 'rc-mariadb-lab: %s\n' "$*" >&2; exit 1; }
[[ "$ROOT" == /tmp/taxnest-rc-mariadb-* ]] || fail "refusing root outside /tmp/taxnest-rc-mariadb-*"
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT >= 1024 && PORT <= 65535 && PORT != 9000 )) || fail "unsafe port"
[[ "$PORT" != 33117 || "$ROOT" == /tmp/taxnest-rc-mariadb-browser-* ]] || fail "33117 is reserved for the exact browser fixture"
[[ -x "$SERVER" && -x "$CLIENT" && -x "$ADMIN" ]] || fail "MariaDB 10.6 binaries unavailable"

write_config() {
    mkdir -p "$ROOT" "$RUN" "$LOG"
    cat >"$CNF" <<EOF
[mysqld]
datadir=$DATA
socket=$SOCKET
port=$PORT
bind-address=127.0.0.1
pid-file=$PID
log-error=$LOG/mariadb.err
skip-name-resolve
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
sql_mode=STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION
EOF
}
alive() { "$ADMIN" --protocol=socket --socket="$SOCKET" -uroot ping --silent >/dev/null 2>&1; }
start() {
    write_config
    if alive; then printf 'MariaDB already ready: %s\n' "$SOCKET"; return; fi
    if [[ ! -d "$DATA/mysql" ]]; then
        "$SERVER" --defaults-file="$CNF" --initialize-insecure
    fi
    "$SERVER" --defaults-file="$CNF" &
    for _ in $(seq 1 80); do alive && { printf 'MariaDB ready: socket=%s port=%s\n' "$SOCKET" "$PORT"; return; }; sleep .25; done
    fail "MariaDB did not become ready; see $LOG/mariadb.err"
}
stop() {
    alive || { printf 'MariaDB stopped\n'; return; }
    "$ADMIN" --protocol=socket --socket="$SOCKET" -uroot shutdown
    printf 'MariaDB stopped: %s\n' "$ROOT"
}
case "${1:-}" in
    start) start ;;
    stop) stop ;;
    status) alive && printf 'ready socket=%s port=%s\n' "$SOCKET" "$PORT" || printf 'stopped socket=%s port=%s\n' "$SOCKET" "$PORT" ;;
    env) printf 'RC_MARIADB_CLIENT=%s\nRC_MARIADB_SOCKET=%s\nRC_MARIADB_PORT=%s\n' "$CLIENT" "$SOCKET" "$PORT" ;;
    *) fail 'usage: start|stop|status|env' ;;
esac