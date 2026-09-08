#!/bin/bash
# Static validation for Cloud Agent PR auto-merge + PR checks workflows.
# Does NOT SSH, deploy, or require secrets.
# Usage: bash scripts/tests/pr-auto-merge-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
PC="$ROOT/.github/workflows/pr-checks.yml"
DP="$ROOT/.github/workflows/deploy-production.yml"

[ -f "$AM" ] || bad "missing $AM"
[ -f "$PC" ] || bad "missing $PC"
[ -f "$DP" ] || bad "missing $DP"

if [ -f "$AM" ]; then
  grep -q 'enablePullRequestAutoMerge' "$AM" \
    && ok "auto-merge workflow uses GitHub native enablePullRequestAutoMerge" \
    || bad "auto-merge workflow must call enablePullRequestAutoMerge"

  grep -q 'mergeMethod: SQUASH' "$AM" \
    && ok "auto-merge method is SQUASH" \
    || bad "auto-merge must use SQUASH"

  grep -q 'workflow_run' "$AM" \
    && ok "auto-merge listens on workflow_run of PR checks (default-branch, after checks pass)" \
    || bad "auto-merge should use workflow_run so it runs from main after PR checks"

  grep -q "startsWith('cursor/')" "$AM" || grep -q "startsWith(\"cursor/\")" "$AM" \
    && ok "auto-merge is limited to cursor/ Cloud Agent branches" \
    || bad "auto-merge must filter cursor/ branches"

  grep -q 'head.repo.full_name' "$AM" \
    && ok "auto-merge ignores forks" \
    || bad "auto-merge must refuse fork PRs"

  grep -q 'workflows: \["PR checks"\]' "$AM" \
    && ok "auto-merge is gated on the PR checks workflow succeeding" \
    || bad "auto-merge must run only after workflow PR checks"

  grep -q "pulls.merge" "$AM" && grep -q "merge_method: 'squash'" "$AM" \
    && ok "CLEAN PRs fall back to REST squash merge" \
    || bad "workflow must squash-merge when GitHub rejects auto-merge for CLEAN status"

  grep -q 'clean status' "$AM" \
    && ok "workflow handles GraphQL 'Pull request is in clean status'" \
    || bad "workflow must catch GitHub CLEAN auto-merge rejection"

  grep -q 'run.head_sha' "$AM" \
    && ok "squash merge is pinned to the PR-checks head SHA" \
    || bad "workflow must pin merge sha to workflow_run.head_sha"

  grep -q "mergeable_state === 'clean'" "$AM" \
    && ok "already-clean mergeable PRs squash without waiting on auto-merge" \
    || bad "workflow must treat mergeable_state clean as squash-now"

  if grep -vE '^\s*#' "$AM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS|environment:\s*production|deploy-live\.sh|ci-deploy-production\.sh'; then
    bad "auto-merge workflow must not reference production deploy secrets or apply scripts"
  else
    ok "auto-merge workflow has no production secrets/apply scripts"
  fi

  grep -q 'createWorkflowDispatch' "$AM" \
    && ok "auto-merge hands off via createWorkflowDispatch" \
    || bad "auto-merge must workflow_dispatch Deploy Production after GITHUB_TOKEN squash"

  grep -q 'target_sha' "$AM" \
    && ok "auto-merge passes target_sha for exact-SHA deploy" \
    || bad "auto-merge must pass target_sha"

  if grep -vE '^\s*#' "$AM" | grep -qiE 'actions/checkout'; then
    bad "auto-merge workflow must not checkout PR code"
  else
    ok "auto-merge workflow does not checkout untrusted code"
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

  if grep -vE '^\s*#' "$PC" | grep -qiE 'environment:\s*production|PRODUCTION_SSH_PRIVATE_KEY'; then
    bad "PR checks must not use the production Environment or SSH secret"
  else
    ok "PR checks has no production Environment/secret"
  fi
fi

  if [ -f "$DP" ]; then
  grep -q 'environment: production' "$DP" \
    && ok "Deploy Production still uses environment: production" \
    || bad "must not remove production Environment from deploy workflow"

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
    && ok "Deploy Production accepts target_sha for auto-merge handoff" \
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

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
