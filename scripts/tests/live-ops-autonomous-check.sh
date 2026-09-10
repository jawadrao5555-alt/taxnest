#!/bin/bash
# Static checks for the autonomous Live Ops owner-command engine.
# Does NOT SSH, deploy, dispatch workflows, or print secrets.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

need() { [ -f "$1" ] && ok "present $(basename "$1")" || bad "missing $1"; }

need "$ROOT/app/Services/LiveOps/LiveOpsCompanyResolver.php"
need "$ROOT/app/Services/LiveOps/LiveOpsOwnerCommandParser.php"
need "$ROOT/app/Services/LiveOps/LiveOpsOwnerReportFormatter.php"
need "$ROOT/app/Services/LiveOps/LiveOpsChangeRiskClassifier.php"
need "$ROOT/app/Services/LiveOps/LiveOpsAutonomousEngine.php"
need "$ROOT/scripts/lib/live_ops_owner_command.py"
need "$ROOT/scripts/lib/live_ops_risk_classify.py"
need "$ROOT/scripts/ci-live-ops-owner-command.sh"
need "$ROOT/scripts/live-ops-fetch-report.sh"
need "$ROOT/scripts/live-ops-safe-auto-merge.sh"
need "$ROOT/scripts/cloud-live-ops-request.sh"
need "$ROOT/.github/workflows/live-ops-owner-bridge.yml"
need "$ROOT/.github/workflows/live-ops-safe-auto-merge.yml"
need "$ROOT/docs/ops/live-ops-owner-bridge.md"

for f in \
  "$ROOT/scripts/ci-live-ops-owner-command.sh" \
  "$ROOT/scripts/live-ops-fetch-report.sh" \
  "$ROOT/scripts/live-ops-safe-auto-merge.sh" \
  "$ROOT/scripts/cloud-live-ops-request.sh"
do
  bash -n "$f" && ok "bash -n $(basename "$f")" || bad "bash -n $(basename "$f")"
done

python3 -m py_compile "$ROOT/scripts/lib/live_ops_owner_command.py" \
  && ok "python compile owner command parser" \
  || bad "owner command parser syntax"

python3 -m py_compile "$ROOT/scripts/lib/live_ops_risk_classify.py" \
  && ok "python compile risk classifier" \
  || bad "risk classifier syntax"

python3 - "$ROOT/scripts/lib/live_ops_owner_command.py" <<'PY' && ok "python parser covers owner phrases and rejects unsafe" || bad "python parser vectors failed"
import json, subprocess, sys
py = sys.argv[1]
def parse(text):
    p = subprocess.run(["python3", py, text], capture_output=True, text=True)
    return json.loads(p.stdout), p.returncode
d, rc = parse("Aaj ki report do.")
assert d["ok"] and d["intent"] == "DAILY_REPORT" and d["operation"] == "DAILY_OPS" and rc == 0
d, rc = parse("Pizza Master check karo")
assert d["ok"] and d["company_name"] == "Pizza Master" and d["intent"] == "COMPANY_CHECK"
d, rc = parse("ZFC ka printing issue solve karo")
assert d["ok"] and d["intent"] == "COMPANY_SOLVE" and d["company_name"] == "ZFC"
d, rc = parse("Sab companies check karo aur jahan issue ho solve karo")
assert d["ok"] and d["intent"] == "FLEET_CHECK_AND_SOLVE"
d, rc = parse("ssh root@host")
assert not d["ok"] and rc != 0
d, rc = parse("gh workflow run deploy-production.yml")
assert not d["ok"]
print("parser vectors ok")
PY

python3 - "$ROOT/scripts/lib/live_ops_risk_classify.py" <<'PY' && ok "python risk classifier deny/allow" || bad "python risk classifier vectors failed"
import json, subprocess, sys, tempfile, os
py = sys.argv[1]
def classify(paths):
    p = subprocess.run(["python3", py, *paths], capture_output=True, text=True)
    return json.loads(p.stdout), p.returncode
d, rc = classify(["public/js/pos-print-attempt.js"])
assert d["class"] == "AUTO_DEPLOY" and rc == 0
d, rc = classify(["database/migrations/x.php"])
assert d["class"] == "HIGH_RISK_BLOCKED" and rc != 0
d, rc = classify(["app/Services/PosTaxMath.php"])
assert d["class"] == "HIGH_RISK_BLOCKED"
print("risk vectors ok")
PY

BRIDGE="$ROOT/.github/workflows/live-ops-owner-bridge.yml"
SAFE="$ROOT/.github/workflows/live-ops-safe-auto-merge.yml"
SAFE_SH="$ROOT/scripts/live-ops-safe-auto-merge.sh"
CLOUD="$ROOT/scripts/cloud-live-ops-request.sh"
FETCH="$ROOT/scripts/live-ops-fetch-report.sh"
CFG="$ROOT/config/live_ops.php"
ENG="$ROOT/app/Services/LiveOps/LiveOpsAutonomousEngine.php"

grep -q 'taxnest-live-ops' "$BRIDGE" && ok "owner bridge accepts repository_dispatch taxnest-live-ops" \
  || bad "missing repository_dispatch type"
grep -q '\[TAXNEST-OPS\]' "$BRIDGE" && ok "owner bridge requires [TAXNEST-OPS] issue prefix" \
  || bad "missing issue prefix guard"
grep -q 'github.repository_owner' "$BRIDGE" && ok "owner bridge authenticates repository owner" \
  || bad "missing owner actor guard"
grep -q 'live_ops_owner_command.py' "$BRIDGE" && ok "owner bridge parses before production" \
  || bad "bridge must parse locally first"
grep -q 'environment: production' "$BRIDGE" && ok "owner bridge uses Environment production" \
  || bad "bridge must use Environment production"
if grep -q 'environment: production-deploy' "$BRIDGE"; then
  bad "owner bridge must not use production-deploy"
else
  ok "owner bridge does not use production-deploy"
fi
if grep -vE '^\s*#' "$BRIDGE" | grep -qE 'workflow run [^l]|deploy-production.yml'; then
  bad "owner bridge must not dispatch arbitrary/deploy workflows"
else
  ok "owner bridge does not dispatch Deploy Production"
fi

grep -q 'environment: production' "$SAFE" && ok "safe-auto-merge uses Environment production for runner token" \
  || bad "safe-auto-merge must read runner token from production env"
if grep -q 'pulls/.*/merge\|deploy-production.yml/dispatches' "$SAFE" "$SAFE_SH"; then
  bad "safe-auto-merge must not squash-merge or dispatch Deploy Production"
else
  ok "safe-auto-merge defers merge/deploy to owner-approval relay"
fi
grep -q 'live-ops/v1/safe-auto-deploy' "$SAFE_SH" && ok "safe-auto-merge calls runner safe-auto-deploy" \
  || bad "missing runner safe-auto-deploy call"
grep -q 'cursor/' "$SAFE_SH" && ok "safe-auto-merge requires cursor/*" \
  || bad "must require cursor/*"

grep -q 'HTTP 403' "$CLOUD" && grep -q 'exit 3' "$CLOUD" && ok "cloud helper fail-closes workflow_dispatch 403" \
  || bad "cloud helper must handle 403 / exit 3"
grep -q 'live-ops-fetch-report.sh' "$CLOUD" && ok "cloud helper retrieves parsed report" \
  || bad "cloud helper must fetch/parse the report"
if grep -qi 'Go to GitHub and download' "$CLOUD" "$FETCH"; then
  bad "must not tell the owner to download artifacts from GitHub"
else
  ok "does not tell owner to download from GitHub UI"
fi
grep -q 'actions: write' "$CLOUD" "$ROOT/docs/ops/live-ops-owner-bridge.md" \
  && ok "documents minimum GitHub App actions: write" \
  || bad "must document actions: write as the dispatch permission"

grep -q 'MAX_ITERATIONS = 3' "$ENG" && ok "autonomous engine bounds iterations at 3" \
  || bad "max iterations must be 3"
grep -q 'owner_company_cards' "$ROOT/app/Services/LiveOps/LiveOpsDiagnosticsService.php" \
  && ok "DAILY_OPS includes owner_company_cards" \
  || bad "DAILY_OPS must include owner company cards"
grep -q "LiveOpsCompanyResolver" "$ROOT/app/Services/LiveOps/LiveOpsDiagnosticsService.php" \
  && ok "diagnostics uses company resolver" \
  || bad "diagnostics must use LiveOpsCompanyResolver"

if grep -R --include='*.php' --include='*.yml' --include='*.sh' -nE 'ghp_[A-Za-z0-9]|github_pat_' "$ROOT/app/Services/LiveOps" "$ROOT/.github/workflows/live-ops-owner-bridge.yml" "$ROOT/scripts/ci-live-ops-owner-command.sh" >/dev/null 2>&1; then
  bad "must not embed GitHub PATs"
else
  ok "no GitHub PAT embedded in new Live Ops paths"
fi

python3 - "$CFG" "$SAFE" "$ROOT/.github/workflows/deploy-production.yml" <<'PY' && ok "no push-to-main production deploy reintroduced" || bad "push-to-main guard failed"
import re, sys
safe, dp = open(sys.argv[2], encoding="utf-8").read(), open(sys.argv[3], encoding="utf-8").read()
if re.search(r"(?m)^\s+push:\s*$", dp):
    sys.exit(1)
if "on.push" in dp.replace(" ", ""):
    # comment mentioning on.push is ok; actual trigger is not
    pass
m = re.search(r"(?ms)^on:\n(.*?)(?=^permissions:|^jobs:)", dp)
on = m.group(1) if m else ""
if re.search(r"(?m)^\s*push:", on):
    sys.exit(1)
if re.search(r"(?m)^\s*push:", safe):
    sys.exit(1)
sys.exit(0)
PY

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "live-ops-autonomous-check: ALL PASS"
  exit 0
fi
echo "live-ops-autonomous-check: $FAILS FAIL(S)" >&2
exit 1
