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
CACHE_ROOT="$STATE_BASE/playwright-cache"
CACHE_EXECUTABLE="$CACHE_ROOT/chromium-1234/chrome-linux64/chrome"
CACHE_EXECUTABLE_TARGET="$CACHE_ROOT/chromium-1234/chrome-linux64/chrome-real"
CACHE_FFMPEG="$CACHE_ROOT/ffmpeg-1011/ffmpeg-linux"
SOCKET_PID=''
SERVER_PID=''
MARKER="$TOOLS/node-ran"
REAL_NODE="$(command -v node)"
BROWSER_TMPDIR="$(mktemp -d /tmp/rcpw.XXXXXX)"
BROWSER_TMPDIR_MARKER="$BROWSER_TMPDIR/.taxnest-rc-browser-tmpdir"
BROWSER_TMPDIR_INODE=''
BROWSER_TMPDIR_MARKER_INODE=''

cleanup() {
    [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
    [[ -n "$SOCKET_PID" ]] && kill "$SOCKET_PID" 2>/dev/null || true
    rm -rf "$TOOLS" "$STATE_BASE" "$LAB_ROOT"
    if [[ -n "$BROWSER_TMPDIR" && -d "$BROWSER_TMPDIR" && ! -L "$BROWSER_TMPDIR" ]]; then
        rm -rf "$BROWSER_TMPDIR"
    fi
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
printf 'node acceptance handoff reached\nHOME=%s\nTMPDIR=%s\nPLAYWRIGHT_BROWSERS_PATH=%s\n' "\${HOME:-}" "\${TMPDIR:-}" "\${PLAYWRIGHT_BROWSERS_PATH:-}" >"$MARKER"
EOF
chmod 700 "$TOOLS/node"

mkdir -p "$STATE_ROOT" "$LAB_ROOT/run" "$(dirname "$CACHE_EXECUTABLE")"
mkdir -p "$(dirname "$CACHE_FFMPEG")"
chmod 700 "$STATE_BASE" "$STATE_ROOT" "$LAB_ROOT"
chmod 700 "$BROWSER_TMPDIR"
printf 'version=1\nstate_base=%s\nbrowser_tmpdir=%s' "$STATE_BASE" "$BROWSER_TMPDIR" >"$BROWSER_TMPDIR_MARKER"
chmod 600 "$BROWSER_TMPDIR_MARKER"
BROWSER_TMPDIR_INODE="$(stat -c '%i' "$BROWSER_TMPDIR")"
BROWSER_TMPDIR_MARKER_INODE="$(stat -c '%i' "$BROWSER_TMPDIR_MARKER")"
printf '#!/bin/sh\nexit 0\n' >"$CACHE_EXECUTABLE_TARGET"
chmod 700 "$CACHE_EXECUTABLE_TARGET"
ln -s "$CACHE_EXECUTABLE_TARGET" "$CACHE_EXECUTABLE"
printf '#!/bin/sh\nexit 0\n' >"$CACHE_FFMPEG"
chmod 700 "$CACHE_FFMPEG"
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
playwright_browsers_path=$CACHE_ROOT
browser_tmpdir=$BROWSER_TMPDIR
browser_tmpdir_inode=$BROWSER_TMPDIR_INODE
browser_tmpdir_marker_inode=$BROWSER_TMPDIR_MARKER_INODE
EOF
chmod 600 "$STATE_BASE/browser-fixture.state"

"$REAL_NODE" "$ROOT/scripts/lib/playwright-cache.mjs" "$CACHE_ROOT" >/dev/null ||
    fail 'legitimate in-revision executable symlink/cache contract was rejected'
pass 'legitimate in-revision executable symlink is accepted in dedicated cache'

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
grep -Fq "PLAYWRIGHT_BROWSERS_PATH=$CACHE_ROOT" "$MARKER" ||
  fail 'separate run did not preserve the persisted Playwright cache path'
grep -Fq "HOME=$STATE_BASE/safe-runtime/home" "$MARKER" ||
  fail 'separate run did not use the isolated HOME'
grep -Fq "TMPDIR=$BROWSER_TMPDIR" "$MARKER" ||
  fail 'separate run did not use the short isolated Chromium TMPDIR'
pass 'separate run preserves cache A while HOME is isolated to B'

chmod 755 "$BROWSER_TMPDIR"
expect_failure 'non-0700 Chromium TMPDIR'
chmod 700 "$BROWSER_TMPDIR"

TMPDIR_SAVED="${BROWSER_TMPDIR}.saved"
mv "$BROWSER_TMPDIR" "$TMPDIR_SAVED"
ln -s "$TMPDIR_SAVED" "$BROWSER_TMPDIR"
expect_failure 'symlinked Chromium TMPDIR'
rm "$BROWSER_TMPDIR"
mv "$TMPDIR_SAVED" "$BROWSER_TMPDIR"

TMPDIR_MARKER_CONTENT="$(cat "$BROWSER_TMPDIR_MARKER")"
printf 'tampered\n' >"$BROWSER_TMPDIR_MARKER"
expect_failure 'tampered Chromium TMPDIR marker'
printf '%s' "$TMPDIR_MARKER_CONTENT" >"$BROWSER_TMPDIR_MARKER"

TMPDIR_REPLACED="${BROWSER_TMPDIR}.replaced"
mv "$BROWSER_TMPDIR" "$TMPDIR_REPLACED"
mkdir "$BROWSER_TMPDIR"
chmod 700 "$BROWSER_TMPDIR"
printf '%s' "$TMPDIR_MARKER_CONTENT" >"$BROWSER_TMPDIR_MARKER"
chmod 600 "$BROWSER_TMPDIR_MARKER"
expect_failure 'replaced Chromium TMPDIR inode'
rm -rf "$BROWSER_TMPDIR"
mv "$TMPDIR_REPLACED" "$BROWSER_TMPDIR"
pass 'Chromium TMPDIR ownership, mode, symlink, marker, and inode boundaries are rejected'

expect_runner_failure() {
    local label="$1" cache_path="$2"
    if bash "$ROOT/scripts/rc-safe-run" --browser --runtime "$STATE_BASE/safe-runtime" \
        --mariadb-root "$LAB_ROOT" --playwright-browsers-path "$cache_path" \
        --browser-tmpdir "$BROWSER_TMPDIR" -- true >/dev/null 2>&1; then
        fail "$label was accepted"
    fi
    pass "$label is rejected"
}
expect_runner_failure 'non-canonical runtime-associated Playwright cache path' \
    "$STATE_BASE/safe-runtime/../playwright-cache"
ln -s "$CACHE_ROOT" "$STATE_BASE/cache-link"
expect_runner_failure 'symlinked runtime-associated Playwright cache path' "$STATE_BASE/cache-link"
rm -f "$STATE_BASE/cache-link"
mv "$CACHE_ROOT" "$STATE_BASE/playwright-cache-missing"
expect_runner_failure 'missing exact runtime-associated Playwright cache' "$CACHE_ROOT"
mv "$STATE_BASE/playwright-cache-missing" "$CACHE_ROOT"
mv "$CACHE_ROOT/chromium-1234" "$CACHE_ROOT/chromium-9999"
expect_runner_failure 'stale exact runtime-associated Playwright cache' "$CACHE_ROOT"
mv "$CACHE_ROOT/chromium-9999" "$CACHE_ROOT/chromium-1234"
chmod 600 "$CACHE_FFMPEG"
expect_runner_failure 'non-executable exact runtime-associated Playwright cache' "$CACHE_ROOT"
chmod 700 "$CACHE_FFMPEG"
rm -f "$CACHE_EXECUTABLE"
ln -s "$TOOLS/outside-browser" "$CACHE_EXECUTABLE"
expect_runner_failure 'escaping exact runtime-associated Playwright cache' "$CACHE_ROOT"
rm -f "$CACHE_EXECUTABLE"
ln -s "$CACHE_EXECUTABLE_TARGET" "$CACHE_EXECUTABLE"

expect_cache_failure() {
    local label="$1"
    if "$REAL_NODE" "$ROOT/scripts/lib/playwright-cache.mjs" "$CACHE_ROOT" >/dev/null 2>&1; then
        fail "$label was accepted"
    fi
    pass "$label is rejected"
}

mv "$CACHE_ROOT/ffmpeg-1011" "$CACHE_ROOT/ffmpeg-9999"
expect_cache_failure 'stale Playwright FFmpeg revision'
mv "$CACHE_ROOT/ffmpeg-9999" "$CACHE_ROOT/ffmpeg-1011"
chmod 600 "$CACHE_FFMPEG"
expect_cache_failure 'non-executable Playwright FFmpeg executable'
chmod 700 "$CACHE_FFMPEG"

ln -s "$TOOLS/outside-browser" "$CACHE_ROOT/chromium-1234/unexpected-link"
expect_cache_failure 'arbitrary nested Playwright cache symlink'
rm -f "$CACHE_ROOT/chromium-1234/unexpected-link"

mv "$CACHE_ROOT/chromium-1234" "$CACHE_ROOT/chromium-9999"
expect_cache_failure 'stale Playwright revision'
mv "$CACHE_ROOT/chromium-9999" "$CACHE_ROOT/chromium-1234"
rm -f "$CACHE_EXECUTABLE"
expect_cache_failure 'missing Playwright executable'
printf '#!/bin/sh\nexit 0\n' >"$CACHE_EXECUTABLE"
chmod 600 "$CACHE_EXECUTABLE"
expect_cache_failure 'non-executable Playwright executable'
chmod 700 "$CACHE_EXECUTABLE"
rm -f "$CACHE_EXECUTABLE"
ln -s "$TOOLS/outside-browser" "$CACHE_EXECUTABLE"
expect_cache_failure 'escaping Playwright executable symlink'
rm -f "$CACHE_EXECUTABLE"
printf '#!/bin/sh\nexit 0\n' >"$CACHE_EXECUTABLE"
chmod 700 "$CACHE_EXECUTABLE"
mkdir "$CACHE_ROOT/arbitrary-entry"
expect_cache_failure 'arbitrary Playwright cache entry'
rm -rf "$CACHE_ROOT/arbitrary-entry"

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

cp "$STATE_BASE/browser-fixture.state" "$STATE_BASE/browser-fixture.state.backup"
sed -i "s|^browser_tmpdir=.*|browser_tmpdir=/tmp/rcpw.tampered|" "$STATE_BASE/browser-fixture.state"
expect_failure 'tampered persisted Chromium TMPDIR target'
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

cp "$FIXTURE" "$FIXTURE.backup"
rm -f "$FIXTURE"
ln -s "$TOOLS/outside-fixture" "$FIXTURE"
expect_failure 'fixture symlink'
rm -f "$FIXTURE"
mv "$FIXTURE.backup" "$FIXTURE"

bash -n "$FIXTURE_HELPER"
bash -n "$ROOT/scripts/rc-playwright-install.sh"
node --check "$ROOT/scripts/rc-browser-acceptance.mjs" >/dev/null
node --check "$ROOT/scripts/tests/rc-browser-chromium-socket-check.mjs" >/dev/null
node --check "$ROOT/scripts/lib/playwright-cache.mjs" >/dev/null
php -l "$ROOT/scripts/rc-browser-fixture-seed.php" >/dev/null
php -l "$ROOT/scripts/rc-browser-fixture-resume.php" >/dev/null
php -l "$ROOT/scripts/rc-di-browser-fixture.php" >/dev/null
pass 'browser helper and fixture syntax checks pass'

DECOY_TMPDIR="$(mktemp -d /tmp/rcpw.XXXXXX)"
chmod 700 "$DECOY_TMPDIR"
env -u RC_MARIADB_ROOT \
    PATH="$TOOLS:$PATH" \
    RC_BROWSER_STATE_ROOT="$STATE_BASE" \
    RC_MARIADB_PORT=33117 \
    RC_BROWSER_PORT=5911 \
    RC_MARIADBD="$TOOLS/mariadbd" \
    RC_MARIADB_CLIENT="$TOOLS/mariadb" \
    RC_MARIADB_ADMIN="$TOOLS/mariadb-admin" \
    RC_MARIADB_INSTALL_DB="$TOOLS/mariadb-install-db" \
    bash "$FIXTURE_HELPER" stop >/dev/null
[[ ! -e "$BROWSER_TMPDIR" ]] || fail 'validated Chromium TMPDIR was not cleaned'
[[ -d "$DECOY_TMPDIR" && ! -L "$DECOY_TMPDIR" ]] || fail 'unassociated Chromium TMPDIR was cleaned'
rm -rf "$DECOY_TMPDIR"
pass 'cleanup removes only the validated marker/inode-associated Chromium TMPDIR'