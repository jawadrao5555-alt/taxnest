#!/usr/bin/env bash
# Focused local proof for RC failure diagnostics.
#
# This creates a disposable git source and a fake Composer that fails before
# any dependency operation can reach the network.  It verifies that a nested
# bootstrap log is surfaced only after sanitization and that the original
# non-zero exit is retained.
set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/taxnest-rc-diagnostics.XXXXXX")"
cleanup() {
    rm -rf -- "$work"
}
trap cleanup EXIT

source="$work/source"
bin="$work/bin"
runtime="$work/runtime"
mkdir -p "$source/pra-agent" "$source/agent-realtime-gateway" "$bin" "$runtime"
mkdir -p "$source/scripts"
cp "$ROOT/scripts/npm-bootstrap-pinned.sh" "$source/scripts/npm-bootstrap-pinned.sh"
chmod 700 "$source/scripts/npm-bootstrap-pinned.sh"
git -C "$source" init -q
git -C "$source" config user.email rc-diagnostics@example.invalid
git -C "$source" config user.name rc-diagnostics
for lock in \
    composer.lock \
    package-lock.json \
    pra-agent/package-lock.json \
    agent-realtime-gateway/package-lock.json; do
    mkdir -p "$source/$(dirname "$lock")"
    printf '{}\n' >"$source/$lock"
done
git -C "$source" add .
git -C "$source" commit -qm initial

fake_github_token="$(printf 'gh%s' 'p_')diagnostic_secret_must_not_escape"
cat >"$bin/composer" <<EOF
#!/usr/bin/env sh
printf 'composer dependency failure\n'
printf 'Authorization: Bearer %s\n' '$fake_github_token'
printf 'APP_KEY=base64:diagnostic_secret_must_not_escape\n'
exit 23
EOF
chmod 700 "$bin/composer"

set +e
PATH="$bin:$PATH" bash "$ROOT/scripts/rc-bootstrap.sh" \
    --source "$source" --working-tree --runtime "$runtime" \
    >"$work/stdout" 2>"$work/stderr"
status=$?
set -e
[[ "$status" -eq 23 ]] || {
    echo "rc-failure-observability-check: expected bootstrap exit 23, got $status" >&2
    cat "$work/stderr" >&2
    exit 1
}

diagnostic="$runtime/failure-diagnostics"
[[ -s "$diagnostic/diagnostic.json" ]]
[[ -s "$diagnostic/logs.txt" ]]
grep -Fq 'composer dependency failure' "$diagnostic/logs.txt"
grep -Fq '[REDACTED]' "$diagnostic/logs.txt"
if grep -Fq "$fake_github_token" "$diagnostic/logs.txt" \
    || grep -Fq 'diagnostic_secret_must_not_escape' "$diagnostic/logs.txt"; then
    echo "rc-failure-observability-check: secret appeared in sanitized diagnostics" >&2
    exit 1
fi
grep -Fq 'logs/composer-install.log' "$diagnostic/logs.txt"
grep -Fq "RC_FAILURE_ARTIFACT_DIR=$diagnostic" "$work/stderr"
grep -Fq "RC_FAILURE_SUMMARY=$diagnostic/diagnostic.json" "$work/stderr"
grep -Fq "RC_FAILURE_LOG=$diagnostic/logs.txt" "$work/stderr"

python3 - "$diagnostic/diagnostic.json" <<'PY'
import json
import pathlib
import sys

summary = json.loads(pathlib.Path(sys.argv[1]).read_text())
assert summary["kind"] == "rc-failure-diagnostics"
assert summary["exit"] == 23
assert summary["logs"]
assert summary["artifacts"]["sanitized_logs"] == "failure-diagnostics/logs.txt"
print("rc-failure-observability-check: sanitized summary verified")
PY
echo "rc-failure-observability-check: PASS (local synthetic bootstrap failure only)"