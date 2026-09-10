#!/usr/bin/env bash
# Classify a cursor/* PR; if AUTO_DEPLOY, ask production (runner token) to
# approve via the existing owner-approval relay. Does NOT squash-merge here,
# does NOT dispatch Deploy Production, does NOT skip Elaan, does NOT SSH.
# Owner Merge & Deploy remains the only merge+exact-SHA deploy initiator.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

PULL=""
EXPECTED=""
for arg in "$@"; do
  case "$arg" in
    --pull-number=*) PULL="${arg#--pull-number=}" ;;
    --expected-head-sha=*) EXPECTED="${arg#--expected-head-sha=}" ;;
    *) echo "unknown arg: $arg" >&2; exit 2 ;;
  esac
done
echo "$PULL" | grep -Eq '^[0-9]+$' || { echo "pull_number must be numeric" >&2; exit 2; }
EXPECTED="$(printf '%s' "$EXPECTED" | tr 'A-F' 'a-f')"
echo "$EXPECTED" | grep -Eq '^[0-9a-f]{40}$' || { echo "head sha must be 40-char hex" >&2; exit 2; }
mkdir -p artifacts
[ -n "${GH_TOKEN:-${GITHUB_TOKEN:-}}" ] || { echo "GH_TOKEN required to read PR files" >&2; exit 2; }
[ -n "${LIVE_OPS_RUNNER_TOKEN:-}" ] || { echo "LIVE_OPS_RUNNER_TOKEN required (Environment only)" >&2; exit 2; }
[ -n "${LIVE_OPS_BASE_URL:-}" ] || { echo "LIVE_OPS_BASE_URL required" >&2; exit 2; }

REPO="${GITHUB_REPOSITORY:-}"
[ -n "$REPO" ] || REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner)

gh api "repos/${REPO}/pulls/${PULL}" > /tmp/safe-pr.json
python3 - "$EXPECTED" <<'PY'
import json, sys
pr=json.load(open("/tmp/safe-pr.json", encoding="utf-8"))
exp=sys.argv[1].lower()
head=(pr.get("head") or {})
sha=(head.get("sha") or "").lower()
ref=head.get("ref") or ""
if sha != exp:
    raise SystemExit(f"PR HEAD moved live={sha} expected={exp}")
if not ref.startswith("cursor/"):
    raise SystemExit("non-cursor/ branch rejected")
if pr.get("draft"):
    raise SystemExit("draft PR rejected")
if (pr.get("base") or {}).get("ref") != "main":
    raise SystemExit("PR must target main")
print("pr ok", ref, sha)
PY

gh api --paginate "repos/${REPO}/pulls/${PULL}/files" --jq '.[].filename' > /tmp/safe-paths.txt
python3 "$ROOT/scripts/lib/live_ops_risk_classify.py" < /tmp/safe-paths.txt | tee /tmp/safe-risk.json
python3 - <<'PY'
import json, sys
d=json.load(open("/tmp/safe-risk.json", encoding="utf-8"))
if d.get("class") != "AUTO_DEPLOY":
    print("HIGH_RISK_BLOCKED:", d.get("reason"), file=sys.stderr)
    sys.exit(1)
PY

BODY=$(python3 - <<PY
import json
paths=[ln.strip() for ln in open("/tmp/safe-paths.txt", encoding="utf-8") if ln.strip()]
print(json.dumps({
  "pull_request_number": int("$PULL"),
  "head_sha": "$EXPECTED",
  "paths": paths,
  "requester": "live-ops-safe-auto-merge",
}))
PY
)
curl -fsS -X POST "${LIVE_OPS_BASE_URL%/}/api/live-ops/v1/safe-auto-deploy" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Live-Ops-Token: ${LIVE_OPS_RUNNER_TOKEN}" \
  -d "$BODY" | tee artifacts/live-ops-safe-auto-deploy.json
python3 - <<'PY'
import json
d=json.load(open("artifacts/live-ops-safe-auto-deploy.json", encoding="utf-8"))
if not d.get("ok"):
    raise SystemExit(d.get("error") or "safe-auto-deploy failed")
print("approval_request_id", d.get("approval_request_id"), "status", d.get("status"))
print("Relay approved. Approval Relay Dispatch / Owner Merge & Deploy will merge the exact SHA.")
PY
