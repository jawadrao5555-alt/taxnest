#!/bin/bash
# Secret-free observation helper for Cloud Agents after a cursor/* PR merges.
#
# WHAT THIS DOES:
#   - Reads GitHub Actions "Deploy Production" run status for a main SHA (gh CLI)
#   - Optionally probes public https://taxnest.pk/up (no auth, no customer data)
#
# WHAT THIS NEVER DOES:
#   - SSH to production
#   - Read PRODUCTION_SSH_PRIVATE_KEY / LIVE_QA_PASS / .env
#   - Login as live QA
#   - Approve Environment "production"
#   - Claim LIVE VERIFIED (only Actions ci-live-verify may)
#
# Usage:
#   bash scripts/cloud-issue-to-live-observe.sh
#   bash scripts/cloud-issue-to-live-observe.sh --sha=<40-char>
#   bash scripts/cloud-issue-to-live-observe.sh --sha=origin/main
#
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

SHA=""
PROBE_UP=1
for arg in "$@"; do
  case "$arg" in
    --sha=*) SHA="${arg#--sha=}" ;;
    --no-up) PROBE_UP=0 ;;
    -h|--help)
      sed -n '2,22p' "$0"
      exit 0
      ;;
  esac
done

if [ -z "$SHA" ]; then
  SHA=$(git rev-parse origin/main 2>/dev/null || git rev-parse main 2>/dev/null || true)
fi
if [ "$SHA" = "origin/main" ] || [ "$SHA" = "main" ]; then
  SHA=$(git rev-parse "$SHA")
fi

echo "cloud-issue-to-live-observe"
echo "  target SHA: ${SHA:-unknown}"

if ! command -v gh >/dev/null 2>&1; then
  echo "  WARN: gh CLI not available — cannot list Deploy Production runs" >&2
else
  echo ""
  echo "==> Recent Deploy Production workflow runs"
  gh run list --workflow=deploy-production.yml --limit 8 2>/dev/null \
    || gh run list --workflow="Deploy Production" --limit 8 2>/dev/null \
    || echo "  (could not list runs)"

  if [ -n "$SHA" ]; then
    echo ""
    echo "==> Runs matching SHA ${SHA:0:12}…"
    # Best-effort: show any run whose headSha matches
    gh run list --workflow=deploy-production.yml --limit 30 --json databaseId,headSha,conclusion,status,url,createdAt,displayTitle \
      2>/dev/null | python3 - "$SHA" <<'PY' || echo "  (SHA filter unavailable)"
import json, sys
sha = sys.argv[1].lower()
try:
    rows = json.load(sys.stdin)
except Exception as e:
    print("  (parse failed)", e)
    sys.exit(0)
hits = [r for r in rows if (r.get("headSha") or "").lower() == sha]
if not hits:
    print("  no Deploy Production run found yet for this SHA (waiting for merge/approval?)")
else:
    for r in hits:
        print(
            f"  id={r.get('databaseId')} status={r.get('status')} "
            f"conclusion={r.get('conclusion')} url={r.get('url')}"
        )
PY
  fi
fi

if [ "$PROBE_UP" = "1" ]; then
  echo ""
  echo "==> Public /up probe (no auth; not LIVE VERIFIED by itself)"
  CODE=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 https://taxnest.pk/up 2>/dev/null || echo "000")
  echo "  https://taxnest.pk/up → HTTP $CODE"
  echo "  NOTE: HTTP 200 alone is NOT live verification of the reported issue."
fi

echo ""
echo "Next (Cloud Agent, secret-free):"
echo "  - If Deploy Production conclusion=failure → start a NEW diagnosis cycle"
echo "    (docs/ops/cloud-agent-issue-to-live.md). Do not claim LIVE VERIFIED."
echo "  - If conclusion=success → LIVE VERIFIED only means CI live-verify passed;"
echo "    still report the SHA + Actions URL in the agent summary."
echo "  - Never approve Environment production; never fetch production secrets."
