#!/bin/bash
# Static validation: Cursor auto-merge is disabled; PR checks still run;
# owner-triggered merge+deploy is the only initiation path.
# Does NOT SSH, deploy, or require secrets.
# Usage: bash scripts/tests/pr-auto-merge-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
OM="$ROOT/.github/workflows/owner-merge-and-deploy.yml"
PC="$ROOT/.github/workflows/pr-checks.yml"
DP="$ROOT/.github/workflows/deploy-production.yml"

[ -f "$AM" ] || bad "missing $AM"
[ -f "$OM" ] || bad "missing $OM"
[ -f "$PC" ] || bad "missing $PC"
[ -f "$DP" ] || bad "missing $DP"

if [ -f "$AM" ]; then
  if grep -qE 'enablePullRequestAutoMerge|pulls\.merge|createWorkflowDispatch' "$AM"; then
    bad "enable-pr-auto-merge.yml must not auto-merge or dispatch deploy"
  else
    ok "automatic Cursor merge is disabled"
  fi

  grep -q 'workflow_run' "$AM" \
    && ok "retired auto-merge still listens on workflow_run of PR checks (default-branch)" \
    || bad "keep workflow_run so this file never gains a pull_request write-token trigger"

  grep -q "workflows: \[\"PR checks\"\]" "$AM" || grep -q "workflows: \['PR checks'\]" "$AM" \
    && ok "retired listener is gated on PR checks" \
    || bad "retired workflow must still name PR checks"

  if grep -vE '^\s*#' "$AM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS|environment:\s*production|deploy-live\.sh|ci-deploy-production\.sh'; then
    bad "retired auto-merge workflow must not reference production deploy secrets or apply scripts"
  else
    ok "retired auto-merge workflow has no production secrets/apply scripts"
  fi

  if grep -vE '^\s*#' "$AM" | grep -qiE 'actions/checkout'; then
    bad "retired auto-merge workflow must not checkout PR code"
  else
    ok "retired auto-merge workflow does not checkout untrusted code"
  fi
fi

if [ -f "$PC" ]; then
  grep -q 'name: PR checks' "$PC" \
    && ok "PR checks workflow is named PR checks" \
    || bad "PR checks workflow name should be 'PR checks' (required-check target)"

  grep -q 'pr-auto-merge-check.sh' "$PC" \
    && ok "PR checks runs pr-auto-merge-check.sh" \
    || bad "PR checks must run the static validator"

  grep -q 'issue-to-live-check.sh' "$PC" \
    && ok "PR checks runs issue-to-live-check.sh" \
    || bad "PR checks must run issue-to-live-check.sh"

  grep -q 'ci-deploy-production-check.sh' "$PC" \
    && ok "PR checks runs ci-deploy-production-check.sh" \
    || bad "PR checks must run production deploy safety checks"

  grep -q 'ready_for_review' "$PC" \
    && ok "PR checks retriggers on ready_for_review" \
    || bad "PR checks must include pull_request type ready_for_review"

  if grep -vE '^\s*#' "$PC" | grep -qiE 'environment:\s*production|PRODUCTION_SSH_PRIVATE_KEY'; then
    bad "PR checks must not use the production Environment or SSH secret"
  else
    ok "PR checks has no production Environment/secret"
  fi

  grep -q 'deploy-unattended-safety-check.sh' "$PC" \
    && ok "PR checks runs deploy-unattended-safety-check.sh" \
    || bad "PR checks must run unattended deploy safety checks"

  grep -q 'owner-merge-and-deploy-check.sh' "$PC" \
    && ok "PR checks runs owner-merge-and-deploy-check.sh" \
    || bad "PR checks must run owner merge/deploy safety checks"
fi

  if [ -f "$DP" ]; then
  grep -qE '^[[:space:]]+environment: production-deploy[[:space:]]*$' "$DP" \
    && ok "Deploy Production still uses environment: production-deploy" \
    || bad "must not remove production-deploy Environment from deploy workflow"

  grep -q 'PRODUCTION_SSH_PRIVATE_KEY' "$DP" \
    && ok "Deploy Production still uses PRODUCTION_SSH_PRIVATE_KEY" \
    || bad "must not remove production SSH secret from deploy workflow"

  grep -q 'ci-live-verify.sh' "$DP" \
    && ok "Deploy Production runs post-deploy ci-live-verify.sh" \
    || bad "Deploy Production must run scripts/ci-live-verify.sh after apply"

  grep -q 'LIVE_QA_PASS' "$DP" \
    && ok "Deploy Production wires LIVE_QA_PASS for live verify" \
    || bad "Deploy Production must pass LIVE_QA_PASS into live verify"

  grep -q 'target_sha' "$DP" \
    && ok "Deploy Production accepts target_sha for owner-merge handoff" \
    || bad "Deploy Production must accept workflow_dispatch target_sha"

  grep -q 'steps.resolve.outputs.sha' "$DP" \
    && ok "Deploy Production pins apply/verify to resolved SHA" \
    || bad "Deploy Production must resolve and pin deploy SHA"

  if grep -q 'pull_request' "$DP"; then
    bad "Deploy Production must not run on pull_request"
  else
    ok "Deploy Production does not run on pull_request"
  fi
fi

if [ -f "$ROOT/scripts/tests/automerge-deploy-handoff-check.sh" ]; then
  bash "$ROOT/scripts/tests/automerge-deploy-handoff-check.sh" \
    && ok "automerge-deploy-handoff-check nested run" \
    || bad "automerge-deploy-handoff-check nested run failed"
fi

if [ -f "$ROOT/scripts/tests/automerge-ready-for-review-check.sh" ]; then
  bash "$ROOT/scripts/tests/automerge-ready-for-review-check.sh" \
    && ok "automerge-ready-for-review-check nested run" \
    || bad "automerge-ready-for-review-check nested run failed"
fi

if [ -f "$ROOT/scripts/tests/owner-merge-and-deploy-check.sh" ]; then
  bash "$ROOT/scripts/tests/owner-merge-and-deploy-check.sh" \
    && ok "owner-merge-and-deploy-check nested run" \
    || bad "owner-merge-and-deploy-check nested run failed"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
