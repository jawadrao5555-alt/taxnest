#!/bin/bash
# Cancel other Deploy Production runs that are WAITING for Environment approval.
#
# Safe to run only AFTER the current run has proven it is the origin/main tip.
# Never cancels in_progress runs (those may be in SSH/apply).
#
# Does NOT deploy, SSH, or read production secrets.
# Env (Actions): GITHUB_REPOSITORY, GITHUB_RUN_ID, GH_TOKEN or GITHUB_TOKEN
# Optional: DRY_RUN=1  FIXTURE_JSON=path  GH_API (default https://api.github.com)

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SELECTOR="$ROOT/scripts/lib/select-stale-waiting-deploy-runs.py"
API="${GH_API:-${GITHUB_API_URL:-https://api.github.com}}"
TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
REPO="${GITHUB_REPOSITORY:-}"
RUN_ID="${GITHUB_RUN_ID:-}"
WORKFLOW="deploy-production.yml"

if [ ! -f "$SELECTOR" ]; then
  echo "missing $SELECTOR" >&2
  exit 1
fi

if [ -n "${FIXTURE_JSON:-}" ]; then
  python3 "$SELECTOR" "$FIXTURE_JSON" "${RUN_ID:-0}"
  exit 0
fi

if [ -z "$REPO" ] || [ -z "$RUN_ID" ]; then
  echo "GITHUB_REPOSITORY and GITHUB_RUN_ID are required" >&2
  exit 1
fi
if [ -z "$TOKEN" ]; then
  echo "warning: no GH_TOKEN/GITHUB_TOKEN — cannot cancel stale waiting deploys" >&2
  exit 0
fi

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

HTTP=$(curl -sS -o "$TMP" -w '%{http_code}' \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/vnd.github+json" \
  -H "X-GitHub-Api-Version: 2022-11-28" \
  "${API}/repos/${REPO}/actions/workflows/${WORKFLOW}/runs?status=waiting&per_page=50") || {
  echo "warning: listing waiting Deploy Production runs failed — continuing" >&2
  exit 0
}

if [ "$HTTP" != "200" ]; then
  echo "warning: list waiting runs HTTP $HTTP — continuing without cancel" >&2
  exit 0
fi

mapfile -t IDS < <(python3 "$SELECTOR" "$TMP" "$RUN_ID")
if [ "${#IDS[@]}" -eq 0 ]; then
  echo "No stale Environment-waiting Deploy Production runs to cancel."
  exit 0
fi

for id in "${IDS[@]}"; do
  case "$id" in
    ''|*[!0-9]*) echo "skipping non-numeric run id: $id" >&2; continue ;;
  esac
  if [ "$id" = "$RUN_ID" ]; then
    continue
  fi
  echo "Cancelling stale Environment-waiting Deploy Production run $id (status=waiting only)."
  if [ "${DRY_RUN:-}" = "1" ]; then
    echo "DRY_RUN: would POST ${API}/repos/${REPO}/actions/runs/${id}/cancel"
    continue
  fi
  CHTTP=$(curl -sS -o /dev/null -w '%{http_code}' -X POST \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "${API}/repos/${REPO}/actions/runs/${id}/cancel") || CHTTP="000"
  case "$CHTTP" in
    202|204|200) echo "Cancelled waiting run $id (HTTP $CHTTP)." ;;
    *) echo "warning: cancel run $id HTTP $CHTTP — continuing" >&2 ;;
  esac
done
exit 0
