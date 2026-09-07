#!/bin/bash
# Prove the auto-merge → Deploy Production handoff cannot silently disappear.
# Does NOT deploy, SSH, or require secrets.
# Usage: bash scripts/tests/automerge-deploy-handoff-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
DP="$ROOT/.github/workflows/deploy-production.yml"
DOC="$ROOT/docs/ops/cloud-agent-issue-to-live.md"
GHP="$ROOT/docs/ops/github-production-deploy.md"

[ -f "$AM" ] || bad "missing enable-pr-auto-merge.yml"
[ -f "$DP" ] || bad "missing deploy-production.yml"

if [ -f "$AM" ]; then
  grep -q 'createWorkflowDispatch' "$AM" \
    && ok "auto-merge calls createWorkflowDispatch" \
    || bad "auto-merge must createWorkflowDispatch Deploy Production after squash"

  grep -q "workflow_id: 'deploy-production.yml'" "$AM" || grep -q 'workflow_id: "deploy-production.yml"' "$AM" \
    && ok "auto-merge dispatches deploy-production.yml" \
    || bad "auto-merge must target workflow_id deploy-production.yml"

  grep -q 'target_sha' "$AM" \
    && ok "auto-merge passes target_sha input" \
    || bad "auto-merge must pass inputs.target_sha"

  grep -q 'result.sha\|merge_commit_sha' "$AM" \
    && ok "auto-merge uses merge/squash commit SHA (not only PR head)" \
    || bad "auto-merge must hand off the squash/merge commit SHA"

  grep -q 'actions: write' "$AM" \
    && ok "auto-merge has actions: write for workflow_dispatch" \
    || bad "auto-merge permissions.actions must be write"

  # Must document why (GITHUB_TOKEN push suppression)
  grep -qi 'GITHUB_TOKEN\|workflow_dispatch IS\|suppress' "$AM" \
    && ok "auto-merge comments explain GITHUB_TOKEN push suppression" \
    || bad "auto-merge must document why workflow_dispatch handoff exists"

  # Must NOT embed production secrets
  if grep -vE '^\s*#' "$AM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS|environment:\s*production'; then
    bad "auto-merge must not hold production Environment secrets"
  else
    ok "auto-merge does not embed production secrets/Environment"
  fi

  # Immediate squash path must dispatch
  grep -q 'dispatchDeployProduction' "$AM" \
    && ok "auto-merge defines dispatchDeployProduction helper" \
    || bad "missing dispatchDeployProduction helper"

  # Native auto-merge path must eventually hand off
  grep -q 'waitMergedThenDispatch' "$AM" \
    && ok "auto-merge waits/polls native auto-merge then hands off" \
    || bad "native enablePullRequestAutoMerge path must hand off after merge"

  # Reruns of already-merged PRs must not blindly re-dispatch
  grep -qi 'skipping deploy handoff on rerun\|already merged — skipping deploy' "$AM" \
    && ok "already-merged reruns skip duplicate handoff" \
    || bad "already-merged PRs must skip duplicate deploy handoff on rerun"
fi

if [ -f "$DP" ]; then
  grep -q 'target_sha' "$DP" \
    && ok "Deploy Production accepts target_sha input" \
    || bad "Deploy Production must accept workflow_dispatch target_sha"

  grep -q 'steps.resolve.outputs.sha\|resolve.outputs.sha' "$DP" \
    && ok "Deploy Production resolves deploy SHA via outputs" \
    || bad "Deploy Production must resolve DEPLOY_SHA for checkout/apply/verify"

  # Both apply and live-verify must use resolved SHA, not raw github.sha only
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

  grep -q 'merge-base --is-ancestor' "$DP" \
    && ok "Deploy Production refuses SHAs not on origin/main" \
    || bad "Deploy Production must ancestry-check target SHA on main"

  grep -q 'environment: production' "$DP" \
    && ok "Deploy Production keeps environment: production" \
    || bad "must keep Environment production"

  grep -q 'production-deploy' "$DP" \
    && ok "Deploy Production keeps concurrency group" \
    || bad "must keep concurrency protection"

  grep -q 'ci-live-verify.sh' "$DP" \
    && ok "Deploy Production still runs ci-live-verify" \
    || bad "must keep post-deploy live verify"

  # Must still support push to main (human merges)
  grep -A3 '^on:' "$DP" | grep -q 'push:' \
    && ok "Deploy Production still triggers on push to main" \
    || bad "must keep push-to-main trigger for human merges"
fi

if [ -f "$DOC" ]; then
  grep -qi 'workflow_dispatch\|GITHUB_TOKEN\|handoff' "$DOC" \
    && ok "issue-to-live doc mentions handoff/GITHUB_TOKEN/workflow_dispatch" \
    || bad "issue-to-live doc must describe auto-merge deploy handoff"
fi

if [ -f "$GHP" ]; then
  grep -qi 'target_sha\|workflow_dispatch\|GITHUB_TOKEN' "$GHP" \
    && ok "github-production-deploy doc mentions target_sha/handoff" \
    || bad "github-production-deploy.md must document target_sha handoff"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "automerge-deploy-handoff-check: ALL PASS"
  exit 0
fi
echo "automerge-deploy-handoff-check: $FAILS FAIL(S)" >&2
exit 1
