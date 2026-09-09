#!/usr/bin/env bash
# Request owner-controlled squash-merge + exact-SHA Deploy Production.
#
# After the owner EXPLICITLY approves in chat (canonical phrase or an
# allow-listed alias), the Cloud Agent runs this script. It:
#   1. Re-reads the PR (number, READY, targets main, mergeable, HEAD SHA).
#   2. Runs the same decide() gates as GitHub Actions (dry, no mutate).
#   3. Prints the exact PR number and HEAD SHA being sent.
#   4. Attempts `gh workflow run owner-merge-and-deploy.yml`.
#
# Exit codes:
#   0 — workflow_dispatch accepted.
#   2 — PR/SHA/gates rejected (do not merge, do not deploy).
#   3 — gates passed but dispatch is 403 (App token cannot start workflows).
#       Print the Actions UI fill-in values. Owner clicks Run workflow.
#
# NEVER:
#   gh pr merge / GitHub Merge button
#   gh workflow run deploy-production.yml
#   SSH to production
#   enable auto-merge
#   force-push / reset / clean
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

usage() {
  cat <<'EOF'
Usage:
  scripts/owner-merge-and-deploy-request.sh <pull_number> <expected_head_sha> [confirm_phrase]

Default confirm phrase: Approved — Merge & Deploy
Allow-listed aliases (must match exactly, including spaces/case):
  Deploy kar do
  Live kar do
  Approved, put it live
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

PULL_NUMBER="${1:-}"
EXPECTED_HEAD_SHA="${2:-}"
CONFIRM="${3:-Approved — Merge & Deploy}"

if [[ -z "${PULL_NUMBER}" || -z "${EXPECTED_HEAD_SHA}" ]]; then
  usage
  exit 2
fi
if [[ ! "${PULL_NUMBER}" =~ ^[0-9]+$ ]]; then
  echo "ERROR: pull_number must be a positive integer (got ${PULL_NUMBER})." >&2
  exit 2
fi
if [[ ! "${EXPECTED_HEAD_SHA}" =~ ^[0-9a-fA-F]{40}$ ]]; then
  echo "ERROR: expected_head_sha must be a 40-character hex SHA." >&2
  exit 2
fi
EXPECTED_HEAD_SHA="$(printf '%s' "${EXPECTED_HEAD_SHA}" | tr 'A-F' 'a-f')"
if ! command -v gh >/dev/null 2>&1; then
  echo "ERROR: gh CLI required." >&2
  exit 2
fi
if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 required." >&2
  exit 2
fi

REPO="${GITHUB_REPOSITORY:-}"
if [[ -z "${REPO}" ]]; then
  REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner)"
fi
OWNER="${REPO%%/*}"
NAME="${REPO#*/}"
TMP="$(mktemp -d /tmp/owner-merge-request.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

echo "==> Owner Merge & Deploy request"
echo "    repo=${REPO}"
echo "    pull_number=${PULL_NUMBER}"
echo "    expected_head_sha=${EXPECTED_HEAD_SHA}"
echo "    confirm=${CONFIRM}"
echo

echo "==> Fetch PR #${PULL_NUMBER} and origin/main tip (read-only)"
gh api "repos/${REPO}/pulls/${PULL_NUMBER}" > "$TMP/pr.json"
HEAD_SHA="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8"))["head"]["sha"])' "$TMP/pr.json")"
gh api "repos/${REPO}/commits/${HEAD_SHA}/check-runs?per_page=100" > "$TMP/checks.json"
MAIN_SHA="$(gh api "repos/${REPO}/commits/main" --jq .sha)"

python3 - "$TMP/pr.json" "$MAIN_SHA" <<'PY'
import json, sys
pr = json.load(open(sys.argv[1], encoding="utf-8"))
head = pr.get("head") or {}
base = pr.get("base") or {}
print("==> Live PR snapshot")
print(f"    url={pr.get('html_url') or ''}")
print(f"    head_ref={head.get('ref') or ''}")
print(f"    head.sha={head.get('sha') or ''}")
print(f"    base.ref={base.get('ref') or ''}")
print(
    "    draft={0} merged={1} state={2}".format(
        pr.get("draft"), pr.get("merged"), pr.get("state")
    )
)
print(
    "    mergeable={0} mergeable_state={1}".format(
        pr.get("mergeable"), pr.get("mergeable_state")
    )
)
print(f"==> origin/main tip {sys.argv[2]}")
print()
PY

set +e
python3 "$ROOT/scripts/lib/owner-merge-and-deploy.py" \
  --pr-json "$TMP/pr.json" \
  --checks-json "$TMP/checks.json" \
  --confirm "$CONFIRM" \
  --expected-head-sha "$EXPECTED_HEAD_SHA" \
  --owner "$OWNER" \
  --repo "$NAME" \
  --main-sha "$MAIN_SHA" \
  > "$TMP/decision.json"
DECIDE_RC=$?
set -e

python3 - "$TMP/decision.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
print(f"==> decide() action={d.get('action')} ok={d.get('action') != 'reject'}")
print(f"    {d.get('reason')}")
print()
PY
cat "$TMP/decision.json"
echo

if [[ "${DECIDE_RC}" -eq 2 ]]; then
  echo "ERROR: Owner Merge & Deploy request rejected. Do not merge. Do not deploy." >&2
  exit 2
fi
if [[ "${DECIDE_RC}" -ne 0 ]]; then
  echo "ERROR: decide() failed (exit ${DECIDE_RC}). Do not merge. Do not deploy." >&2
  exit 2
fi

ACTION="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8")).get("action") or "")' "$TMP/decision.json")"
if [[ "${ACTION}" == "reject" ]]; then
  echo "ERROR: Owner Merge & Deploy request rejected. Do not merge. Do not deploy." >&2
  exit 2
fi

echo "=============================================="
echo "OWNER-CONTROLLED DEPLOYMENT REQUEST"
echo "  PR number : ${PULL_NUMBER}"
echo "  HEAD SHA  : ${EXPECTED_HEAD_SHA}"
echo "  confirm   : ${CONFIRM}"
echo "=============================================="
echo
echo "==> Attempting gh workflow run owner-merge-and-deploy.yml (no merge, no Deploy Production)..."

set +e
DISPATCH_OUT="$(
  gh workflow run owner-merge-and-deploy.yml \
    --ref main \
    -f "pull_number=${PULL_NUMBER}" \
    -f "expected_head_sha=${EXPECTED_HEAD_SHA}" \
    -f "confirm=${CONFIRM}" \
    2>&1
)"
DISPATCH_RC=$?
set -e

if [[ "${DISPATCH_RC}" -eq 0 ]]; then
  echo "OK: workflow_dispatch accepted. Do not click Merge. Do not start Deploy Production."
  echo "${DISPATCH_OUT}"
  exit 0
fi

echo "${DISPATCH_OUT}" >&2
if printf '%s' "${DISPATCH_OUT}" | grep -Eqi 'HTTP 403|Resource not accessible by integration'; then
  echo
  echo "ERROR: Cloud Agent token cannot dispatch workflows (HTTP 403)." >&2
  echo "Gates PASSED. Owner must click Run workflow with these exact values:" >&2
  echo "  Workflow          : Owner Merge & Deploy" >&2
  echo "  Use workflow from : main" >&2
  echo "  pull_number       : ${PULL_NUMBER}" >&2
  echo "  expected_head_sha : ${EXPECTED_HEAD_SHA}" >&2
  echo "  confirm           : ${CONFIRM}" >&2
  echo "  URL               : https://github.com/${REPO}/actions/workflows/owner-merge-and-deploy.yml" >&2
  echo
  echo "Do not use the GitHub PR Merge button." >&2
  echo "Do not start Deploy Production yourself." >&2
  exit 3
fi

echo "ERROR: workflow_dispatch failed (exit ${DISPATCH_RC}). Do not merge. Do not deploy." >&2
exit 2
