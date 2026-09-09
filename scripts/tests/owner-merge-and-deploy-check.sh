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
REQ="$ROOT/scripts/owner-merge-and-deploy-request.sh"

for f in "$AM" "$OM" "$PC" "$DP" "$LIB" "$SH" "$REQ" "$DIAG" "$REM"; do
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
for needle in ("pull_number", "expected_head_sha", "confirm"):
    if needle not in on:
        print("missing input", needle, file=sys.stderr); sys.exit(1)
if "Approved — Merge & Deploy" not in text:
    print("missing confirmation phrase", file=sys.stderr); sys.exit(1)
if "Deploy kar do" not in text or "Live kar do" not in text or "Approved, put it live" not in text:
    print("missing owner confirm aliases", file=sys.stderr); sys.exit(1)
if "owner-merge-and-deploy-request.sh" not in text:
    print("missing request-script pointer", file=sys.stderr); sys.exit(1)
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
grep -q 'sha=' "$SH" && grep -q 'expected-head-sha' "$SH" \
  && ok "squash merge is pinned to expected head SHA" \
  || bad "must pin pulls.merge sha to expected head"
bash -n "$SH" && ok "owner-merge-and-deploy.sh bash -n" || bad "bash -n owner-merge-and-deploy.sh"

# --------------------------------------------------------------------------- Request helper (Phase 2, no mutate except workflow_dispatch attempt)
bash -n "$REQ" && ok "owner-merge-and-deploy-request.sh bash -n" \
  || bad "bash -n owner-merge-and-deploy-request.sh"
grep -q 'workflow run owner-merge-and-deploy.yml' "$REQ" \
  && ok "request script dispatches Owner Merge & Deploy only" \
  || bad "request script must gh workflow run owner-merge-and-deploy.yml"
if grep -vE '^\s*#' "$REQ" | grep -qE 'gh pr merge|pulls/.*/merge|workflow run deploy-production'; then
  bad "request script must not merge PRs or dispatch Deploy Production"
else
  ok "request script does not merge or dispatch Deploy Production"
fi
if grep -E '^\s*ssh |live_ssh ' "$REQ" | grep -vqE '^\s*#'; then
  bad "request script must not SSH"
else
  ok "request script does not SSH"
fi
grep -q 'HTTP 403' "$REQ" && grep -q 'exit 3' "$REQ" \
  && ok "request script fail-closes on workflow_dispatch 403 with Actions fill-ins" \
  || bad "request script must document HTTP 403 / exit 3"
grep -q 'Deploy kar do' "$REQ" && grep -q 'Live kar do' "$REQ" \
  && ok "request script documents owner confirm aliases" \
  || bad "request script must list confirm aliases"

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
  grep -q 'Merge pull request' "$DP" && grep -q 'rev-list --parents' "$DP" \
    && ok "Deploy Production refuses GitHub PR merge-commits" \
    || bad "must refuse Merge-button merge-commits on Deploy Production"
  python3 - "$DP" <<'PY' && ok "Deploy Production does not auto-deploy on push to main" || bad "must not keep on.push (PR Merge button bypass)"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"(?ms)^on:\n(.*?)(?=^permissions:|^concurrency:|^jobs:)", text)
on = m.group(1) if m else ""
if re.search(r"(?m)^\s*push:", on):
    sys.exit(1)
if "workflow_dispatch:" not in on:
    sys.exit(1)
sys.exit(0)
PY
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
