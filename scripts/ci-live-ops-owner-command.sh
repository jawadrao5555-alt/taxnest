#!/usr/bin/env bash
# Trusted CI helper: owner-command engine against production (allow-listed text only).
# Prefer LIVE_OPS_RUNNER_TOKEN HTTPS API; fallback SSH + artisan live-ops:owner-command.
# The owner text is JSON-encoded and never interpolated into a shell command string.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
mkdir -p artifacts
OUT="artifacts/live-ops-owner-command.json"
OWNER_TEXT="${OWNER_TEXT:?}"
REQUESTER="${REQUESTER:-github-actions}"
SOURCE="${SOURCE:-github-actions}"
REQUEST_ID="${REQUEST_ID:-}"

python3 "$ROOT/scripts/lib/live_ops_owner_command.py" "$OWNER_TEXT" > artifacts/live-ops-owner-parsed.json
python3 - <<'PY'
import json, sys
p=json.load(open("artifacts/live-ops-owner-parsed.json", encoding="utf-8"))
if not p.get("ok"):
    print("owner-command rejected before production call:", p.get("error"), file=sys.stderr)
    sys.exit(1)
print("parsed intent=", p.get("intent"), "operation=", p.get("operation"), "company=", p.get("company_name") or "")
PY

if [ -n "${LIVE_OPS_RUNNER_TOKEN:-}" ] && [ -n "${LIVE_OPS_BASE_URL:-}" ]; then
  export OWNER_TEXT REQUESTER SOURCE REQUEST_ID
  BODY=$(python3 - <<'PY'
import json, os
print(json.dumps({
  "text": os.environ["OWNER_TEXT"],
  "requester": os.environ.get("REQUESTER") or "github-actions",
  "source": os.environ.get("SOURCE") or "github-actions",
  "request_id": os.environ.get("REQUEST_ID") or None,
}))
PY
)
  curl -fsS -X POST "${LIVE_OPS_BASE_URL%/}/api/live-ops/v1/owner-command" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "X-Live-Ops-Token: ${LIVE_OPS_RUNNER_TOKEN}" \
    -d "$BODY" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok"), d; open("artifacts/live-ops-owner-command.json","w").write(json.dumps(d["result"], indent=2)+"\n")'
  echo "wrote $OUT (https)"
  exit 0
fi

KEY_TMPDIR=$(mktemp -d)
chmod 700 "$KEY_TMPDIR"
trap 'rm -rf "$KEY_TMPDIR"' EXIT
umask 077
printf '%s\n' "${PRODUCTION_SSH_PRIVATE_KEY:?}" > "$KEY_TMPDIR/taxnest-production-deploy"
chmod 600 "$KEY_TMPDIR/taxnest-production-deploy"
export LIVE_SSH_KEY="$KEY_TMPDIR/taxnest-production-deploy"
export LIVE_KNOWN_HOSTS="$ROOT/scripts/lib/live-known-hosts"
# shellcheck source=lib/live-host.sh
source "$ROOT/scripts/lib/live-host.sh"
live_host_assert_not_retired
require_live_key
case " ${LIVE_SSH_OPTS[*]} " in
  *" StrictHostKeyChecking=yes "*) ;;
  *) echo "LIVE_SSH_OPTS must include StrictHostKeyChecking=yes" >&2; exit 1 ;;
esac
case " ${LIVE_SSH_OPTS[*]} " in
  *" UserKnownHostsFile=$LIVE_KNOWN_HOSTS "*) ;;
  *) echo "LIVE_SSH_OPTS must pin UserKnownHostsFile to $LIVE_KNOWN_HOSTS" >&2; exit 1 ;;
esac
case " ${LIVE_SSH_OPTS[*]} " in
  *" StrictHostKeyChecking=no "*|*" StrictHostKeyChecking=accept-new "*)
    echo "refusing weakened host-key checking" >&2
    exit 1
    ;;
esac

REMOTE=$(OWNER_TEXT="$OWNER_TEXT" REQUESTER="$REQUESTER" SOURCE="$SOURCE" REQUEST_ID="$REQUEST_ID" \
  LIVE_DIR="$LIVE_DIR" LIVE_PHP="$LIVE_PHP" python3 - <<'PY'
import os, shlex
php = os.environ.get("LIVE_PHP", "/usr/bin/php")
args = [php, "artisan", "live-ops:owner-command",
        "--text=" + os.environ["OWNER_TEXT"],
        "--requester=" + os.environ.get("REQUESTER", "github-actions"),
        "--source=" + os.environ.get("SOURCE", "github-actions"),
        "--output=/tmp/live-ops-owner-command.json"]
if os.environ.get("REQUEST_ID"):
    args.append("--request-id=" + os.environ["REQUEST_ID"])
print("cd " + shlex.quote(os.environ.get("LIVE_DIR", "/var/www/taxnest")) + " && " + " ".join(shlex.quote(a) for a in args))
PY
)

live_ssh "$REMOTE"
live_ssh "cat /tmp/live-ops-owner-command.json" > "$OUT"
echo "wrote $OUT (ssh)"
