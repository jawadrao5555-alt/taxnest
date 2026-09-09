#!/bin/bash
# Prove unattended Deploy Production can proceed without GitHub Environment
# required reviewers, without weakening fail-closed gates or exposing secrets
# to Cloud Agents. Does NOT SSH, deploy, or call GitHub.
# Usage: bash scripts/tests/deploy-unattended-safety-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

WF="$ROOT/.github/workflows/deploy-production.yml"
AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"
DIAG="$ROOT/.github/workflows/live-ops-diagnose.yml"
REM="$ROOT/.github/workflows/live-ops-remediate.yml"
CI="$ROOT/.github/workflows/../scripts/ci-deploy-production.sh"
CI="$ROOT/scripts/ci-deploy-production.sh"
OBSERVE="$ROOT/scripts/cloud-issue-to-live-observe.sh"
LIVE_REQ="$ROOT/scripts/cloud-live-ops-request.sh"
LIVE_REM="$ROOT/scripts/cloud-live-ops-remediate-request.sh"

for f in "$WF" "$AM" "$DIAG" "$REM" "$CI" "$OBSERVE"; do
  [ -f "$f" ] || bad "missing $f"
done

# --------------------------------------------------------------------------- Environment split
python3 - "$WF" "$DIAG" "$REM" <<'PY' && ok "deploy uses production-deploy; Live Ops stays on production" || bad "Environment split / secret isolation broken"
import re, sys
wf, diag, rem = (open(p, encoding="utf-8").read() for p in sys.argv[1:])

def jobs(text):
    blob = {}
    cur = None
    in_jobs = False
    for line in text.splitlines():
        if line == "jobs:":
            in_jobs = True
            continue
        if not in_jobs:
            continue
        m = re.match(r"^  ([A-Za-z0-9_-]+):\s*$", line)
        if m:
            cur = m.group(1)
            blob[cur] = []
            continue
        if cur is not None:
            blob[cur].append(line)
    return {k: "\n".join(v) for k, v in blob.items()}

wjobs = jobs(wf)
if "gate" not in wjobs or "deploy" not in wjobs:
    print("missing gate/deploy", file=sys.stderr)
    sys.exit(1)
gate, deploy = wjobs["gate"], wjobs["deploy"]
if re.search(r"(?m)^\s+environment:\s+", gate):
    print("gate must not use any Environment (no secrets, must start immediately)", file=sys.stderr)
    sys.exit(1)
if not re.search(r"(?m)^\s+environment:\s+production-deploy\s*$", deploy):
    print("deploy must use environment: production-deploy (secrets + branch policy, no shared Live Ops reviewers)", file=sys.stderr)
    sys.exit(1)
if re.search(r"(?m)^\s+environment:\s+production\s*$", deploy):
    print("deploy must NOT use environment: production (that Environment has Live Ops required reviewers)", file=sys.stderr)
    sys.exit(1)
if "PRODUCTION_SSH_PRIVATE_KEY" in gate or "LIVE_QA_PASS" in gate:
    print("gate must not reference production secrets", file=sys.stderr)
    sys.exit(1)
if "PRODUCTION_SSH_PRIVATE_KEY" not in deploy or "LIVE_QA_PASS" not in deploy:
    print("deploy must keep Environment-scoped SSH + LIVE_QA_PASS", file=sys.stderr)
    sys.exit(1)

if not re.search(r"(?m)^\s+environment:\s+production\s*$", diag):
    print("live-ops-diagnose must stay on environment: production (required reviewers)", file=sys.stderr)
    sys.exit(1)
if not re.search(r"(?m)^\s+environment:\s+production\s*$", rem):
    print("live-ops-remediate must stay on environment: production (required reviewers)", file=sys.stderr)
    sys.exit(1)
if re.search(r"(?m)^\s+environment:\s+production-deploy\s*$", diag + "\n" + rem):
    print("Live Ops must not use production-deploy (would inherit unattended deploy)", file=sys.stderr)
    sys.exit(1)
print("split ok")
PY

# --------------------------------------------------------------------------- No token auto-approve / no repo-wide secret workaround
python3 - "$WF" "$AM" <<'PY' && ok "no Environment auto-approve token workaround" || bad "token auto-approve or broad secret workaround present"
import re, sys
texts = [open(p, encoding="utf-8").read() for p in sys.argv[1:]]
joined = "\n".join(texts)
banned = [
    r"review_pending_deployments",
    r"pending_deployments",
    r"approveEnvironment",
    r"deployment_review",
    r"createDeploymentStatus",
]
for pat in banned:
    if re.search(pat, joined, re.I):
        print("banned pattern:", pat, file=sys.stderr)
        sys.exit(1)
# Must not move the SSH secret to a repository-level secrets. context in auto-merge
am = texts[1]
if "secrets.PRODUCTION_SSH_PRIVATE_KEY" in am or "secrets.LIVE_QA_PASS" in am:
    print("auto-merge must not read production secrets", file=sys.stderr)
    sys.exit(1)
print("no workaround")
PY

# --------------------------------------------------------------------------- Emergency flags fail-closed on unattended path
python3 - "$WF" <<'PY' && ok "gate refuses skip_elaan/allow_settings; SSH job never passes them" || bad "emergency flags still reach unattended apply"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
lines = text.splitlines()
jobs = {}
cur = None
in_jobs = False
for line in lines:
    if line == "jobs:":
        in_jobs = True
        continue
    if not in_jobs:
        continue
    m = re.match(r"^  ([A-Za-z0-9_-]+):\s*$", line)
    if m:
        cur = m.group(1)
        jobs[cur] = []
        continue
    if cur is not None:
        jobs[cur].append(line)
gate = "\n".join(jobs["gate"])
deploy = "\n".join(jobs["deploy"])
if "skip_elaan is refused" not in gate:
    print("gate must refuse skip_elaan", file=sys.stderr)
    sys.exit(1)
if "allow_settings is refused" not in gate:
    print("gate must refuse allow_settings", file=sys.stderr)
    sys.exit(1)
# SSH step must hardcode SKIP_ELAAN false / ALLOW_SETTINGS empty, not inputs
ssh = deploy
if re.search(r"SKIP_ELAAN:\s*\$\{\{\s*github\.event\.inputs\.skip_elaan", ssh):
    print("deploy must not pass skip_elaan input through to SKIP_ELAAN", file=sys.stderr)
    sys.exit(1)
if re.search(r"ALLOW_SETTINGS:\s*\$\{\{\s*github\.event\.inputs\.allow_settings", ssh):
    print("deploy must not pass allow_settings input through", file=sys.stderr)
    sys.exit(1)
if 'SKIP_ELAAN: "false"' not in ssh and "SKIP_ELAAN: 'false'" not in ssh:
    print("deploy must hardcode SKIP_ELAAN false", file=sys.stderr)
    sys.exit(1)
if "--no-elaan" in ssh:
    print("deploy job must not invoke --no-elaan", file=sys.stderr)
    sys.exit(1)
if "--allow-settings" in ssh:
    print("deploy job must not invoke --allow-settings", file=sys.stderr)
    sys.exit(1)
# Refuse must happen in gate BEFORE cancel/SSH
if gate.find("skip_elaan") > gate.find("cancel-stale-waiting-production-deploys.sh"):
    print("skip_elaan refuse must run before cancelling other runs", file=sys.stderr)
    sys.exit(1)
print("flags ok")
PY

# skip_elaan still default false (input exists so a mistaken dispatch is visible and refused)
if grep -A6 'skip_elaan:' "$WF" | grep -q 'default: false'; then
  ok "skip_elaan input still defaults to false"
else
  bad "skip_elaan default must remain false"
fi

# --------------------------------------------------------------------------- Existing fail-closed apply/verify/concurrency still present
python3 - "$WF" <<'PY' && ok "tip/concurrency/live-verify/SSH invariants remain" || bad "core deploy invariants missing"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
need = [
    "deploy_guard_main_tip",
    "ci-deploy-production.sh",
    "ci-live-verify.sh",
    "group: production-deploy-gate",
    "group: production-deploy",
    "cancel-stale-waiting-production-deploys.sh",
    "PRODUCTION_SSH_PRIVATE_KEY",
    "LIVE_QA_PASS",
]
for n in need:
    if n not in text:
        print("missing", n, file=sys.stderr)
        sys.exit(1)
if re.search(r"(?m)^concurrency:", text):
    print("workflow-level concurrency present", file=sys.stderr)
    sys.exit(1)
jobs = {}
cur = None
in_jobs = False
for line in text.splitlines():
    if line == "jobs:":
        in_jobs = True
        continue
    if not in_jobs:
        continue
    m = re.match(r"^  ([A-Za-z0-9_-]+):\s*$", line)
    if m:
        cur = m.group(1)
        jobs[cur] = []
        continue
    if cur is not None:
        jobs[cur].append(line)
deploy = "\n".join(jobs["deploy"])
gate = "\n".join(jobs["gate"])
if "cancel-in-progress: false" not in deploy:
    print("deploy must keep cancel-in-progress: false", file=sys.stderr)
    sys.exit(1)
if "cancel-in-progress: true" in deploy:
    print("deploy must not cancel in-progress", file=sys.stderr)
    sys.exit(1)
if "ci-deploy-production.sh" in gate or "ci-live-verify.sh" in gate:
    print("gate must not SSH/apply/verify", file=sys.stderr)
    sys.exit(1)
if "actions: write" in deploy:
    print("deploy must not have actions: write", file=sys.stderr)
    sys.exit(1)
print("invariants ok")
PY

# --------------------------------------------------------------------------- Cloud Agent remains production-secret-free
for f in "$OBSERVE" "$LIVE_REQ" "$LIVE_REM" "$AM" "$ROOT/.github/workflows/owner-merge-and-deploy.yml"; do
  [ -f "$f" ] || continue
  if grep -vE '^\s*#' "$f" | grep -qE 'secrets\.PRODUCTION_SSH_PRIVATE_KEY|PRODUCTION_SSH_PRIVATE_KEY:'; then
    bad "$(basename "$f") must not hold PRODUCTION_SSH_PRIVATE_KEY"
  else
    ok "$(basename "$f") does not hold PRODUCTION_SSH_PRIVATE_KEY"
  fi
  if grep -vE '^\s*#' "$f" | grep -qE 'LIVE_QA_PASS:|secrets\.LIVE_QA_PASS'; then
    bad "$(basename "$f") must not hold LIVE_QA_PASS"
  else
    ok "$(basename "$f") does not hold LIVE_QA_PASS"
  fi
done

if grep -q 'OWNER_APPROVES_LIVE_OPS_FIX' "$REM"; then
  ok "Live Ops remediate still requires owner phrase"
else
  bad "must not drop OWNER_APPROVES_LIVE_OPS_FIX"
fi

# Owner merge (not retired auto-merge) dispatches only target_sha
SH="$ROOT/scripts/owner-merge-and-deploy.sh"
python3 - "$AM" "$SH" <<'PY' && ok "owner handoff sends only target_sha; auto-merge does not dispatch" || bad "must not dispatch skip_elaan/allow_settings; auto-merge must stay retired"
import sys
am, sh = (open(p, encoding="utf-8").read() for p in sys.argv[1:])
if "createWorkflowDispatch" in am or "pulls.merge" in am or "enablePullRequestAutoMerge" in am:
    print("retired auto-merge still merges/dispatches", file=sys.stderr)
    sys.exit(1)
if "inputs[target_sha]" not in sh:
    print("owner script must dispatch target_sha", file=sys.stderr)
    sys.exit(1)
if "skip_elaan" in sh or "allow_settings" in sh:
    print("owner dispatch must not send skip_elaan/allow_settings", file=sys.stderr)
    sys.exit(1)
sys.exit(0)
PY

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "deploy-unattended-safety-check: ALL PASS"
  exit 0
fi
echo "deploy-unattended-safety-check: $FAILS FAIL(S)" >&2
exit 1
