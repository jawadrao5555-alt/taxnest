#!/bin/bash
# Owner-triggered squash merge of ONE cursor/* PR, then exact-SHA Deploy Production.
# Does NOT read production secrets, SSH, or skip Elaan.
# Usage (Actions):
#   bash scripts/owner-merge-and-deploy.sh \
#     --pull-number=N \
#     --expected-head-sha=40char \
#     --approval-request-id=relay-issued-id
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

PULL=""
EXPECTED=""
APPROVAL_REQUEST_ID=""
HANDOFF_NONCE="$(openssl rand -hex 16)"
echo "$HANDOFF_NONCE" | grep -Eq '^[0-9a-f]{32}$' || { echo "failed to generate handoff nonce" >&2; exit 2; }
for arg in "$@"; do
  case "$arg" in
    --pull-number=*) PULL="${arg#--pull-number=}" ;;
    --expected-head-sha=*) EXPECTED="${arg#--expected-head-sha=}" ;;
    --approval-request-id=*) APPROVAL_REQUEST_ID="${arg#--approval-request-id=}" ;;
    *) echo "unknown arg: $arg" >&2; exit 2 ;;
  esac
done

[ -n "$PULL" ] || { echo "missing --pull-number" >&2; exit 2; }
[ -n "$EXPECTED" ] || { echo "missing --expected-head-sha" >&2; exit 2; }
[ -n "$APPROVAL_REQUEST_ID" ] || { echo "missing --approval-request-id" >&2; exit 2; }
[ -n "${OWNER_APPROVAL_RELAY_URL:-}" ] || { echo "OWNER_APPROVAL_RELAY_URL repository variable is required" >&2; exit 2; }
echo "$PULL" | grep -Eq '^[0-9]+$' || { echo "pull_number must be numeric" >&2; exit 2; }
[ -n "${GH_TOKEN:-${GITHUB_TOKEN:-}}" ] || { echo "GH_TOKEN required" >&2; exit 2; }

REPO="${GITHUB_REPOSITORY:-}"
[ -n "$REPO" ] || REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner)
OWNER="${REPO%%/*}"
NAME="${REPO#*/}"
TMP=$(mktemp -d /tmp/owner-merge.XXXXXX)
trap 'rm -rf "$TMP"' EXIT

echo "==> Fetch PR #${PULL} and origin/main tip"
gh api "repos/${REPO}/pulls/${PULL}" > "$TMP/pr.json"
MAIN_SHA=$(gh api "repos/${REPO}/commits/main" --jq .sha)
HEAD_SHA=$(python3 -c 'import json; print(json.load(open("'"$TMP"'/pr.json"))["head"]["sha"])')
gh api "repos/${REPO}/commits/${HEAD_SHA}/check-runs?per_page=100" > "$TMP/checks.json"
gh run list --repo "$REPO" --workflow 'Deploy Production' --status success --limit 20 \
  --json headSha,conclusion --jq '[.[] | select(.conclusion=="success") | .headSha] | unique' \
  > "$TMP/success.json" 2>/dev/null || echo '[]' > "$TMP/success.json"

OIDC_TOKEN=$(curl --fail --silent --show-error \
  --retry 5 \
  --retry-delay 2 \
  --retry-all-errors \
  -H "Authorization: bearer ${ACTIONS_ID_TOKEN_REQUEST_TOKEN}" \
  "${ACTIONS_ID_TOKEN_REQUEST_URL}&audience=owner-approval-relay" | python3 -c 'import json,sys; print(json.load(sys.stdin)["value"])')
RELAY_URL="${OWNER_APPROVAL_RELAY_URL%/}"
jq -n --arg id "$APPROVAL_REQUEST_ID" --arg repo "$REPO" \
  --argjson pull "$PULL" --arg sha "$EXPECTED" \
  '{approval_request_id:$id,repository:$repo,pull_number:$pull,expected_head_sha:$sha}' > "$TMP/claim-request.json"
curl --fail --silent --show-error \
  -H "Authorization: Bearer ${OIDC_TOKEN}" -H "Content-Type: application/json" \
  --data-binary @"$TMP/claim-request.json" \
  "${RELAY_URL}/api/deployment-approval/v1/approval-claims" > "$TMP/claim.json"
RECEIPT=$(python3 - "$TMP/claim.json" <<'PY'
import json, sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
v=d.get("provenance_receipt") or d.get("receipt")
if not isinstance(v, str) or not v:
    raise SystemExit("relay response did not contain provenance_receipt")
print(v)
PY
)
echo "::add-mask::${RECEIPT}"

python3 - "$TMP" "$APPROVAL_REQUEST_ID" "$RECEIPT" "$EXPECTED" "$OWNER" "$NAME" "$MAIN_SHA" <<'PY' > "$TMP/payload.json"
import json, sys
tmpdir, approval_id, receipt, expected, owner, repo, main_sha = sys.argv[1:]
pr_obj = json.load(open(f"{tmpdir}/pr.json", encoding="utf-8"))
checks_raw = json.load(open(f"{tmpdir}/checks.json", encoding="utf-8"))
checks = checks_raw.get("check_runs") if isinstance(checks_raw, dict) else checks_raw
success = json.load(open(f"{tmpdir}/success.json", encoding="utf-8"))
payload = {
    "approval_request_id": approval_id,
    "provenance_receipt": receipt,
    "expected_head_sha": expected,
    "owner": owner,
    "repo": repo,
    "origin_main_sha": main_sha,
    "check_runs": checks,
    "successful_deploy_shas": success,
    "pr": {
        "draft": pr_obj.get("draft"),
        "merged": pr_obj.get("merged"),
        "mergeable": pr_obj.get("mergeable"),
        "mergeable_state": pr_obj.get("mergeable_state"),
        "merge_commit_sha": pr_obj.get("merge_commit_sha"),
        "base": {"ref": (pr_obj.get("base") or {}).get("ref")},
        "head": {
            "ref": (pr_obj.get("head") or {}).get("ref"),
            "sha": (pr_obj.get("head") or {}).get("sha"),
            "repo": {"full_name": ((pr_obj.get("head") or {}).get("repo") or {}).get("full_name")},
        },
    },
}
json.dump(payload, sys.stdout)
PY

set +e
python3 "$ROOT/scripts/lib/owner-merge-and-deploy.py" --input-json "$TMP/payload.json" > "$TMP/decision.json"
DECIDE_RC=$?
set -e
cat "$TMP/decision.json"
ACTION=$(python3 -c 'import json; print(json.load(open("'"$TMP"'/decision.json"))["action"])')
REASON=$(python3 -c 'import json; print(json.load(open("'"$TMP"'/decision.json"))["reason"])')
DISPATCH_SHA=$(python3 -c 'import json; print(json.load(open("'"$TMP"'/decision.json")).get("dispatch_sha") or "")')
MERGE_HEAD=$(python3 -c 'import json; print(json.load(open("'"$TMP"'/decision.json")).get("merge_head_sha") or "")')
echo "==> decision: $ACTION — $REASON"

if [ "$ACTION" = "reject" ] || [ "$DECIDE_RC" -eq 2 ]; then
  echo "OWNER MERGE & DEPLOY REJECTED: $REASON" >&2
  exit 1
fi

dispatch_deploy() {
  local sha="$1"
  echo "==> Verify ${sha} is current origin/main tip"
  TIP=$(gh api "repos/${REPO}/commits/main" --jq .sha)
  python3 - "$TIP" "$sha" <<'PY'
import sys
tip, sha = sys.argv[1].lower(), sys.argv[2].lower()
if tip != sha:
    sys.stderr.write(f"REFUSING deploy: squash/merge SHA {sha} != origin/main tip {tip}\n")
    sys.exit(1)
PY
  jq -n --arg id "$APPROVAL_REQUEST_ID" --arg receipt "$RECEIPT" \
    --arg repo "$REPO" --arg sha "$sha" \
    '{approval_request_id:$id,provenance_receipt:$receipt,repository:$repo,merge_sha:$sha}' > "$TMP/merge-complete-request.json"
  curl --fail --silent --show-error \
    -H "Authorization: Bearer ${OIDC_TOKEN}" -H "Content-Type: application/json" \
    --data-binary @"$TMP/merge-complete-request.json" \
    "${RELAY_URL}/api/deployment-approval/v1/merge-complete" > "$TMP/merge-complete.json"
   echo "==> workflow_dispatch Deploy Production with relay provenance"
  DISPATCH_STARTED=$(date -u +%s)
  gh api --method POST "repos/${REPO}/actions/workflows/deploy-production.yml/dispatches" \
    -f ref=main \
     -f "inputs[target_sha]=$sha" \
     -f "inputs[approval_request_id]=$APPROVAL_REQUEST_ID" \
     -f "inputs[handoff_nonce]=$HANDOFF_NONCE"
  RUN_ID=""
  for _ in $(seq 1 30); do
    RUN_ID=$(gh run list --repo "$REPO" --workflow deploy-production.yml --limit 50 \
      --json databaseId,displayTitle,headSha,createdAt |
      jq -r --arg title "Deploy Production — approval ${APPROVAL_REQUEST_ID} — nonce ${HANDOFF_NONCE}" --arg sha "$sha" --argjson started "$DISPATCH_STARTED" \
        '[.[] | select(.displayTitle == $title and (.headSha|ascii_downcase == ($sha|ascii_downcase)) and ((.createdAt|fromdateiso8601) >= $started))] | if length == 0 then empty else min_by(.databaseId).databaseId end')
    [ -n "$RUN_ID" ] && break
    sleep 2
  done
  [ -n "$RUN_ID" ] || { echo "Deploy Production run was not registered for approval ${APPROVAL_REQUEST_ID}" >&2; exit 1; }
  jq -n --arg id "$APPROVAL_REQUEST_ID" --arg receipt "$RECEIPT" \
    --arg repo "$REPO" --arg sha "$sha" --arg nonce "$HANDOFF_NONCE" --arg run "$RUN_ID" \
    '{approval_request_id:$id,provenance_receipt:$receipt,repository:$repo,merge_sha:$sha,handoff_nonce:$nonce,deployment_run_id:($run|tonumber),deployment_run_attempt:1}' > "$TMP/deploy-run.json"
  curl --fail --silent --show-error --retry 3 --retry-all-errors \
    -H "Authorization: Bearer ${OIDC_TOKEN}" \
    -H "Content-Type: application/json" --data-binary @"$TMP/deploy-run.json" \
    "${RELAY_URL}/api/deployment-approval/v1/deploy-run"
  echo "OWNER MERGE & DEPLOY: dispatched Deploy Production for $sha (run $RUN_ID)"
}

if [ "$ACTION" = "noop" ]; then
  echo "OWNER MERGE & DEPLOY: $REASON"
  exit 0
fi

if [ "$ACTION" = "dispatch_only" ]; then
  dispatch_deploy "$DISPATCH_SHA"
  exit 0
fi

PIN="${MERGE_HEAD:-$EXPECTED}"
echo "==> Squash-merge PR #${PULL} pinned to $PIN"
gh api --method PUT "repos/${REPO}/pulls/${PULL}/merge" \
  -f merge_method=squash \
  -f sha="$PIN" > "$TMP/merge.json"
python3 - "$TMP/merge.json" > "$TMP/merge.sha" <<'PY'
import json, sys
data = json.load(open(sys.argv[1], encoding="utf-8"))
if not data.get("merged"):
    raise SystemExit("squash merge response missing merged=true")
print(data["sha"])
PY
MERGE_SHA=$(cat "$TMP/merge.sha")
echo "==> squash SHA $MERGE_SHA"
dispatch_deploy "$MERGE_SHA"
