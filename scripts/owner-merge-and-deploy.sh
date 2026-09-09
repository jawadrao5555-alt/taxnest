#!/bin/bash
# Owner-triggered squash merge of ONE cursor/* PR, then exact-SHA Deploy Production.
# Does NOT read production secrets, SSH, or skip Elaan.
# Usage (Actions):
#   bash scripts/owner-merge-and-deploy.sh \
#     --pull-number=N \
#     --expected-head-sha=40char \
#     --confirm='Approved — Merge & Deploy'
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

PULL=""
EXPECTED=""
CONFIRM=""
for arg in "$@"; do
  case "$arg" in
    --pull-number=*) PULL="${arg#--pull-number=}" ;;
    --expected-head-sha=*) EXPECTED="${arg#--expected-head-sha=}" ;;
    --confirm=*) CONFIRM="${arg#--confirm=}" ;;
    *) echo "unknown arg: $arg" >&2; exit 2 ;;
  esac
done

[ -n "$PULL" ] || { echo "missing --pull-number" >&2; exit 2; }
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

python3 - "$TMP" "$CONFIRM" "$EXPECTED" "$OWNER" "$NAME" "$MAIN_SHA" <<'PY' > "$TMP/payload.json"
import json, sys
tmpdir, confirm, expected, owner, repo, main_sha = sys.argv[1:]
pr_obj = json.load(open(f"{tmpdir}/pr.json", encoding="utf-8"))
checks_raw = json.load(open(f"{tmpdir}/checks.json", encoding="utf-8"))
checks = checks_raw.get("check_runs") if isinstance(checks_raw, dict) else checks_raw
success = json.load(open(f"{tmpdir}/success.json", encoding="utf-8"))
payload = {
    "confirm": confirm,
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
  echo "==> workflow_dispatch Deploy Production target_sha=$sha (target_sha input only)"
  gh api --method POST "repos/${REPO}/actions/workflows/deploy-production.yml/dispatches" \
    -f ref=main \
    -f "inputs[target_sha]=$sha"
  echo "OWNER MERGE & DEPLOY: dispatched Deploy Production for $sha"
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
