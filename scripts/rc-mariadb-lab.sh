#!/usr/bin/env bash
# Isolated RC-only MariaDB control. Never reads application .env.
set -Eeuo pipefail

ROOT="$(realpath -m "${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-${UID}}")"
PORT="${RC_MARIADB_PORT:-33116}"
SERVER="${RC_MARIADBD:-${RC_MARIADB_SERVER:-}}"
if [[ -z "$SERVER" ]]; then SERVER="$(command -v mariadbd 2>/dev/null || command -v mysqld 2>/dev/null || true)"; fi
SERVER_DIR="$(dirname "$SERVER")"
# Nix bundles tools beside the server; distribution/container packages split
# mariadbd into sbin and the client tools into bin. Explicit overrides must
# still fail closed rather than silently falling back to another installation.
resolve_tool() {
    local override="$1" name="$2"
    if [[ -n "$override" ]]; then
        printf '%s\n' "$override"
    elif [[ -x "$SERVER_DIR/$name" ]]; then
        printf '%s\n' "$SERVER_DIR/$name"
    else
        command -v "$name" 2>/dev/null || true
    fi
}
CLIENT="$(resolve_tool "${RC_MARIADB_CLIENT:-}" mariadb)"
ADMIN="$(resolve_tool "${RC_MARIADB_ADMIN:-}" mariadb-admin)"
INSTALL_DB="$(resolve_tool "${RC_MARIADB_INSTALL_DB:-}" mariadb-install-db)"
DATA="$ROOT/data"; RUN="$ROOT/run"; LOG="$ROOT/log"; SOCKET="$RUN/mariadb.sock"; PID="$RUN/mariadb.pid"; CNF="$ROOT/my.cnf"

fail() { printf 'rc-mariadb-lab: %s\n' "$*" >&2; exit 1; }
[[ "$ROOT" == /tmp/taxnest-rc-mariadb-* ]] || fail "refusing root outside /tmp/taxnest-rc-mariadb-*"
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT >= 1024 && PORT <= 65535 && PORT != 9000 )) || fail "unsafe port"
[[ "$PORT" != 33117 || "$ROOT" == /tmp/taxnest-rc-mariadb-browser-* ]] || fail "33117 is reserved for the exact browser fixture"
[[ -x "$SERVER" && -x "$CLIENT" && -x "$ADMIN" && -x "$INSTALL_DB" ]] || fail "genuine MariaDB 10.6 server/client/admin/install-db binaries unavailable"
if [[ "${RC_MARIADB_REQUIRE_EGRESS_GUARD:-0}" == 1 ]]; then
    [[ -r "${RC_MARIADB_LD_PRELOAD:-}" ]] || fail 'required loopback-only egress guard is unavailable'
fi
guarded() {
    if [[ -n "${RC_MARIADB_LD_PRELOAD:-}" ]]; then
        mkdir -p "$ROOT/home"
        env -i PATH="$PATH" HOME="$ROOT/home" TMPDIR="$RUN" LANG=C LC_ALL=C TZ=UTC NO_PROXY='*' no_proxy='*' \
            LD_PRELOAD="$RC_MARIADB_LD_PRELOAD" "$@"
    else
        "$@"
    fi
}
require_version() {
    local role="$1" binary="$2" output
    output="$(guarded "$binary" --version 2>&1)" || fail "$role --version failed: $output"
    [[ "$output" == *MariaDB* && "$output" =~ (^|[^0-9.])10\.6\.[0-9]+([^0-9.]|$) ]] ||
        fail "refusing non-MariaDB-10.6 $role: $output"
    printf '%s\n' "$output"
}
SERVER_VERSION="$(require_version server "$SERVER")"
CLIENT_VERSION="$(require_version client "$CLIENT")"
ADMIN_VERSION="$(require_version admin "$ADMIN")"
# install-db is an initialization script, not a version-reporting binary.
# Do not invoke it during discovery: even --version can initialize a datadir.

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
alive() { guarded "$ADMIN" --protocol=socket --socket="$SOCKET" -uroot ping --silent >/dev/null 2>&1; }
start() {
    write_config
    if alive; then printf 'MariaDB already ready: %s\n' "$SOCKET"; return; fi
    if [[ ! -d "$DATA/mysql" ]]; then
        guarded "$INSTALL_DB" --defaults-file="$CNF" --datadir="$DATA" --auth-root-authentication-method=normal --skip-test-db
    fi
    guarded "$SERVER" --defaults-file="$CNF" &
    for _ in $(seq 1 80); do alive && { printf 'MariaDB ready: socket=%s port=%s\n' "$SOCKET" "$PORT"; return; }; sleep .25; done
    fail "MariaDB did not become ready; see $LOG/mariadb.err"
}
stop() {
    alive || { printf 'MariaDB stopped\n'; return; }
    guarded "$ADMIN" --protocol=socket --socket="$SOCKET" -uroot shutdown
    printf 'MariaDB stopped: %s\n' "$ROOT"
}
case "${1:-}" in
    start) start ;;
    stop) stop ;;
    status) alive && printf 'ready socket=%s port=%s\n' "$SOCKET" "$PORT" || printf 'stopped socket=%s port=%s\n' "$SOCKET" "$PORT" ;;
    env) printf 'RC_MARIADB_CLIENT=%s\nRC_MARIADB_SOCKET=%s\nRC_MARIADB_PORT=%s\nRC_MARIADB_SERVER_VERSION=%s\n' "$CLIENT" "$SOCKET" "$PORT" "$SERVER_VERSION" ;;
    *) fail 'usage: start|stop|status|env' ;;
esac