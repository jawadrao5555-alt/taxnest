#!/bin/bash
# Prove Cursor automatic merge is disabled and owner-triggered merge+deploy
# is gated. Does NOT SSH, merge, deploy, or call GitHub.
# Usage: bash scripts/tests/owner-merge-and-deploy-check.sh
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
OM="$ROOT/.github/workflows/owner-merge-and-deploy.yml"
PC="$ROOT/.github/workflows/pr-checks.yml"
DP="$ROOT/.github/workflows/deploy-production.yml"
DIAG="$ROOT/.github/workflows/live-ops-diagnose.yml"
REM="$ROOT/.github/workflows/live-ops-remediate.yml"
LIB="$ROOT/scripts/lib/owner-merge-and-deploy.py"
SH="$ROOT/scripts/owner-merge-and-deploy.sh"

for f in "$AM" "$OM" "$PC" "$DP" "$LIB" "$SH" "$DIAG" "$REM"; do
  [ -f "$f" ] || bad "missing $f"
done

# --------------------------------------------------------------------------- Auto-merge disabled
if grep -qE 'enablePullRequestAutoMerge|pulls\.merge|createWorkflowDispatch' "$AM"; then
  bad "enable-pr-auto-merge.yml must not merge or dispatch Deploy Production"
else
  ok "automatic Cursor merge/dispatch is disabled in enable-pr-auto-merge.yml"
fi
grep -q 'Auto-merge disabled' "$AM" \
  && ok "retired auto-merge job states owner approval is required" \
  || bad "retired auto-merge must say Auto-merge disabled"
if grep -vE '^\s*#' "$AM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS|environment:\s*production'; then
  bad "retired auto-merge must not hold production secrets"
else
  ok "retired auto-merge is production-secret-free"
fi
python3 - "$AM" <<'PY' && ok "retired auto-merge stays workflow_run of PR checks (no pull_request write-token job)" || bad "retired auto-merge trigger unsafe"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"(?ms)^on:\n(.*?)(?=^permissions:|^jobs:)", text)
on = m.group(1) if m else ""
if re.search(r"(?m)^\s*pull_request:", on) or re.search(r"(?m)^\s*pull_request_target:", on):
    sys.exit(1)
if "workflow_run:" not in on:
    sys.exit(1)
if 'workflows: ["PR checks"]' not in on and "workflows: ['PR checks']" not in on:
    sys.exit(1)
sys.exit(0)
PY

# --------------------------------------------------------------------------- PR checks still run
grep -q 'name: PR checks' "$PC" && grep -q 'pr-auto-merge-check.sh' "$PC" \
  && ok "PR checks workflow still runs validation" \
  || bad "PR checks must keep running"
grep -q 'owner-merge-and-deploy-check.sh' "$PC" \
  && ok "PR checks runs owner-merge-and-deploy-check.sh" \
  || bad "PR checks must run owner-merge-and-deploy-check.sh"
grep -q 'ready_for_review' "$PC" \
  && ok "PR checks still retriggers on ready_for_review" \
  || bad "keep ready_for_review so owner sees fresh checks"

# --------------------------------------------------------------------------- Owner workflow shape
python3 - "$OM" <<'PY' && ok "Owner Merge & Deploy is owner workflow_dispatch only" || bad "owner workflow trigger/permissions unsafe"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
if "name: Owner Merge & Deploy" not in text:
    print("missing workflow name", file=sys.stderr); sys.exit(1)
m = re.search(r"(?ms)^on:\n(.*?)(?=^permissions:|^concurrency:|^jobs:)", text)
on = m.group(1) if m else ""
if "workflow_dispatch:" not in on:
    sys.exit(1)
if re.search(r"(?m)^\s*pull_request:", on) or "workflow_run:" in on or re.search(r"(?m)^\s*push:", on):
    print("must not auto-trigger on PR/push/workflow_run", file=sys.stderr); sys.exit(1)
for needle in ("pull_number", "expected_head_sha", "approval_request_id"):
    if needle not in on:
        print("missing input", needle, file=sys.stderr); sys.exit(1)
if "id-token: write" not in text or "OWNER_APPROVAL_RELAY_URL" not in text:
    print("missing OIDC relay configuration", file=sys.stderr); sys.exit(1)
for block in re.findall(r"(?ms)^\s+run:\s*\|\n(.*?)(?=^\s+- name:|^\s*$)", text):
    if "${{" in block:
        print("owner run block contains raw GitHub expression", file=sys.stderr); sys.exit(1)
if "group: owner-merge-and-deploy" not in text:
    print("missing owner concurrency group", file=sys.stderr); sys.exit(1)
if "cancel-in-progress: false" not in text:
    print("owner merge must not cancel in-progress", file=sys.stderr); sys.exit(1)
if re.search(r"(?m)^\s+environment:\s+", text):
    print("owner merge must not use a GitHub Environment (no secrets)", file=sys.stderr); sys.exit(1)
if "PRODUCTION_SSH_PRIVATE_KEY" in text or "LIVE_QA_PASS" in text:
    print("owner merge must not read production secrets", file=sys.stderr); sys.exit(1)
if "skip_elaan" in text or "allow_settings" in text:
    print("owner merge must not pass skip_elaan/allow_settings", file=sys.stderr); sys.exit(1)
sys.exit(0)
PY

grep -q 'owner-merge-and-deploy.sh' "$OM" \
  && ok "owner workflow runs scripts/owner-merge-and-deploy.sh" \
  || bad "owner workflow must call the merge script"
if grep -q 'confirm' "$OM"; then
  bad "owner workflow must not authorize with a plaintext confirmation"
else
  ok "owner workflow has no plaintext approval bypass"
fi
grep -q "merge_method=squash\|merge_method:squash\|merge_method=squash" "$SH" \
  && ok "owner merge uses squash" \
  || bad "must squash merge"
grep -q 'deploy-production.yml/dispatches' "$SH" && grep -q 'inputs\[target_sha\]' "$SH" \
  && ok "owner path dispatches Deploy Production with exact target_sha" \
  || bad "must dispatch deploy-production.yml target_sha only"
if grep -q 'skip_elaan' "$SH" || grep -q 'allow_settings' "$SH"; then
  bad "owner merge script must not send skip_elaan/allow_settings"
else
  ok "owner dispatch does not send skip_elaan/allow_settings"
fi
grep -q 'approval_request_id' "$SH" && grep -q 'ACTIONS_ID_TOKEN_REQUEST_URL' "$SH" \
  && ok "owner script claims approval through GitHub OIDC relay" \
  || bad "owner script must use OIDC relay approval claim"
grep -q '/api/deployment-approval/v1/approval-claims' "$SH" \
  && grep -q '/api/deployment-approval/v1/merge-complete' "$SH" \
  && grep -q '/api/deployment-approval/v1/owner-status' "$OM" \
  && ok "owner workflow uses claim, merge-complete, and failure-release endpoints" \
  || bad "owner workflow relay endpoint contract/order missing"
grep -q '::add-mask::' "$SH" && grep -q 'deployment_run_id' "$SH" \
  && grep -q 'v1/deploy-run' "$SH" \
  && ok "owner script masks receipt and registers exact deployment run" \
  || bad "owner script must mask receipt and register deployment run"
grep -q 'createdAt' "$SH" && grep -q 'displayTitle' "$SH" && grep -q 'headSha' "$SH" \
  && grep -q 'displayTitle == \$title' "$SH" && grep -q 'min_by(.databaseId)' "$SH" \
  && ok "owner binds exact-title newly-created run and chooses smallest database ID" \
  || bad "owner run binding selector is incomplete"
grep -q 'openssl rand -hex 16' "$SH" && grep -q 'inputs\[handoff_nonce\]' "$SH" \
  && grep -q 'handoff_nonce' "$SH" \
  && ok "owner generates and carries a 128-bit handoff nonce" \
  || bad "handoff nonce correlation missing"
python3 - "$SH" <<'PY' && ok "merge-complete precedes Deploy Production dispatch" || bad "merge-complete must precede dispatch"
import sys
t=open(sys.argv[1], encoding="utf-8").read()
if t.index("api/deployment-approval/v1/merge-complete") > t.index("actions/workflows/deploy-production.yml/dispatches"):
    sys.exit(1)
PY
grep -q 'sha=' "$SH" && grep -q 'expected-head-sha' "$SH" \
  && ok "squash merge is pinned to expected head SHA" \
  || bad "must pin pulls.merge sha to expected head"
bash -n "$SH" && ok "owner-merge-and-deploy.sh bash -n" || bad "bash -n owner-merge-and-deploy.sh"

# --------------------------------------------------------------------------- Decision library (fixture matrix)
python3 "$LIB" --self-test \
  && ok "owner-merge decision self-test (phrase, cursor/, draft, main, checks, SHA, idempotent, stale)" \
  || bad "owner-merge-and-deploy.py self-test failed"

# --------------------------------------------------------------------------- Deploy Production invariants remain
grep -qE '^[[:space:]]+environment: production-deploy[[:space:]]*$' "$DP" \
  && ok "Deploy Production still uses environment: production-deploy" \
  || bad "must not move deploy back to Environment production"
grep -q 'group: production-deploy' "$DP" && grep -q 'cancel-in-progress: false' "$DP" \
  && ok "production deploy remains serialized (cancel-in-progress false)" \
  || bad "must keep serialized production-deploy concurrency"
grep -q 'deploy_guard_main_tip' "$DP" \
  && ok "stale/non-tip SHA still rejected on Deploy Production" \
  || bad "must keep tip guard"
grep -qE '^[[:space:]]+environment: production[[:space:]]*$' "$DIAG" \
  && grep -qE '^[[:space:]]+environment: production[[:space:]]*$' "$REM" \
  && ok "Live Ops still uses Environment production" \
  || bad "must not change Live Ops Environment"
grep -q 'OWNER_APPROVES_LIVE_OPS_FIX' "$REM" \
  && ok "Live Ops owner phrase unchanged" \
  || bad "must not weaken Live Ops authorization"

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "owner-merge-and-deploy-check: ALL PASS"
  exit 0
fi
echo "owner-merge-and-deploy-check: $FAILS FAIL(S)" >&2
exit 1
