#!/usr/bin/env bash
# Regression coverage for the two-process browser fixture handoff.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE_HELPER="$ROOT/scripts/rc-browser-fixture.sh"
TOOLS="$(mktemp -d "${TMPDIR:-/tmp}/taxnest-rc-browser-state-check.XXXXXX")"
RUN_ID="$((1000000000 + $$))"
STATE_BASE="/tmp/taxnest-rc-browser-${RUN_ID}-state-check"
LAB_ROOT="/tmp/taxnest-rc-mariadb-browser-${RUN_ID}"
STATE_ROOT="$STATE_BASE/safe-runtime/browser-state"
SOCKET="$LAB_ROOT/run/mariadb.sock"
FIXTURE="$STATE_ROOT/fixture.json"
SERVER_PID_FILE="$STATE_ROOT/php-server.pid"
SOCKET_PID=''
SERVER_PID=''
MARKER="$TOOLS/node-ran"

cleanup() {
    [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
    [[ -n "$SOCKET_PID" ]] && kill "$SOCKET_PID" 2>/dev/null || true
    rm -rf "$TOOLS" "$STATE_BASE" "$LAB_ROOT"
}
trap cleanup EXIT

fail() { printf 'rc-browser-fixture-state-check: %s\n' "$*" >&2; exit 1; }
pass() { printf 'PASS: %s\n' "$*"; }

for tool in mariadbd mariadb mariadb-admin mariadb-install-db; do
    cat >"$TOOLS/$tool" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "${1:-}" == --version ]]; then
    printf '%s Ver 10.6.23-MariaDB browser-state fixture\n' "$(basename "$0")"
    exit 0
fi
exit 0
EOF
    chmod 700 "$TOOLS/$tool"
done

cat >"$TOOLS/node" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'node acceptance handoff reached\n' >"$MARKER"
EOF
chmod 700 "$TOOLS/node"

mkdir -p "$STATE_ROOT" "$LAB_ROOT/run"
chmod 700 "$STATE_BASE" "$STATE_ROOT" "$LAB_ROOT"
printf '{"synthetic":true}\n' >"$FIXTURE"
sleep 30 &
SERVER_PID="$!"
printf '%s\n' "$SERVER_PID" >"$SERVER_PID_FILE"

# A real Unix socket is required; a regular file must not satisfy the helper.
php -r '$socket=$argv[1];$server=stream_socket_server("unix://".$socket,$errno,$error);if(!$server){fwrite(STDERR,$error);exit(1);}while(true){@stream_socket_accept($server,1);}' "$SOCKET" &
SOCKET_PID="$!"
for _ in $(seq 1 20); do [[ -S "$SOCKET" ]] && break; sleep .1; done
[[ -S "$SOCKET" && ! -L "$SOCKET" ]] || fail 'test Unix socket did not start'

cat >"$STATE_BASE/browser-fixture.state" <<EOF
version=1
state_base=$STATE_BASE
safe_runtime=$STATE_BASE/safe-runtime
state_root=$STATE_ROOT
mariadb_root=$LAB_ROOT
mariadb_port=33117
socket=$SOCKET
fixture=$FIXTURE
server_pid=$SERVER_PID_FILE
database=taxnest_rc_browser
browser_port=5911
EOF
chmod 600 "$STATE_BASE/browser-fixture.state"

run_helper() {
    env -u RC_MARIADB_ROOT \
        PATH="$TOOLS:$PATH" \
        RC_BROWSER_STATE_ROOT="$STATE_BASE" \
        RC_MARIADB_PORT=33117 \
        RC_BROWSER_PORT=5911 \
        RC_MARIADBD="$TOOLS/mariadbd" \
        RC_MARIADB_CLIENT="$TOOLS/mariadb" \
        RC_MARIADB_ADMIN="$TOOLS/mariadb-admin" \
        RC_MARIADB_INSTALL_DB="$TOOLS/mariadb-install-db" \
        bash "$FIXTURE_HELPER" run >/dev/null
}
expect_failure() {
    local label="$1"
    if run_helper >/dev/null 2>&1; then
        fail "$label was accepted"
    fi
    pass "$label is rejected"
}

run_helper
[[ -s "$MARKER" ]] || fail 'separate run did not reload the persisted fixture target/socket'
pass 'separate run reloads persisted MariaDB root without its original env'

mv "$STATE_BASE/browser-fixture.state" "$STATE_BASE/browser-fixture.state.missing"
expect_failure 'missing browser fixture state'
mv "$STATE_BASE/browser-fixture.state.missing" "$STATE_BASE/browser-fixture.state"

cp "$STATE_BASE/browser-fixture.state" "$STATE_BASE/browser-fixture.state.backup"
sed -i "s|^fixture=.*|fixture=$STATE_ROOT/tampered.json|" "$STATE_BASE/browser-fixture.state"
expect_failure 'tampered fixture target'
mv "$STATE_BASE/browser-fixture.state.backup" "$STATE_BASE/browser-fixture.state"

cp "$STATE_BASE/browser-fixture.state" "$STATE_BASE/browser-fixture.state.backup"
sed -i "s|^socket=.*|socket=$LAB_ROOT/run/wrong.sock|" "$STATE_BASE/browser-fixture.state"
expect_failure 'wrong persisted socket target'
mv "$STATE_BASE/browser-fixture.state.backup" "$STATE_BASE/browser-fixture.state"

kill "$SOCKET_PID" 2>/dev/null || true
SOCKET_PID=''
rm -f "$SOCKET"
: >"$SOCKET"
expect_failure 'non-socket MariaDB target'
rm -f "$SOCKET"
php -r '$socket=$argv[1];$server=stream_socket_server("unix://".$socket,$errno,$error);if(!$server){fwrite(STDERR,$error);exit(1);}while(true){@stream_socket_accept($server,1);}' "$SOCKET" &
SOCKET_PID="$!"
for _ in $(seq 1 20); do [[ -S "$SOCKET" ]] && break; sleep .1; done
[[ -S "$SOCKET" && ! -L "$SOCKET" ]] || fail 'test Unix socket did not restart'

rm -f "$FIXTURE"
ln -s "$TOOLS/outside-fixture" "$FIXTURE"
expect_failure 'fixture symlink'

bash -n "$FIXTURE_HELPER"
node --check "$ROOT/scripts/rc-browser-acceptance.mjs" >/dev/null
php -l "$ROOT/scripts/rc-browser-fixture-seed.php" >/dev/null
php -l "$ROOT/scripts/rc-browser-fixture-resume.php" >/dev/null
php -l "$ROOT/scripts/rc-di-browser-fixture.php" >/dev/null
pass 'browser helper and fixture syntax checks pass'