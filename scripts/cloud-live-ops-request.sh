#!/usr/bin/env bash
# Secret-free Cloud Agent helper: request Live Ops Diagnose workflow + fetch artifact.
#
# NEVER uses production SSH/DB/QA secrets.
#
# Usage:
#   bash scripts/cloud-live-ops-request.sh --operation=DAILY_OPS
#   bash scripts/cloud-live-ops-request.sh --operation=BILLING_BY_COMPANY --date-from=2026-09-07 --date-to=2026-09-07
#   bash scripts/cloud-live-ops-request.sh --operation=COMPANY_DIAGNOSTIC --company-id=35
#
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

OPERATION="DAILY_OPS"
COMPANY_ID=""
COMPANY_NAME=""
DATE_FROM=""
DATE_TO=""
REQUESTER="cloud-agent"
WAIT=1

for arg in "$@"; do
  case "$arg" in
    --operation=*) OPERATION="${arg#--operation=}" ;;
    --company-id=*) COMPANY_ID="${arg#--company-id=}" ;;
    --company-name=*) COMPANY_NAME="${arg#--company-name=}" ;;
    --date-from=*) DATE_FROM="${arg#--date-from=}" ;;
    --date-to=*) DATE_TO="${arg#--date-to=}" ;;
    --requester=*) REQUESTER="${arg#--requester=}" ;;
    --no-wait) WAIT=0 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
  esac
done

case "$OPERATION" in
  DAILY_OPS|SERVER_HEALTH|COMPANY_HEALTH|BILLING_SUMMARY|BILLING_BY_COMPANY|PRA_HEALTH|AGENT_HEALTH|PRINTER_HEALTH|ERROR_SUMMARY|COMPANY_DIAGNOSTIC|PROBLEMATIC_COMPANIES) ;;
  *) echo "Disallowed operation: $OPERATION" >&2; exit 1 ;;
esac
case "$OPERATION" in
  COMPANY_HEALTH|PRINTER_HEALTH|COMPANY_DIAGNOSTIC)
    if [ -z "$COMPANY_ID" ] && [ -z "$COMPANY_NAME" ]; then
      echo "company_id or company_name is required for $OPERATION" >&2
      exit 1
    fi
    ;;
esac

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI required" >&2
  exit 1
fi

echo "cloud-live-ops-request"
echo "  operation=$OPERATION company_id=${COMPANY_ID:-} company_name=${COMPANY_NAME:-}"

ARGS=(workflow run live-ops-diagnose.yml
  -f "operation=$OPERATION"
  -f "requester=$REQUESTER"
)
[ -n "$COMPANY_ID" ] && ARGS+=(-f "company_id=$COMPANY_ID")
[ -n "$COMPANY_NAME" ] && ARGS+=(-f "company_name=$COMPANY_NAME")
[ -n "$DATE_FROM" ] && ARGS+=(-f "date_from=$DATE_FROM")
[ -n "$DATE_TO" ] && ARGS+=(-f "date_to=$DATE_TO")

gh "${ARGS[@]}"
echo "Dispatched Live Ops Diagnose (Environment production approval may be required)."

if [ "$WAIT" -eq 1 ]; then
  echo "Waiting for latest workflow run…"
  sleep 5
  RUN_ID=$(gh run list --workflow=live-ops-diagnose.yml --limit 1 --json databaseId -q '.[0].databaseId')
  if [ -n "$RUN_ID" ]; then
    gh run watch "$RUN_ID" --exit-status || true
    mkdir -p artifacts/live-ops-download
    gh run download "$RUN_ID" -n live-ops-diagnostic -D artifacts/live-ops-download/ || true
    if [ -f artifacts/live-ops-download/live-ops-diagnostic.json ]; then
      echo "Report at artifacts/live-ops-download/live-ops-diagnostic.json"
      python3 - <<'PY'
import json
p="artifacts/live-ops-download/live-ops-diagnostic.json"
d=json.load(open(p))
print("summary:", d.get("summary_text"))
print("report_id:", d.get("report_id"))
print("digest:", d.get("artifact_digest"))
PY
    else
      echo "Artifact not ready yet — check Actions UI / Environment approval."
    fi
  fi
fi
