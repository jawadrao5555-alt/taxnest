#!/usr/bin/env bash
# Trusted CI helper: allow-listed live-ops:diagnose against production.
# Prefer LIVE_OPS_RUNNER_TOKEN HTTPS API; fallback SSH + fixed artisan args.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
mkdir -p artifacts
OUT="artifacts/live-ops-diagnostic.json"
OPERATION="${OPERATION:?}"

if [ -n "${LIVE_OPS_RUNNER_TOKEN:-}" ] && [ -n "${LIVE_OPS_BASE_URL:-}" ]; then
  export OPERATION COMPANY_ID COMPANY_NAME DATE_FROM DATE_TO REQUESTER
  BODY=$(python3 - <<'PY'
import json, os
cid = os.environ.get("COMPANY_ID") or ""
print(json.dumps({
  "operation": os.environ["OPERATION"],
  "company_id": int(cid) if cid.isdigit() else None,
  "company_name": os.environ.get("COMPANY_NAME") or None,
  "date_from": os.environ.get("DATE_FROM") or None,
  "date_to": os.environ.get("DATE_TO") or None,
  "requester": os.environ.get("REQUESTER") or "github-actions",
}))
PY
)
  curl -fsS -X POST "${LIVE_OPS_BASE_URL%/}/api/live-ops/v1/diagnose" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "X-Live-Ops-Token: ${LIVE_OPS_RUNNER_TOKEN}" \
    -d "$BODY" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok"), d; open("artifacts/live-ops-diagnostic.json","w").write(json.dumps(d["report"], indent=2)+"\n")'
  echo "wrote $OUT (https)"
  exit 0
fi

# shellcheck source=lib/live-host.sh
source "$ROOT/scripts/lib/live-host.sh"
live_host_assert_not_retired
KEY_TMPDIR=$(mktemp -d)
trap 'rm -rf "$KEY_TMPDIR"' EXIT
printf '%s\n' "${PRODUCTION_SSH_PRIVATE_KEY:?}" > "$KEY_TMPDIR/taxnest-production-deploy"
chmod 600 "$KEY_TMPDIR/taxnest-production-deploy"
export LIVE_SSH_KEY="$KEY_TMPDIR/taxnest-production-deploy"
LIVE_SSH_OPTS=(-i "$LIVE_SSH_KEY" -p "$LIVE_SSH_PORT" -o BatchMode=yes
               -o ConnectTimeout=15
               -o UserKnownHostsFile="$LIVE_KNOWN_HOSTS"
               -o StrictHostKeyChecking=yes)

REMOTE=$(OPERATION="$OPERATION" COMPANY_ID="${COMPANY_ID:-}" COMPANY_NAME="${COMPANY_NAME:-}" \
  DATE_FROM="${DATE_FROM:-}" DATE_TO="${DATE_TO:-}" REQUESTER="${REQUESTER:-github-actions}" \
  LIVE_DIR="$LIVE_DIR" LIVE_PHP="$LIVE_PHP" python3 - <<'PY'
import os, shlex
php = os.environ.get("LIVE_PHP", "/usr/bin/php")
args = [php, "artisan", "live-ops:diagnose", "--operation=" + os.environ["OPERATION"],
        "--requester=" + os.environ.get("REQUESTER", "github-actions"),
        "--output=/tmp/live-ops-diagnostic.json"]
if os.environ.get("COMPANY_ID"):
    args.append("--company-id=" + os.environ["COMPANY_ID"])
if os.environ.get("COMPANY_NAME"):
    args.append("--company-name=" + os.environ["COMPANY_NAME"])
if os.environ.get("DATE_FROM"):
    args.append("--date-from=" + os.environ["DATE_FROM"])
if os.environ.get("DATE_TO"):
    args.append("--date-to=" + os.environ["DATE_TO"])
print("cd " + shlex.quote(os.environ.get("LIVE_DIR", "/var/www/taxnest")) + " && " + " ".join(shlex.quote(a) for a in args))
PY
)

live_ssh "$REMOTE"
live_ssh "cat /tmp/live-ops-diagnostic.json" > "$OUT"
echo "wrote $OUT (ssh)"
