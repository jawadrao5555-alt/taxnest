#!/bin/bash
# Static validation for issue→live autonomous workflow wiring.
# Does NOT SSH, deploy, contact production, or require secrets.
# Usage: bash scripts/tests/issue-to-live-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

DOC="$ROOT/docs/ops/cloud-agent-issue-to-live.md"
VERIFY="$ROOT/scripts/ci-live-verify.sh"
OBSERVE="$ROOT/scripts/cloud-issue-to-live-observe.sh"
DP="$ROOT/.github/workflows/deploy-production.yml"
HAND="$ROOT/CLOUD_AGENT_HANDOFF.md"
AGENTS="$ROOT/AGENTS.md"
ISSUE="$ROOT/docs/ops/cloud-agent-issue-resolution.md"
GHP="$ROOT/docs/ops/github-production-deploy.md"
EX="$ROOT/deploy/live-verify.markers.example"

for f in "$DOC" "$VERIFY" "$OBSERVE" "$DP" "$EX"; do
  [ -f "$f" ] && ok "present $(basename "$f")" || bad "missing $f"
done

if [ -f "$DOC" ]; then
  for needle in \
    'LIVE VERIFIED' \
    'MAX' \
    '3' \
    'self-heal' \
    'PRODUCTION_SSH_PRIVATE_KEY' \
    'LIVE_QA_PASS' \
    'ci-live-verify' \
    'skip_elaan' \
    'Cloud Agent' \
    'multi-agent' \
    'Exact-SHA' \
    'concurrency'
  do
    grep -qi "$needle" "$DOC" && ok "issue-to-live doc mentions: $needle" || bad "issue-to-live doc missing: $needle"
  done
  grep -qi 'MUST NEVER\|Never.*production secret\|production-secret-free' "$DOC" \
    && ok "issue-to-live doc states secret-free Cloud Agent" \
    || bad "issue-to-live doc missing secret-free boundary"
fi

if [ -f "$VERIFY" ]; then
  bash -n "$VERIFY" && ok "bash -n ci-live-verify.sh" || bad "bash -n ci-live-verify.sh"
  grep -q 'EXPECTED_SHA' "$VERIFY" && ok "ci-live-verify checks EXPECTED_SHA" || bad "ci-live-verify missing EXPECTED_SHA"
  grep -q 'data-tn-sale-root\|data-tn-sale-document' "$VERIFY" && ok "ci-live-verify asserts sale markers" || bad "ci-live-verify missing sale markers"
  grep -q '/up' "$VERIFY" && ok "ci-live-verify checks /up" || bad "ci-live-verify missing /up"
  grep -qi 'nayatel_vps_key' "$VERIFY" && ok "ci-live-verify refuses Replit key" || bad "ci-live-verify should refuse Replit key"
  # Must not hardcode passwords — only read from env
  if grep -E 'PASS="\$\{LIVE_QA_PASS' "$VERIFY" >/dev/null; then
    ok "ci-live-verify reads LIVE_QA_PASS from env"
  else
    bad "ci-live-verify must read LIVE_QA_PASS from env"
  fi
  if grep -E 'PASS="[^$"][^"]+"|password="[^$"][^"]+"' "$VERIFY" | grep -vqE '^\s*#'; then
    bad "ci-live-verify may hardcode a password string"
  else
    ok "ci-live-verify has no hardcoded password strings"
  fi
  # Default profile must not seed bills
  if grep -qi 'invoice/store\|SEED_ID\|SMOKE X-REPORT PROBE' "$VERIFY"; then
    bad "ci-live-verify must not seed probe bills by default"
  else
    ok "ci-live-verify does not seed probe bills"
  fi
fi

if [ -f "$OBSERVE" ]; then
  bash -n "$OBSERVE" && ok "bash -n cloud-issue-to-live-observe.sh" || bad "bash -n observe"
  if grep -E '^\s*(export )?(LIVE_QA_PASS|PRODUCTION_SSH_PRIVATE_KEY)=' "$OBSERVE" | grep -q .; then
    bad "observe script must not assign production secrets"
  else
    ok "observe script does not assign production secrets"
  fi
  grep -q 'gh run' "$OBSERVE" && ok "observe uses gh run listing" || bad "observe should use gh"
  if grep -E '^\s*ssh |live_ssh ' "$OBSERVE" | grep -vqE '^\s*#'; then
    bad "observe must not SSH"
  else
    ok "observe does not SSH"
  fi
fi

if [ -f "$DP" ]; then
  grep -q 'ci-live-verify.sh' "$DP" && ok "Deploy Production runs ci-live-verify.sh" || bad "Deploy Production missing live verify step"
  grep -q 'LIVE_QA_PASS' "$DP" && ok "Deploy Production wires LIVE_QA_PASS secret" || bad "Deploy Production missing LIVE_QA_PASS"
  grep -q 'target_sha' "$DP" && ok "Deploy Production accepts target_sha handoff" || bad "Deploy Production missing target_sha"
  grep -q 'environment: production' "$DP" && ok "Deploy Production keeps environment: production" || bad "lost environment: production"
  grep -q 'concurrency:' "$DP" && grep -q 'production-deploy' "$DP" \
    && ok "Deploy Production keeps concurrency protection" || bad "concurrency protection missing"
  grep -q 'deploy_guard_main_tip\|deploy_require_origin_main_tip' "$DP" \
    && ok "Deploy Production refuses non-tip SHA" || bad "Deploy Production missing origin/main tip guard"
  grep -q 'github.sha' "$DP" && ok "Deploy Production still uses github.sha" || bad "exact SHA wiring missing"
  if grep -q 'pull_request' "$DP"; then
    bad "Deploy Production must not run on pull_request"
  else
    ok "Deploy Production does not run on pull_request"
  fi
  # skip_elaan must remain emergency-only input, not default true
  if grep -A5 'skip_elaan:' "$DP" | grep -q 'default: false'; then
    ok "skip_elaan defaults to false"
  else
    bad "skip_elaan must default to false"
  fi
fi

for f in "$HAND" "$AGENTS" "$ISSUE" "$GHP"; do
  [ -f "$f" ] || continue
  grep -q 'cloud-agent-issue-to-live.md' "$f" \
    && ok "$(basename "$f") links issue-to-live" \
    || bad "$(basename "$f") must link docs/ops/cloud-agent-issue-to-live.md"
done

# Local browser fail-closed still present
LIB="$ROOT/scripts/lib/local-browser.mjs"
if [ -f "$LIB" ]; then
  grep -q 'assertLocalOnlyBaseUrl' "$LIB" && ok "local browser fail-closed helper still present" || bad "local-browser guard missing"
fi

if [ -f "$ROOT/scripts/tests/ci-live-verify-login-redirect-check.sh" ]; then
  bash "$ROOT/scripts/tests/ci-live-verify-login-redirect-check.sh" \
    && ok "ci-live-verify login redirect diagnostics checks passed" \
    || bad "ci-live-verify login redirect diagnostics checks failed"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "issue-to-live-check: ALL PASS"
  exit 0
fi
echo "issue-to-live-check: $FAILS FAIL(S)" >&2
exit 1
