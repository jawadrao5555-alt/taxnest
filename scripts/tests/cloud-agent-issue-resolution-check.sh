#!/bin/bash
# Static validation that the DEFAULT Cloud Agent issue-resolution policy
# exists and is wired into the handoff / related docs.
# Does not access production. Does not run Chrome or PHPUnit.
# Usage: bash scripts/tests/cloud-agent-issue-resolution-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

POLICY="$ROOT/docs/ops/cloud-agent-issue-resolution.md"
HAND="$ROOT/CLOUD_AGENT_HANDOFF.md"
DEV="$ROOT/docs/ops/cloud-agent-development.md"
ARCH="$ROOT/docs/ops/cloud-agent-architecture.md"
BROWSER="$ROOT/docs/ops/cloud-agent-local-browser-qa.md"
AGENTS="$ROOT/AGENTS.md"

[ -f "$POLICY" ] && ok "present cloud-agent-issue-resolution.md" || bad "missing issue-resolution policy"
[ -f "$HAND" ] && ok "present CLOUD_AGENT_HANDOFF.md" || bad "missing handoff"
[ -f "$BROWSER" ] && ok "present local-browser-qa.md" || bad "missing browser QA doc"
[ -f "$AGENTS" ] && ok "present AGENTS.md" || bad "missing AGENTS.md"

if [ -f "$POLICY" ]; then
  for needle in \
    'DEFAULT workflow' \
    'Never deploy production' \
    'Reproduce locally' \
    'local MariaDB' \
    'Chrome' \
    'root cause' \
    'original' \
    'fake' \
    'php artisan test' \
    'Evidence required' \
    'DONE' \
    'taxnest.pk' \
    'live-screen-smoke' \
    'cursor/'
  do
    grep -qi "$needle" "$POLICY" && ok "policy mentions: $needle" || bad "policy missing: $needle"
  done

  # Safety: policy must forbid production access / live QA path
  grep -qi 'MUST NEVER' "$POLICY" && ok "policy has MUST NEVER safety block" || bad "policy missing MUST NEVER block"
  if grep -qiE 'ssh.*(production|vps)|deploy production from cloud|use live-screen-smoke' "$POLICY" \
     | grep -qiE 'should|must|required to ssh|required to deploy'; then
    : # soft — only fail if it instructs production SSH
  fi
  if grep -E '(BASE_URL=https://taxnest\.pk|LIVE_URL=https://taxnest\.pk)' "$POLICY" | grep -vq 'refuse\|never\|not\|forbid\|block'; then
    bad "policy appears to instruct hitting taxnest.pk"
  else
    ok "policy does not instruct production BASE_URL"
  fi
fi

if [ -f "$HAND" ]; then
  grep -q 'cloud-agent-issue-resolution.md' "$HAND" \
    && ok "handoff links issue-resolution policy" \
    || bad "handoff must link docs/ops/cloud-agent-issue-resolution.md"
  grep -qi 'default.*issue\|issue-resolution\|Issue resolution' "$HAND" \
    && ok "handoff treats issue-resolution as default" \
    || bad "handoff should call out default issue-resolution"
  grep -qiE 'DONE|FIXED|VERIFIED' "$HAND" \
    && ok "handoff mentions DONE/FIXED/VERIFIED discipline" \
    || bad "handoff missing language discipline for DONE/FIXED/VERIFIED"
  grep -qi 'never.*deploy production\|Never deploy production' "$HAND" \
    && ok "handoff forbids production deploy" \
    || bad "handoff missing production deploy forbid"
fi

if [ -f "$AGENTS" ]; then
  grep -q 'cloud-agent-issue-resolution.md' "$AGENTS" \
    && ok "AGENTS.md links issue-resolution policy" \
    || bad "AGENTS.md must link issue-resolution policy"
  grep -qi 'never.*production\|MUST NEVER\|do not deploy' "$AGENTS" \
    && ok "AGENTS.md states production boundary" \
    || bad "AGENTS.md missing production boundary"
fi

if [ -f "$DEV" ]; then
  grep -q 'cloud-agent-issue-resolution.md' "$DEV" \
    && ok "development.md links issue-resolution" \
    || bad "development.md should link issue-resolution"
fi

if [ -f "$ARCH" ]; then
  grep -q 'cloud-agent-issue-resolution.md' "$ARCH" \
    && ok "architecture.md links issue-resolution" \
    || bad "architecture.md should link issue-resolution"
fi

if [ -f "$BROWSER" ]; then
  grep -q 'cloud-agent-issue-resolution.md' "$BROWSER" \
    && ok "browser QA doc links issue-resolution" \
    || bad "browser QA doc should link issue-resolution"
fi

# Policy must not contain secret-like blobs
if [ -f "$POLICY" ]; then
  if grep -qiE 'BEGIN (OPENSSH|RSA|EC) PRIVATE KEY|sk-live|AKIA[0-9A-Z]{16}' "$POLICY"; then
    bad "policy contains secret-like material"
  else
    ok "policy has no private-key/AWS-like blobs"
  fi
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "cloud-agent-issue-resolution-check: ALL PASS"
  exit 0
fi
echo "cloud-agent-issue-resolution-check: $FAILS FAIL(S)" >&2
exit 1
