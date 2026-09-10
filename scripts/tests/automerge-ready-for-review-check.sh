#!/bin/bash
# Prove draft → ready_for_review still refreshes PR checks WITHOUT giving
# write-token merge workflows a pull_request trigger.
# Does NOT SSH, deploy, merge, or call GitHub.
# Usage: bash scripts/tests/automerge-ready-for-review-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
OM="$ROOT/.github/workflows/owner-merge-and-deploy.yml"
PC="$ROOT/.github/workflows/pr-checks.yml"
DP="$ROOT/.github/workflows/deploy-production.yml"

for f in "$AM" "$OM" "$PC" "$DP"; do
  [ -f "$f" ] || bad "missing $f"
done

python3 - "$PC" <<'PY' && ok "PR checks pull_request types include ready_for_review and keep opened/synchronize/reopened" || bad "PR checks types must be opened, synchronize, reopened, ready_for_review"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"(?ms)^on:\n(.*?)(?=^permissions:|^jobs:)", text)
if not m:
    print("no on: block", file=sys.stderr)
    sys.exit(1)
on = m.group(1)
if "workflow_dispatch" in on or "push:" in on or "workflow_run:" in on:
    print("PR checks must stay pull_request-only", file=sys.stderr)
    sys.exit(1)
if "pull_request:" not in on:
    print("missing pull_request", file=sys.stderr)
    sys.exit(1)
types = re.search(r"types:\s*\[([^\]]+)\]", on)
if not types:
    print("PR checks must list pull_request types (ready_for_review is not a GitHub default)", file=sys.stderr)
    sys.exit(1)
got = [t.strip() for t in types.group(1).split(",")]
need = ["opened", "synchronize", "reopened", "ready_for_review"]
if got != need:
    print("types mismatch:", got, "want", need, file=sys.stderr)
    sys.exit(1)
banned = ["edited", "labeled", "unlabeled", "assigned", "review_requested", "closed", "converted_to_draft"]
for b in banned:
    if b in got:
        print("unexpected PR checks type", b, file=sys.stderr)
        sys.exit(1)
sys.exit(0)
PY

python3 - "$AM" <<'PY' && ok "retired auto-merge trigger is workflow_run of PR checks only (no pull_request)" || bad "retired auto-merge must not gain a pull_request trigger"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"(?ms)^on:\n(.*?)(?=^permissions:|^jobs:)", text)
if not m:
    print("no on: block", file=sys.stderr)
    sys.exit(1)
on = m.group(1)
if re.search(r"(?m)^\s*pull_request:", on) or re.search(r"(?m)^\s*pull_request_target:", on):
    print("enable-pr-auto-merge must not trigger on pull_request / pull_request_target", file=sys.stderr)
    sys.exit(1)
if re.search(r"(?m)^\s*workflow_dispatch:", on):
    print("retired auto-merge must not add workflow_dispatch", file=sys.stderr)
    sys.exit(1)
if "workflow_run:" not in on:
    print("missing workflow_run", file=sys.stderr)
    sys.exit(1)
if 'workflows: ["PR checks"]' not in on and "workflows: ['PR checks']" not in on:
    print("must listen to PR checks", file=sys.stderr)
    sys.exit(1)
if "ready_for_review" in on:
    print("do not put ready_for_review on enable-pr-auto-merge", file=sys.stderr)
    sys.exit(1)
sys.exit(0)
PY

python3 - "$OM" <<'PY' && ok "Owner Merge & Deploy has no pull_request trigger (write-token stays on main)" || bad "owner workflow must not run from the PR branch"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"(?ms)^on:\n(.*?)(?=^permissions:|^concurrency:|^jobs:)", text)
on = m.group(1) if m else ""
if re.search(r"(?m)^\s*pull_request:", on) or re.search(r"(?m)^\s*pull_request_target:", on):
    sys.exit(1)
if "workflow_run:" in on:
    sys.exit(1)
if "workflow_dispatch:" not in on:
    sys.exit(1)
sys.exit(0)
PY

if grep -qE 'enablePullRequestAutoMerge|pulls\.merge' "$AM"; then
  bad "retired auto-merge must not still squash-merge"
else
  ok "retired auto-merge no longer squash-merges"
fi

if grep -vE '^\s*#' "$AM" | grep -qiE 'actions/checkout'; then
  bad "retired auto-merge must not checkout PR code"
else
  ok "retired auto-merge still does not checkout untrusted code"
fi
if grep -vE '^\s*#' "$AM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS'; then
  bad "retired auto-merge must not hold production secrets"
else
  ok "retired auto-merge still production-secret-free"
fi

if grep -vE '^\s*#' "$PC" | grep -qiE 'environment:\s*production|PRODUCTION_SSH_PRIVATE_KEY|ci-deploy-production.sh'; then
  bad "PR checks must not deploy or hold production secrets"
else
  ok "PR checks still does not deploy"
fi
if grep -q 'pull_request' "$DP"; then
  bad "Deploy Production must not gain a pull_request trigger"
else
  ok "Deploy Production still does not run on pull_request"
fi

python3 - "$ROOT/scripts/owner-merge-and-deploy.sh" <<'PY' && ok "owner dispatch sends exact SHA and relay provenance" || bad "owner handoff provenance contract missing"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
if "skip_elaan" in text or "allow_settings" in text:
    sys.exit(1)
if "inputs[target_sha]" not in text:
    sys.exit(1)
if "inputs[approval_request_id]" not in text or "inputs[handoff_nonce]" not in text or "v1/deploy-run" not in text:
    sys.exit(1)
if "inputs[provenance_receipt]" in text:
    sys.exit(1)
sys.exit(0)
PY

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "automerge-ready-for-review-check: ALL PASS"
  exit 0
fi
echo "automerge-ready-for-review-check: $FAILS FAIL(S)" >&2
exit 1
