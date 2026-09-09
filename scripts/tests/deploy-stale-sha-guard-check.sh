#!/bin/bash
# Prove stale/non-tip SHA is fail-closed and concurrency is split correctly.
# Does NOT SSH, deploy, or call GitHub. No production secrets.
# Usage: bash scripts/tests/deploy-stale-sha-guard-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

WF="$ROOT/.github/workflows/deploy-production.yml"
GUARD="$ROOT/scripts/lib/deploy-main-tip-guard.sh"
CANCEL="$ROOT/scripts/lib/cancel-stale-waiting-production-deploys.sh"
SELECT="$ROOT/scripts/lib/select-stale-waiting-deploy-runs.py"
CI="$ROOT/scripts/ci-deploy-production.sh"
AM="$ROOT/.github/workflows/enable-pr-auto-merge.yml"

for f in "$WF" "$GUARD" "$CANCEL" "$SELECT" "$CI" "$AM"; do
  [ -f "$f" ] || bad "missing $f"
done

bash -n "$GUARD" && ok "deploy-main-tip-guard.sh bash -n" || bad "guard bash -n failed"
bash -n "$CANCEL" && ok "cancel-stale-waiting-production-deploys.sh bash -n" || bad "cancel script bash -n failed"
python3 -m py_compile "$SELECT" && ok "select-stale-waiting-deploy-runs.py compiles" || bad "selector py_compile failed"

# --------------------------------------------------------------------------- YAML / job structure
python3 - "$WF" <<'PY' && ok "workflow job split + invariants" || bad "workflow job split + invariants"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
lines = text.splitlines()

# No workflow-level concurrency (would make Environment wait occupy the SSH group).
for line in lines:
    if line.startswith("concurrency:"):
        print("workflow-level concurrency present — this recreates the stale-approval slot bug", file=sys.stderr)
        sys.exit(1)

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

if "gate" not in jobs or "deploy" not in jobs:
    print("missing gate and/or deploy jobs", file=sys.stderr)
    sys.exit(1)

def blob(name):
    return "\n".join(jobs[name])

gate, deploy = blob("gate"), blob("deploy")

def has_env(b, name):
    return bool(re.search(r"(?m)^\s+environment:\s+" + name + r"\s*$", b))

if has_env(gate, "production") or has_env(gate, "production-deploy"):
    print("gate must NOT use an Environment (must start without occupying approval/secrets)", file=sys.stderr)
    sys.exit(1)
if has_env(deploy, "production"):
    print("deploy must NOT use environment: production (Live Ops reviewers live there)", file=sys.stderr)
    sys.exit(1)
if not has_env(deploy, "production-deploy"):
    print("deploy must keep environment: production-deploy", file=sys.stderr)
    sys.exit(1)

if "PRODUCTION_SSH_PRIVATE_KEY" in gate:
    print("gate must not reference PRODUCTION_SSH_PRIVATE_KEY", file=sys.stderr)
    sys.exit(1)
if "PRODUCTION_SSH_PRIVATE_KEY" not in deploy:
    print("deploy must reference PRODUCTION_SSH_PRIVATE_KEY", file=sys.stderr)
    sys.exit(1)
if "LIVE_QA_PASS" not in deploy:
    print("deploy must reference LIVE_QA_PASS", file=sys.stderr)
    sys.exit(1)
if "LIVE_QA_PASS" in gate:
    print("gate must not reference LIVE_QA_PASS", file=sys.stderr)
    sys.exit(1)

if not re.search(r"group:\s+production-deploy-gate", gate):
    print("gate missing concurrency group production-deploy-gate", file=sys.stderr)
    sys.exit(1)
if not re.search(r"cancel-in-progress:\s+true", gate):
    print("gate must cancel-in-progress: true (supersede stale pre-apply)", file=sys.stderr)
    sys.exit(1)
if not re.search(r"group:\s+production-deploy\s*$", deploy, re.M):
    print("deploy missing concurrency group production-deploy", file=sys.stderr)
    sys.exit(1)
if not re.search(r"cancel-in-progress:\s+false", deploy):
    print("deploy must keep cancel-in-progress: false", file=sys.stderr)
    sys.exit(1)
if re.search(r"cancel-in-progress:\s+true", deploy):
    print("deploy must NOT set cancel-in-progress: true", file=sys.stderr)
    sys.exit(1)

if not re.search(r"(?m)^\s+needs:\s+gate\s*$", deploy):
    print("deploy must need gate", file=sys.stderr)
    sys.exit(1)

if "ci-deploy-production.sh" in gate:
    print("gate must not run ci-deploy-production.sh", file=sys.stderr)
    sys.exit(1)
if "ci-deploy-production.sh" not in deploy:
    print("deploy must run ci-deploy-production.sh", file=sys.stderr)
    sys.exit(1)
if "ci-live-verify.sh" not in deploy:
    print("deploy must run ci-live-verify.sh", file=sys.stderr)
    sys.exit(1)
if "ci-live-verify.sh" in gate:
    print("gate must not run live verify", file=sys.stderr)
    sys.exit(1)

if "deploy_guard_main_tip" not in gate or "deploy_guard_main_tip" not in deploy:
    print("both jobs must call deploy_guard_main_tip", file=sys.stderr)
    sys.exit(1)

if "cancel-stale-waiting-production-deploys.sh" not in gate:
    print("gate must cancel stale waiting runs after tip check", file=sys.stderr)
    sys.exit(1)
if "cancel-stale-waiting-production-deploys.sh" in deploy:
    print("deploy/SSH job must not cancel other runs", file=sys.stderr)
    sys.exit(1)

# Cancel must be AFTER the tip guard in the gate job text order.
if gate.find("deploy_guard_main_tip") > gate.find("cancel-stale-waiting-production-deploys.sh"):
    print("tip guard must run before cancelling waiting runs", file=sys.stderr)
    sys.exit(1)

if "actions: write" not in gate:
    print("gate needs actions: write to cancel waiting runs", file=sys.stderr)
    sys.exit(1)
# deploy job should not broaden token to actions:write
if re.search(r"(?m)^\s+permissions:\s*$", deploy) and "actions: write" in deploy:
    print("deploy job must not have actions: write", file=sys.stderr)
    sys.exit(1)

print("structure ok")
PY

# skip_elaan still emergency-only
if grep -A5 'skip_elaan:' "$WF" | grep -q 'default: false'; then
  ok "skip_elaan defaults to false"
else
  bad "skip_elaan must default to false"
fi

# Elaan path unchanged in CI script
grep -q 'insert_committed_elaan_spec' "$CI" \
  && grep -q 'check_elaan_freshness' "$CI" \
  && ok "Elaan insert + freshness remain in ci-deploy-production.sh" \
  || bad "Elaan path missing from ci-deploy-production.sh"

# Tip check in CI script is BEFORE SSH preflight
python3 - "$CI" <<'PY' && ok "ci-deploy-production.sh tip-check is before SSH preflight" || bad "tip-check must precede SSH"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
tip = text.find("deploy_require_origin_main_tip")
ssh = text.find('step "Preflight: SSH connectivity + live HEAD"')
elaan = text.find("insert_committed_elaan_spec")
apply_ = text.find("remote_apply")
if min(tip, ssh, elaan, apply_) < 0:
    sys.exit(1)
if not (tip < ssh < elaan < apply_):
    sys.exit(1)
sys.exit(0)
PY

# Owner merge handoff intact; automatic Cursor merge must not dispatch
OM="$ROOT/.github/workflows/owner-merge-and-deploy.yml"
SH="$ROOT/scripts/owner-merge-and-deploy.sh"
if grep -q 'createWorkflowDispatch' "$AM"; then
  bad "retired auto-merge must not dispatch Deploy Production"
else
  ok "retired auto-merge does not dispatch"
fi
grep -q 'inputs\[target_sha\]' "$SH" \
  && ok "owner merge still dispatches exact target_sha" \
  || bad "owner-merge handoff broken"

if grep -vE '^\s*#' "$AM" "$OM" | grep -qiE 'PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS'; then
  bad "merge-initiation workflows must still not hold production secrets"
else
  ok "merge-initiation workflows still production-secret-free"
fi

# --------------------------------------------------------------------------- Git fixture: tip vs ancestor-not-tip
TMP=$(mktemp -d /tmp/stale-sha-guard.XXXXXX)
trap 'rm -rf "$TMP"' EXIT
gitq() { git -c user.email=t@t -c user.name=t "$@"; }

mkdir -p "$TMP"
gitq init -q --bare "$TMP/origin-repo"
gitq init -q "$TMP/ws"
(
  cd "$TMP/ws"
  git config user.email t@t
  git config user.name t
  gitq remote add origin "$TMP/origin-repo"
  echo a > f && gitq add f && gitq commit -qm A
  gitq branch -M main
  gitq push -q origin HEAD:main
  echo b > f && gitq add f && gitq commit -qm B
  gitq push -q origin HEAD:main
  gitq fetch -q origin "+main:refs/remotes/origin/main"
)
# shellcheck source=scripts/lib/deploy-main-tip-guard.sh
source "$GUARD"

(
  cd "$TMP/ws"
  TIP=$(git rev-parse refs/remotes/origin/main)
  HEAD=$(git rev-parse HEAD)
  [ "$TIP" = "$HEAD" ] || exit 1
  deploy_require_full_sha "$TIP" >/dev/null
  deploy_require_checkout_matches "$TIP" >/dev/null
  deploy_require_on_main_history "$TIP" >/dev/null
  deploy_require_origin_main_tip "$TIP" >/dev/null
) && ok "requested SHA == origin/main tip → allowed" || bad "tip SHA should be allowed"

OLD=$(git -C "$TMP/ws" rev-parse HEAD~1)
(
  cd "$TMP/ws"
  git merge-base --is-ancestor "$OLD" refs/remotes/origin/main || exit 1
  deploy_require_on_main_history "$OLD" >/dev/null || exit 1
  if deploy_require_origin_main_tip "$OLD" >/dev/null 2>"$TMP/old.err"; then
    exit 1
  fi
  grep -q 'not the current origin/main tip' "$TMP/old.err" || exit 1
) && ok "older SHA ancestor of main but not tip → rejected" || bad "ancestor-not-tip must be rejected"

OTHER=$(gitq init -q "$TMP/other")
(
  cd "$TMP/other"
  git config user.email t@t && git config user.name t
  echo z > z && gitq add z && gitq commit -qm Z
  FOREIGN=$(git rev-parse HEAD)
  cd "$TMP/ws"
  if deploy_require_on_main_history "$FOREIGN" >/dev/null 2>"$TMP/foreign.err"; then
    exit 1
  fi
  grep -qi 'not in origin/main history' "$TMP/foreign.err" || exit 1
) && ok "non-main commit → history guard rejects" || bad "non-main commit must be rejected"

# checkout mismatch
(
  cd "$TMP/ws"
  TIP=$(git rev-parse HEAD)
  if deploy_require_checkout_matches "$OLD" >/dev/null 2>"$TMP/mismatch.err"; then
    exit 1
  fi
  grep -q 'checkout HEAD' "$TMP/mismatch.err" || exit 1
) && ok "checkout HEAD != requested SHA → rejected" || bad "checkout mismatch must be rejected"

# full guard on tip succeeds
(
  cd "$TMP/ws"
  TIP=$(git rev-parse HEAD)
  deploy_guard_main_tip "$TIP" "workflow_dispatch" "refs/heads/main" >/dev/null
) && ok "full guard passes for current tip on main" || bad "full guard should pass for tip"

(
  cd "$TMP/ws"
  git checkout -q "$OLD"
  if deploy_guard_main_tip "$OLD" "workflow_dispatch" "refs/heads/main" >/dev/null 2>"$TMP/full.err"; then
    git checkout -q main
    exit 1
  fi
  git checkout -q main
  grep -q 'not the current origin/main tip' "$TMP/full.err" || exit 1
) && ok "full guard fail-closed for stale SHA (no SSH in this helper)" || bad "full guard must fail for stale SHA"

(
  cd "$TMP/ws"
  TIP=$(git rev-parse HEAD)
  if deploy_guard_main_tip "$TIP" "workflow_dispatch" "refs/heads/dev" >/dev/null 2>"$TMP/ref.err"; then
    exit 1
  fi
  grep -q 'refs/heads/main' "$TMP/ref.err" || exit 1
) && ok "non-main GITHUB_REF refused" || bad "must require refs/heads/main"

# --------------------------------------------------------------------------- Cancel selector: waiting only
python3 - "$SELECT" <<'PY' && ok "selector cancels only foreign waiting runs" || bad "selector waiting-only policy"
import json, os, subprocess, sys, tempfile
selector = sys.argv[1]
payload = {
  "workflow_runs": [
    {"id": 10, "status": "waiting"},
    {"id": 11, "status": "in_progress"},
    {"id": 12, "status": "queued"},
    {"id": 13, "status": "pending"},
    {"id": 14, "status": "requested"},
    {"id": 15, "status": "completed"},
    {"id": 99, "status": "waiting"},
  ]
}
fd, path = tempfile.mkstemp(suffix=".json")
os.close(fd)
with open(path, "w", encoding="utf-8") as fh:
    json.dump(payload, fh)
out = subprocess.check_output(["python3", selector, path, "99"], text=True)
os.unlink(path)
ids = {int(x) for x in out.split() if x.strip()}
if ids != {10}:
    print("expected {10}, got", ids, file=sys.stderr)
    sys.exit(1)
PY

# Wrapper fixture path must not call network
(
  FIXTURE_JSON="$TMP/empty.json"
  echo '{"workflow_runs":[]}' > "$FIXTURE_JSON"
  GITHUB_RUN_ID=1
  export FIXTURE_JSON GITHUB_RUN_ID
  OUT=$(bash "$CANCEL")
  [ -z "$OUT" ]
) && ok "cancel wrapper with empty fixture prints no IDs" || bad "empty fixture should cancel nothing"

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "deploy-stale-sha-guard-check: ALL PASS"
  exit 0
fi
echo "deploy-stale-sha-guard-check: $FAILS FAIL(S)" >&2
exit 1
