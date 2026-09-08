#!/bin/bash
# Prove draft → ready_for_review re-enters auto-merge WITHOUT giving
# enable-pr-auto-merge.yml a pull_request trigger (that would run the
# write-token job from the PR branch).
# Does NOT SSH, deploy, merge, or call GitHub.
# Usage: bash scripts/tests/automerge-ready-for-review-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
PC="$ROOT/.github/workflows/pr-checks.yml"
DP="$ROOT/.github/workflows/deploy-production.yml"

for f in "$AM" "$PC" "$DP"; do
  [ -f "$f" ] || bad "missing $f"
done

# --------------------------------------------------------------------------- PR checks: default types + ready_for_review only
python3 - "$PC" <<'PY' && ok "PR checks pull_request types include ready_for_review and keep opened/synchronize/reopened" || bad "PR checks types must be opened, synchronize, reopened, ready_for_review"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
# Isolate the on: block before permissions/jobs
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
# Must not subscribe to noisy/broad extra types
banned = ["edited", "labeled", "unlabeled", "assigned", "review_requested", "closed", "converted_to_draft"]
for b in banned:
    if b in got:
        print("unexpected PR checks type", b, file=sys.stderr)
        sys.exit(1)
sys.exit(0)
PY

# --------------------------------------------------------------------------- Enable PR auto-merge stays workflow_run-only (default branch)
python3 - "$AM" <<'PY' && ok "Enable PR auto-merge trigger is workflow_run of PR checks only (no pull_request)" || bad "auto-merge must not gain a pull_request trigger"
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
    print("enable-pr-auto-merge must not add workflow_dispatch (not the existing security model)", file=sys.stderr)
    sys.exit(1)
if "workflow_run:" not in on:
    print("missing workflow_run", file=sys.stderr)
    sys.exit(1)
if 'workflows: ["PR checks"]' not in on and "workflows: ['PR checks']" not in on:
    print("must listen to PR checks", file=sys.stderr)
    sys.exit(1)
if "types: [completed]" not in on:
    print("must listen to completed", file=sys.stderr)
    sys.exit(1)
if "ready_for_review" in on:
    print("do not put ready_for_review on enable-pr-auto-merge (duplicate + PR-branch execution)", file=sys.stderr)
    sys.exit(1)
sys.exit(0)
PY

# Failed PR checks cannot enable auto-merge
if grep -q "github.event.workflow_run.conclusion == 'success'" "$AM" \
   && grep -q "github.event.workflow_run.event == 'pull_request'" "$AM"; then
  ok "auto-merge job requires successful PR-checks pull_request workflow_run"
else
  bad "auto-merge must require workflow_run conclusion success and event pull_request"
fi

# Draft skipped; ready_for_review path documented
if grep -q 'pr.draft' "$AM" && grep -q 'skip draft' "$AM"; then
  ok "auto-merge skips draft PRs"
else
  bad "auto-merge must skip drafts and log it"
fi

# Same-repo + cursor/ remain
grep -q 'head.repo.full_name' "$AM" && grep -q "skip fork" "$AM" \
  && ok "fork PRs still skipped" \
  || bad "fork skip missing"
grep -q "startsWith('cursor/')" "$AM" \
  && ok "cursor/ branch restriction remains" \
  || bad "cursor/ restriction missing"

# Squash + SHA pin + already-merged skip (no duplicate merge/handoff)
grep -q 'mergeMethod: SQUASH' "$AM" && grep -q "merge_method: 'squash'" "$AM" \
  && ok "squash-merge behavior remains" \
  || bad "squash merge missing"
grep -q 'run.head_sha' "$AM" \
  && ok "merge still pinned to PR-checks head SHA" \
  || bad "head SHA pin missing"
grep -qi 'already merged — skipping deploy handoff on rerun\|skipping deploy handoff on rerun' "$AM" \
  && ok "already-merged reruns still skip duplicate deploy handoff" \
  || bad "duplicate-handoff guard missing"

# Permissions unchanged (contents write / PR write / actions write; no extra)
python3 - "$AM" <<'PY' && ok "auto-merge permissions unchanged (contents/pull-requests/actions write only)" || bad "auto-merge permissions broadened or reduced"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"(?ms)^permissions:\n(.*?)(?=^jobs:|^on:)", text)
if not m:
    sys.exit(1)
blob = m.group(1)
need = {"contents: write", "pull-requests: write", "actions: write"}
got = {ln.strip() for ln in blob.splitlines() if ln.strip() and not ln.strip().startswith("#")}
if got != need:
    print("permissions:", got, file=sys.stderr)
    sys.exit(1)
sys.exit(0)
PY

# Must not checkout PR code; must not hold prod secrets
if grep -vE '^\s*#' "$AM" | grep -qiE 'actions/checkout'; then
  bad "auto-merge must not checkout PR code"
else
  ok "auto-merge still does not checkout untrusted code"
fi
if grep -vE '^\s*#' "$AM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS'; then
  bad "auto-merge must not hold production secrets"
else
  ok "auto-merge still production-secret-free"
fi

# Deploy Production trigger/security not changed by this file set:
# this check only asserts AM/PC did not start deploying.
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

# Handoff still only target_sha (no skip_elaan)
python3 - "$AM" <<'PY' && ok "deploy handoff still sends only target_sha" || bad "handoff must not send skip_elaan/allow_settings"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
idx = text.find("createWorkflowDispatch")
if idx < 0:
    sys.exit(1)
window = text[idx:idx+800]
if "skip_elaan" in window or "allow_settings" in window:
    sys.exit(1)
if "target_sha" not in window:
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
