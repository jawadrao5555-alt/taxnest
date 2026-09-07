#!/usr/bin/env bash
# Trusted CI helper: propose or approve+execute allow-listed Live Ops remediation.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
mkdir -p artifacts
OUT="artifacts/live-ops-remediation.json"
MODE="${MODE:?}"

https_call() {
  local path="$1"
  local body="$2"
  curl -fsS -X POST "${LIVE_OPS_BASE_URL%/}/api/live-ops/v1${path}" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "X-Live-Ops-Token: ${LIVE_OPS_RUNNER_TOKEN}" \
    -d "$body"
}

if [ -n "${LIVE_OPS_RUNNER_TOKEN:-}" ] && [ -n "${LIVE_OPS_BASE_URL:-}" ]; then
  if [ "$MODE" = "propose" ]; then
    BODY=$(ACTION="$ACTION" COMPANY_ID="$COMPANY_ID" PARAMS_JSON="${PARAMS_JSON:-{}}" \
      PROPOSAL="${PROPOSAL:-}" IDEMPOTENCY_KEY="${IDEMPOTENCY_KEY:-}" REQUESTER="${REQUESTER:-github-actions}" \
      python3 - <<'PY'
import json, os
params = json.loads(os.environ.get("PARAMS_JSON") or "{}")
if not isinstance(params, dict):
    raise SystemExit("params_json must be object")
print(json.dumps({
  "action": os.environ["ACTION"],
  "company_id": int(os.environ["COMPANY_ID"]),
  "parameters": params,
  "proposal": os.environ.get("PROPOSAL") or None,
  "idempotency_key": os.environ.get("IDEMPOTENCY_KEY") or None,
  "requester": os.environ.get("REQUESTER") or "github-actions",
}))
PY
)
    https_call "/remediate/propose" "$BODY" \
      | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok"), d; open("artifacts/live-ops-remediation.json","w").write(json.dumps(d["remediation"], indent=2, default=str)+"\n")'
  else
    BODY=$(OWNER_APPROVAL_PHRASE="$OWNER_APPROVAL_PHRASE" REQUESTER="${REQUESTER:-owner}" python3 - <<'PY'
import json, os
print(json.dumps({
  "owner_approval_phrase": os.environ["OWNER_APPROVAL_PHRASE"],
  "approved_by": os.environ.get("REQUESTER") or "owner",
}))
PY
)
    https_call "/remediate/${ACTION_ID}/approve" "$BODY" >/tmp/live-ops-approve.json
    https_call "/remediate/${ACTION_ID}/execute" '{"executor":"github-actions"}' \
      | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok"), d; open("artifacts/live-ops-remediation.json","w").write(json.dumps(d["remediation"], indent=2, default=str)+"\n")'
  fi
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

if [ "$MODE" = "propose" ]; then
  REMOTE=$(ACTION="$ACTION" COMPANY_ID="$COMPANY_ID" PARAMS_JSON="${PARAMS_JSON:-{}}" \
    PROPOSAL="${PROPOSAL:-}" IDEMPOTENCY_KEY="${IDEMPOTENCY_KEY:-}" REQUESTER="${REQUESTER:-github-actions}" \
    LIVE_DIR="$LIVE_DIR" LIVE_PHP="$LIVE_PHP" python3 - <<'PY'
import json, os, shlex
params = os.environ.get("PARAMS_JSON") or "{}"
json.loads(params)
php = os.environ.get("LIVE_PHP", "/usr/bin/php")
args = [php, "artisan", "live-ops:remediate", "--propose",
        "--action=" + os.environ["ACTION"],
        "--company-id=" + os.environ["COMPANY_ID"],
        "--params=" + params,
        "--requester=" + os.environ.get("REQUESTER", "github-actions"),
        "--output=/tmp/live-ops-remediation.json"]
if os.environ.get("PROPOSAL"):
    args.append("--proposal=" + os.environ["PROPOSAL"])
if os.environ.get("IDEMPOTENCY_KEY"):
    args.append("--idempotency-key=" + os.environ["IDEMPOTENCY_KEY"])
print("cd " + shlex.quote(os.environ.get("LIVE_DIR", "/var/www/taxnest")) + " && " + " ".join(shlex.quote(a) for a in args))
PY
)
  live_ssh "$REMOTE"
else
  REMOTE=$(ACTION_ID="$ACTION_ID" OWNER_APPROVAL_PHRASE="$OWNER_APPROVAL_PHRASE" \
    REQUESTER="${REQUESTER:-owner}" LIVE_DIR="$LIVE_DIR" LIVE_PHP="$LIVE_PHP" python3 - <<'PY'
import os, shlex
php = os.environ.get("LIVE_PHP", "/usr/bin/php")
base = "cd " + shlex.quote(os.environ.get("LIVE_DIR", "/var/www/taxnest")) + " && "
aid = os.environ["ACTION_ID"]
phrase = os.environ["OWNER_APPROVAL_PHRASE"]
req = os.environ.get("REQUESTER", "owner")
approve = [php, "artisan", "live-ops:remediate", "--approve=" + aid,
           "--owner-phrase=" + phrase, "--approved-by=" + req]
execute = [php, "artisan", "live-ops:remediate", "--execute=" + aid,
           "--requester=" + req, "--output=/tmp/live-ops-remediation.json"]
print(base + " ".join(shlex.quote(a) for a in approve) + " && " + " ".join(shlex.quote(a) for a in execute))
PY
)
  live_ssh "$REMOTE"
fi

live_ssh "cat /tmp/live-ops-remediation.json" > "$OUT"
echo "wrote $OUT (ssh)"
