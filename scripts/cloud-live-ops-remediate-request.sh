#!/usr/bin/env bash
# Secret-free Cloud Agent helper: propose or request approve+execute remediation.
# Owner must approve GitHub Environment "production" AND supply OWNER_APPROVES_LIVE_OPS_FIX.
#
# Usage:
#   bash scripts/cloud-live-ops-remediate-request.sh --mode=propose --action=ENQUEUE_AGENT_COMMAND --company-id=35 --params='{"command_type":"RESYNC"}'
#   bash scripts/cloud-live-ops-remediate-request.sh --mode=approve_execute --action-id=01H... --phrase=OWNER_APPROVES_LIVE_OPS_FIX
#
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

MODE="propose"
ACTION="REFRESH_OPERATIONAL_STATE"
COMPANY_ID=""
PARAMS="{}"
ACTION_ID=""
PHRASE=""
PROPOSAL=""
IDEMPOTENCY=""
REQUESTER="cloud-agent"

for arg in "$@"; do
  case "$arg" in
    --mode=*) MODE="${arg#--mode=}" ;;
    --action=*) ACTION="${arg#--action=}" ;;
    --company-id=*) COMPANY_ID="${arg#--company-id=}" ;;
    --params=*) PARAMS="${arg#--params=}" ;;
    --action-id=*) ACTION_ID="${arg#--action-id=}" ;;
    --phrase=*) PHRASE="${arg#--phrase=}" ;;
    --proposal=*) PROPOSAL="${arg#--proposal=}" ;;
    --idempotency-key=*) IDEMPOTENCY="${arg#--idempotency-key=}" ;;
    --requester=*) REQUESTER="${arg#--requester=}" ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
  esac
done

case "$MODE" in propose|approve_execute) ;; *) echo "bad mode" >&2; exit 1 ;; esac

# Refuse high-risk action names at the Cloud boundary too
case "$ACTION" in
  REGENERATE_AGENT_API_KEY|DISABLE_COMPANY_AGENT|DESTRUCTIVE_DB_REPAIR|BULK_BILLING_CHANGE|ARBITRARY_SQL|ARBITRARY_SHELL|ARBITRARY_ARTISAN|SSH|CREDENTIAL_CHANGE)
    echo "High-risk action denied at Cloud Agent boundary: $ACTION" >&2
    exit 1
    ;;
esac

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI required" >&2
  exit 1
fi

ARGS=(workflow run live-ops-remediate.yml -f "mode=$MODE" -f "requester=$REQUESTER")
if [ "$MODE" = "propose" ]; then
  test -n "$COMPANY_ID" || { echo "company_id required" >&2; exit 1; }
  ARGS+=(-f "action=$ACTION" -f "company_id=$COMPANY_ID" -f "params_json=$PARAMS")
  [ -n "$PROPOSAL" ] && ARGS+=(-f "proposal=$PROPOSAL")
  [ -n "$IDEMPOTENCY" ] && ARGS+=(-f "idempotency_key=$IDEMPOTENCY")
else
  test -n "$ACTION_ID" || { echo "action_id required" >&2; exit 1; }
  test "$PHRASE" = "OWNER_APPROVES_LIVE_OPS_FIX" || { echo "phrase must be OWNER_APPROVES_LIVE_OPS_FIX" >&2; exit 1; }
  ARGS+=(-f "action_id=$ACTION_ID" -f "owner_approval_phrase=$PHRASE")
fi

gh "${ARGS[@]}"
echo "Dispatched Live Ops Remediate. Owner Environment approval required. Diagnosis alone is never approval."
