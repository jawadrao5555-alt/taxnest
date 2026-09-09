#!/bin/bash
# Prove Deploy Production handoff still exists, but only via owner-triggered
# merge — not via automatic Cursor merge.
# Does NOT deploy, SSH, or require secrets.
# Usage: bash scripts/tests/automerge-deploy-handoff-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
OM="$ROOT/.github/workflows/owner-merge-and-deploy.yml"
DP="$ROOT/.github/workflows/deploy-production.yml"
DOC="$ROOT/docs/ops/cloud-agent-issue-to-live.md"
GHP="$ROOT/docs/ops/github-production-deploy.md"

[ -f "$AM" ] || bad "missing enable-pr-auto-merge.yml"
[ -f "$OM" ] || bad "missing owner-merge-and-deploy.yml"
[ -f "$DP" ] || bad "missing deploy-production.yml"

if [ -f "$AM" ]; then
  if grep -q 'createWorkflowDispatch' "$AM"; then
    bad "retired auto-merge must not createWorkflowDispatch Deploy Production"
  else
    ok "retired auto-merge does not dispatch Deploy Production"
  fi
  if grep -vE '^\s*#' "$AM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS|environment:\s*production'; then
    bad "retired auto-merge must not hold production Environment secrets"
  else
    ok "retired auto-merge does not embed production secrets/Environment"
  fi
fi

if [ -f "$OM" ]; then
  grep -q 'deploy-production.yml' "$OM" || grep -q 'owner-merge-and-deploy.sh' "$OM" \
    && ok "owner workflow is the Deploy Production handoff" \
    || bad "owner-merge-and-deploy.yml must invoke the merge/dispatch script"
  grep -q 'actions: write' "$OM" \
    && ok "owner workflow has actions: write for workflow_dispatch" \
    || bad "owner-merge-and-deploy permissions.actions must be write"
  grep -qi 'GITHUB_TOKEN' "$OM" || grep -qi 'GITHUB_TOKEN' "$ROOT/scripts/owner-merge-and-deploy.sh" \
    && ok "owner path documents/uses GITHUB_TOKEN for dispatch after squash" \
    || bad "must document GITHUB_TOKEN squash → workflow_dispatch"
fi

SH="$ROOT/scripts/owner-merge-and-deploy.sh"
if [ -f "$SH" ]; then
  grep -q 'inputs\[target_sha\]' "$SH" \
    && ok "owner handoff passes target_sha input" \
    || bad "owner merge must pass inputs.target_sha"
  grep -q 'merge_method=squash' "$SH" \
    && ok "owner handoff uses squash merge SHA (not only PR head)" \
    || bad "owner merge must squash and hand off the squash commit SHA"
fi

if [ -f "$DP" ]; then
  grep -q 'target_sha' "$DP" \
    && ok "Deploy Production accepts target_sha input" \
    || bad "Deploy Production must accept workflow_dispatch target_sha"

  grep -q 'steps.resolve.outputs.sha\|resolve.outputs.sha' "$DP" \
    && ok "Deploy Production resolves deploy SHA via outputs" \
    || bad "Deploy Production must resolve DEPLOY_SHA for checkout/apply/verify"

  if grep -A2 'TARGET_SHA:' "$DP" | grep -q 'steps.resolve.outputs.sha'; then
    ok "deploy step TARGET_SHA uses resolved SHA"
  else
    bad "deploy step must set TARGET_SHA from resolved SHA"
  fi
  if grep -A2 'EXPECTED_SHA:' "$DP" | grep -q 'steps.resolve.outputs.sha'; then
    ok "live-verify EXPECTED_SHA uses resolved SHA"
  else
    bad "live-verify must set EXPECTED_SHA from resolved SHA"
  fi

  grep -q 'merge-base --is-ancestor\|deploy_require_on_main_history\|deploy_guard_main_tip\|deploy-main-tip-guard.sh' "$DP" \
    && ok "Deploy Production refuses SHAs not on origin/main" \
    || bad "Deploy Production must ancestry-check target SHA on main"

  grep -q 'deploy_require_origin_main_tip\|deploy_guard_main_tip' "$DP" \
    && ok "Deploy Production refuses non-tip origin/main SHAs" \
    || bad "Deploy Production must require exact origin/main tip"

  grep -q 'needs.gate.outputs.sha\|needs: gate' "$DP" \
    && ok "Deploy Production apply job is gated on the pre-apply tip check" \
    || bad "apply job must need the gate job"

  grep -qE '^[[:space:]]+environment: production-deploy[[:space:]]*$' "$DP" \
    && ok "Deploy Production keeps environment: production-deploy" \
    || bad "must keep Environment production-deploy"

  grep -q 'production-deploy' "$DP" \
    && ok "Deploy Production keeps concurrency group" \
    || bad "must keep concurrency protection"

  grep -q 'ci-live-verify.sh' "$DP" \
    && ok "Deploy Production still runs ci-live-verify" \
    || bad "must keep post-deploy live verify"

  grep -A3 '^on:' "$DP" | grep -q 'push:' \
    && ok "Deploy Production still triggers on push to main" \
    || bad "must keep push-to-main trigger for human merges"
fi

if [ -f "$DOC" ]; then
  grep -qi 'Owner Merge & Deploy\|Approved — Merge & Deploy' "$DOC" \
    && ok "issue-to-live doc describes owner-triggered merge" \
    || bad "issue-to-live doc must describe Owner Merge & Deploy"
  grep -qi 'workflow_dispatch\|GITHUB_TOKEN' "$DOC" \
    && ok "issue-to-live doc mentions GITHUB_TOKEN/workflow_dispatch" \
    || bad "issue-to-live doc must still mention workflow_dispatch"
fi

if [ -f "$GHP" ]; then
  grep -qi 'target_sha\|workflow_dispatch' "$GHP" \
    && ok "github-production-deploy doc mentions target_sha/handoff" \
    || bad "github-production-deploy.md must document target_sha handoff"
  grep -qi 'Owner Merge & Deploy\|owner-merge-and-deploy' "$GHP" \
    && ok "github-production-deploy doc names Owner Merge & Deploy" \
    || bad "github-production-deploy.md must name the owner workflow"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "automerge-deploy-handoff-check: ALL PASS"
  exit 0
fi
echo "automerge-deploy-handoff-check: $FAILS FAIL(S)" >&2
exit 1
