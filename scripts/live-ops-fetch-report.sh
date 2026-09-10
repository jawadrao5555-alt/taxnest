#!/usr/bin/env bash
# Poll a Live Ops GitHub Actions run and print the parsed owner report.
# Never tells the owner to download artifacts from the GitHub UI.
# Does not print secrets. Does not SSH.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

RUN_ID=""
WORKFLOW="live-ops-diagnose.yml"
ARTIFACT="live-ops-diagnostic"
TIMEOUT=900
SLEEP=10

for arg in "$@"; do
  case "$arg" in
    --run-id=*) RUN_ID="${arg#--run-id=}" ;;
    --workflow=*) WORKFLOW="${arg#--workflow=}" ;;
    --artifact=*) ARTIFACT="${arg#--artifact=}" ;;
    --timeout=*) TIMEOUT="${arg#--timeout=}" ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
  esac
done

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI required" >&2
  exit 1
fi

if [ -z "$RUN_ID" ]; then
  RUN_ID=$(gh run list --workflow="$WORKFLOW" --limit 1 --json databaseId -q '.[0].databaseId')
fi
echo "$RUN_ID" | grep -Eq '^[0-9]+$' || { echo "run id missing or not numeric" >&2; exit 1; }

echo "live-ops-fetch-report run_id=$RUN_ID workflow=$WORKFLOW"
START=$(date +%s)
while true; do
  STATUS=$(gh run view "$RUN_ID" --json status,conclusion -q '.status + ":" + (.conclusion // "")')
  echo "  status=$STATUS"
  case "$STATUS" in
    completed:success) break ;;
    completed:*)
      echo "Diagnostic run $RUN_ID finished: $STATUS" >&2
      gh run view "$RUN_ID" --log-failed 2>/dev/null | tail -n 80 >&2 || true
      exit 1
      ;;
  esac
  NOW=$(date +%s)
  if [ $((NOW - START)) -ge "$TIMEOUT" ]; then
    echo "Timed out waiting for run $RUN_ID" >&2
    exit 1
  fi
  sleep "$SLEEP"
done

mkdir -p artifacts/live-ops-download
gh run download "$RUN_ID" -n "$ARTIFACT" -D artifacts/live-ops-download/ || {
  echo "Could not download artifact $ARTIFACT from run $RUN_ID" >&2
  exit 1
}

python3 - "$RUN_ID" <<'PY'
import json, os, sys
run_id = sys.argv[1]
base = "artifacts/live-ops-download"
candidates = [
    os.path.join(base, "live-ops-owner-command.json"),
    os.path.join(base, "live-ops-diagnostic.json"),
    os.path.join(base, "owner-report.txt"),
]
data = None
path = None
for p in candidates:
    if os.path.isfile(p):
        path = p
        break
if path is None:
    # nested download dir
    for root, _, files in os.walk(base):
        for f in files:
            if f.endswith(".json"):
                path = os.path.join(root, f)
                break
        if path:
            break
if not path:
    print("No report file in artifact", file=sys.stderr)
    sys.exit(1)
with open(path, encoding="utf-8") as fh:
    raw = fh.read()
try:
    data = json.loads(raw)
except json.JSONDecodeError:
    print(raw)
    sys.exit(0)

# Engine result
if isinstance(data, dict) and "owner_report" in data:
    report = data["owner_report"] or {}
    print(report.get("text") or json.dumps(report, indent=2, ensure_ascii=False))
    print("")
    print(f"request_id: {data.get('request_id')}  status: {data.get('status')}  run: {run_id}")
    sys.exit(0)

# Diagnose envelope
if isinstance(data, dict) and data.get("operation"):
    cards = (data.get("data") or {}).get("owner_company_cards") or []
    if cards:
        lines = ["TaxNest daily report", f"Overall: {(data.get('data') or {}).get('overall', {}).get('status', '')}", ""]
        for c in cards:
            lines += [
                c.get("name") or "Company",
                f"Billing: Rs. {int(round(float(c.get('billing_amount') or 0))):,}",
                f"Bills: {int(c.get('bill_count') or 0)}",
                f"Printing: {c.get('printer') or 'UNKNOWN'}",
                f"Agent: {c.get('agent') or 'UNKNOWN'}",
                f"PRA: {c.get('pra') or 'UNKNOWN'}",
                f"Issues: {c.get('issues_line') or 'None'}",
                "",
            ]
        print("\n".join(lines).rstrip())
    else:
        print(data.get("summary_text") or "Diagnostic completed.")
    print("")
    print(f"report_id: {data.get('report_id')}  digest: {data.get('artifact_digest')}  run: {run_id}")
    sys.exit(0)

print(json.dumps(data, indent=2, ensure_ascii=False)[:4000])
PY
