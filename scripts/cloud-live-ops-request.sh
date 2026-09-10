#!/usr/bin/env bash
# Secret-free Cloud Agent helper: request Live Ops Diagnose / owner-command + fetch report.
#
# NEVER uses production SSH/DB/QA secrets.
# NEVER tells the owner to download artifacts from the GitHub UI.
#
# Usage:
#   bash scripts/cloud-live-ops-request.sh --command='Aaj ki report do'
#   bash scripts/cloud-live-ops-request.sh --command='Pizza Master check karo'
#   bash scripts/cloud-live-ops-request.sh --operation=DAILY_OPS
#   bash scripts/cloud-live-ops-request.sh --operation=COMPANY_DIAGNOSTIC --company-name='Pizza Master'
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
COMMAND=""
WAIT=1

for arg in "$@"; do
  case "$arg" in
    --operation=*) OPERATION="${arg#--operation=}" ;;
    --company-id=*) COMPANY_ID="${arg#--company-id=}" ;;
    --company-name=*) COMPANY_NAME="${arg#--company-name=}" ;;
    --date-from=*) DATE_FROM="${arg#--date-from=}" ;;
    --date-to=*) DATE_TO="${arg#--date-to=}" ;;
    --requester=*) REQUESTER="${arg#--requester=}" ;;
    --command=*) COMMAND="${arg#--command=}" ;;
    --no-wait) WAIT=0 ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
  esac
done

if [ -n "$COMMAND" ]; then
  python3 "$ROOT/scripts/lib/live_ops_owner_command.py" "$COMMAND" > /tmp/live-ops-parsed.json || {
    python3 -c 'import json; d=json.load(open("/tmp/live-ops-parsed.json")); print(d.get("error") or "rejected")' >&2
    exit 1
  }
  OPERATION=$(python3 -c 'import json; print(json.load(open("/tmp/live-ops-parsed.json"))["operation"])')
  PARSED_NAME=$(python3 -c 'import json; print(json.load(open("/tmp/live-ops-parsed.json")).get("company_name") or "")')
  [ -n "$PARSED_NAME" ] && [ -z "$COMPANY_NAME" ] && COMPANY_NAME="$PARSED_NAME"
fi

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

WORKFLOW="live-ops-diagnose.yml"
ARTIFACT="live-ops-diagnostic"
if [ -n "$COMMAND" ]; then
  WORKFLOW="live-ops-owner-bridge.yml"
  ARTIFACT="live-ops-owner-command"
  set +e
  gh workflow run live-ops-owner-bridge.yml \
    -f "command=$COMMAND" \
    -f "requester=$REQUESTER"
  RC=$?
  set -e
else
  ARGS=(workflow run live-ops-diagnose.yml
    -f "operation=$OPERATION"
    -f "requester=$REQUESTER"
  )
  [ -n "$COMPANY_ID" ] && ARGS+=(-f "company_id=$COMPANY_ID")
  [ -n "$COMPANY_NAME" ] && ARGS+=(-f "company_name=$COMPANY_NAME")
  [ -n "$DATE_FROM" ] && ARGS+=(-f "date_from=$DATE_FROM")
  [ -n "$DATE_TO" ] && ARGS+=(-f "date_to=$DATE_TO")
  set +e
  gh "${ARGS[@]}"
  RC=$?
  set -e
fi

if [ "$RC" -ne 0 ]; then
  echo "workflow_dispatch failed (exit $RC)." >&2
  echo "If this is HTTP 403, the GitHub App token lacks actions: write." >&2
  echo "Use the repository issue bridge instead (no PAT required):" >&2
  echo "  Open an issue titled: [TAXNEST-OPS] ${COMMAND:-$OPERATION}" >&2
  echo "  Minimum ChatGPT GitHub App permission: issues write (and actions: write to skip the issue)." >&2
  echo "Do not paste production secrets. Do not use a PAT in chat." >&2
  exit 3
fi

echo "Dispatched $WORKFLOW (Environment production approval may be required once per environment)."

if [ "$WAIT" -eq 1 ]; then
  echo "Waiting for workflow run…"
  sleep 5
  RUN_ID=$(gh run list --workflow="$WORKFLOW" --limit 1 --json databaseId -q '.[0].databaseId' || true)
  if [ -n "$RUN_ID" ]; then
    bash "$ROOT/scripts/live-ops-fetch-report.sh" --run-id="$RUN_ID" --workflow="$WORKFLOW" --artifact="$ARTIFACT" \
      || echo "Run $RUN_ID did not yield a parsed report yet (Environment approval or still running)."
  fi
fi
