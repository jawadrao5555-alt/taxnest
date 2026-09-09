#!/bin/bash
# Prove Live Ops daily-ops allow-list, scopes, and workflow guard stay aligned.
# Does NOT SSH, deploy, or dispatch workflows.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

CFG="$ROOT/config/live_ops.php"
SVC="$ROOT/app/Services/LiveOps/LiveOpsDiagnosticsService.php"
WF="$ROOT/.github/workflows/live-ops-diagnose.yml"
CLOUD="$ROOT/scripts/cloud-live-ops-request.sh"
HOST="$ROOT/app/Services/LiveOps/LiveOpsPlatformHealth.php"

for f in "$CFG" "$SVC" "$WF" "$CLOUD" "$HOST"; do
  [ -f "$f" ] || bad "missing $f"
done

python3 - "$CFG" "$SVC" "$WF" "$CLOUD" <<'PY' && ok "DAILY_OPS + SERVER_HEALTH wired in config, service, workflow, cloud helper" || bad "DAILY_OPS/SERVER_HEALTH wiring mismatch"
import re, sys
cfg, svc, wf, cloud = (open(p, encoding="utf-8").read() for p in sys.argv[1:])
for name in ("DAILY_OPS", "SERVER_HEALTH"):
    if f"'{name}'" not in cfg and f'"{name}"' not in cfg:
        sys.exit(1)
    if f"'{name}'" not in svc:
        sys.exit(1)
    if name not in wf or name not in cloud:
        sys.exit(1)
if "default: DAILY_OPS" not in wf:
    sys.exit(1)
if 'OPERATION="DAILY_OPS"' not in cloud:
    sys.exit(1)
if "environment: production" not in wf:
    sys.exit(1)
if "environment: production-deploy" in wf:
    sys.exit(1)
if "company_id or company_name is required" not in wf:
    sys.exit(1)
if "company_id or company_name is required" not in cloud:
    sys.exit(1)
if re.search(r"(?m)^\s+[^#]*ssh-keyscan", wf) or re.search(r"(?m)^\s+[^#]*StrictHostKeyChecking=no", wf):
    sys.exit(1)
sys.exit(0)
PY

python3 - "$CFG" <<'PY' && ok "operation_scopes cover every diagnostic_operations entry" || bad "operation_scopes mismatch"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
ops = re.search(r"'diagnostic_operations'\s*=>\s*\[(.*?)\]", text, re.S)
scopes = re.search(r"'operation_scopes'\s*=>\s*\[(.*?)\]", text, re.S)
if not ops or not scopes:
    sys.exit(1)
op_names = re.findall(r"'([A-Z_]+)'", ops.group(1))
scope_names = re.findall(r"'([A-Z_]+)'\s*=>", scopes.group(1))
if set(op_names) != set(scope_names):
    sys.exit(1)
required = {
    "COMPANY_HEALTH": "company",
    "PRINTER_HEALTH": "company",
    "COMPANY_DIAGNOSTIC": "company",
    "ERROR_SUMMARY": "dual",
    "PRA_HEALTH": "dual",
    "AGENT_HEALTH": "dual",
    "DAILY_OPS": "global",
    "SERVER_HEALTH": "global",
    "BILLING_BY_COMPANY": "global",
    "PROBLEMATIC_COMPANIES": "global",
}
for k, v in required.items():
    if not re.search(rf"'{k}'\s*=>\s*'{v}'", scopes.group(1)):
        sys.exit(1)
sys.exit(0)
PY

grep -q 'Never mutates' "$HOST" \
  && ok "platform health documents read-only" \
  || bad "LiveOpsPlatformHealth must stay read-only"

if grep -E 'systemctl (restart|reload|start|stop)|apt-get|yum |dnf |ssh-keyscan' "$HOST" "$SVC"; then
  bad "diagnostics must not restart services, install packages, or ssh-keyscan"
else
  ok "no restart/install/ssh-keyscan in new diagnostic readers"
fi

bash -n "$CLOUD" && ok "cloud-live-ops-request.sh bash -n" || bad "cloud helper bash -n failed"

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "live-ops-daily-ops-check: ALL PASS"
  exit 0
fi
echo "live-ops-daily-ops-check: $FAILS FAIL(S)" >&2
exit 1
