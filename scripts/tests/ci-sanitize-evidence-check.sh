#!/usr/bin/env bash
# Focused proof of the atomic, text-only CI evidence contract.
set -Eeuo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/taxnest-evidence-test.XXXXXX")"
cleanup() {
    rm -rf -- "$work"
}
trap cleanup EXIT

workspace="$work/workspace"
target="$work/evidence"
mkdir -p \
    "$workspace/.local/recertification/rc-run/failure-diagnostics" \
    "$workspace/.local/recertification/supp-preflight-1" \
    "$workspace/.local/recertification/native_mariadb106_evidence/source" \
    "$workspace/.local/recertification/rc-run/node_modules" \
    "$workspace/.local/recertification/rc-run/cache" \
    "$workspace/.local/recertification/rc-run/source" \
    "$workspace/.local/browser-evidence"

aws_akia="$(printf 'AK%s' 'IA')"
aws_asia="$(printf 'AS%s' 'IA')"
fake_aws_credential="${aws_akia}FAKECREDENTIAL12"
fake_aws_session="${aws_asia}FAKESESSIONKEY12"
fake_sk="$(printf 'sk%s' '-')fake-key-for-redaction-regression"
private_begin="$(printf '%s' '-----BEGIN ')$(printf '%s' 'PRIVATE ')KEY-----"
private_end="$(printf '%s' '-----END ')$(printf '%s' 'PRIVATE ')KEY-----"

cat >"$workspace/.local/recertification/rc-run/failure-diagnostics/logs.txt" <<EOF
{"token":"json_secret_must_not_escape","version":"v22.23.2","error":"keep this error"}
TOKEN=shell_secret_must_not_escape
Authorization: Bearer header_secret_must_not_escape
https://user:url_secret_must_not_escape@example.invalid/path
https://electron.invalid/artifact.zip?keep=version&X-Amz-Credential=$fake_aws_credential&X-Amz-Signature=electron_fake_sig
https://blob.invalid/package.tar.gz?sv=2024&se=2030-01-01&sig=azure_fake_sig
standalone AWS ${aws_akia}FAKEACCESSKEY123 and $fake_aws_session
standalone $fake_sk
$private_begin
private_secret_must_not_escape
$private_end
EOF
printf '{"ok":true,"version":"v22.23.2"}\n' \
    >"$workspace/.local/recertification/rc-run/failure-diagnostics/diagnostic.json"
printf 'PHPUnit error: expected version v22.23.2\n' \
    >"$workspace/.local/recertification/rc-run/recertification.md"
printf '<testsuite><testcase name="version-v22.23.2"><failure>keep error</failure></testcase></testsuite>\n' \
    >"$workspace/.local/recertification/rc-run/recertification.junit.xml"
printf '<testsuite><testcase name="fbr-kot"/></testsuite>\n' \
    >"$workspace/.local/recertification/supp-preflight-1/fbr-kot-timestamp.junit.xml"
printf '<testsuite><testcase name="owner-schema"/></testsuite>\n' \
    >"$workspace/.local/recertification/supp-preflight-1/owner-approval-schema.junit.xml"
printf '{"native":"upgrade-before"}\n' \
    >"$workspace/.local/recertification/native_mariadb106_evidence/upgrade-before.json"
printf '{"native":"summary"}\n' \
    >"$workspace/.local/recertification/native_mariadb106_evidence/native-summary.json"
printf '{"must_not_copy":"source"}\n' \
    >"$workspace/.local/recertification/native_mariadb106_evidence/source/native-summary.json"
printf 'node_modules_secret\n' \
    >"$workspace/.local/recertification/rc-run/node_modules/evil.log"
printf 'cache_secret\n' \
    >"$workspace/.local/recertification/rc-run/cache/evil.log"
printf 'source_secret\n' \
    >"$workspace/.local/recertification/rc-run/source/evil.log"
printf 'browser error version v22.23.2\n' \
    >"$workspace/.local/browser-evidence/diagnostic.txt"
printf '\x89PNG\r\n' >"$workspace/.local/browser-evidence/screenshot.png"
printf 'SECRET_ENV=must_not_be_read\n' >"$workspace/.env"

GITHUB_WORKSPACE="$workspace" GITHUB_JOB=quoted-redactor-test \
    GITHUB_RUN_ID=123 bash "$ROOT/scripts/ci-sanitize-evidence.sh" "$target" >/dev/null
[[ -f "$target/SANITIZED_SUCCESS" ]]
[[ -f "$target/evidence-metadata.txt" ]]
[[ -f "$target/logs/recertification/supp-preflight-1/fbr-kot-timestamp.junit.xml" ]]
[[ -f "$target/logs/recertification/supp-preflight-1/owner-approval-schema.junit.xml" ]]
[[ -f "$target/logs/recertification/native_mariadb106_evidence/upgrade-before.json" ]]
[[ -f "$target/logs/recertification/native_mariadb106_evidence/native-summary.json" ]]
[[ ! -e "$target/logs/recertification/native_mariadb106_evidence/source" ]]
! find "$target" -type f \( -name '*.png' -o -name '*.env' -o -name 'evil.log' \) -print -quit | grep -q .
if grep -R -nE \
    'json_secret_must_not_escape|shell_secret_must_not_escape|header_secret_must_not_escape|url_secret_must_not_escape|electron_fake_sig|azure_fake_sig|AKIAFAKE|ASIAFAKE|sk-fake-key-for-redaction-regression|private_secret_must_not_escape' \
    "$target"; then
    echo "ci-sanitize-evidence-check: secret escaped redaction" >&2
    exit 1
fi
grep -R -Fq \
    'https://electron.invalid/artifact.zip?keep=version&X-Amz-Credential=[REDACTED]&X-Amz-Signature=[REDACTED]' \
    "$target"
grep -R -Fq \
    'https://blob.invalid/package.tar.gz?sv=2024&se=2030-01-01&sig=[REDACTED]' \
    "$target"
grep -R -Fq 'v22.23.2' "$target"
grep -R -Fq 'keep this error' "$target"
grep -Fq 'SANITIZED_SUCCESS' <(find "$target" -maxdepth 1 -type f -printf '%f\n')
grep -Fq 'json_secret_must_not_escape' \
    "$workspace/.local/recertification/rc-run/failure-diagnostics/logs.txt"
[[ -f "$workspace/.local/browser-evidence/screenshot.png" ]]
python3 - "$target/logs/recertification/rc-run/failure-diagnostics/logs.txt" <<'PY'
import json
import pathlib
import sys

line = next(
    line for line in pathlib.Path(sys.argv[1]).read_text().splitlines()
    if line.startswith("{")
)
payload = json.loads(line)
assert payload["token"] == "[REDACTED]"
assert payload["version"] == "v22.23.2"
assert payload["error"] == "keep this error"
PY
python3 -m json.tool \
    "$target/logs/recertification/rc-run/failure-diagnostics/diagnostic.json" >/dev/null

# A mid-publication error must not leave a marker or a partially published
# output directory.  The failpoint is test-only and does not weaken production
# behavior: it exercises the same rollback path as an I/O/read failure.
set +e
RC_SANITIZE_EVIDENCE_FAIL_AFTER=1 \
    GITHUB_WORKSPACE="$workspace" \
    bash "$ROOT/scripts/ci-sanitize-evidence.sh" "$target" >/dev/null 2>"$work/failure"
status=$?
set -e
[[ "$status" -ne 0 ]]
[[ ! -e "$target" ]]
[[ ! -e "$target/SANITIZED_SUCCESS" ]]

echo "ci-sanitize-evidence-check: PASS (quoted JSON, shell, headers, URL credentials, private keys, atomic failure)"