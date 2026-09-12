#!/bin/bash
# Prove Approval Relay Dispatch treats empty {"claims":[]} as success
# and rejects HTML. Does NOT call GitHub or production.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

PY="$ROOT/scripts/lib/approval_dispatch_claims.py"
WF="$ROOT/.github/workflows/approval-dispatch.yml"
[ -f "$PY" ] || bad "missing approval_dispatch_claims.py"
[ -f "$WF" ] || bad "missing approval-dispatch.yml"
python3 -m py_compile "$PY" && ok "parser compiles" || bad "parser syntax"

python3 - "$PY" <<'PY' && ok "empty body and empty claims are zero rows" || bad "empty-claims vectors"
import subprocess, sys
py = sys.argv[1]

def run(body: str):
    p = subprocess.run(["python3", py, "-"], input=body, capture_output=True, text=True)
    return p.returncode, p.stdout, p.stderr

rc, out, err = run("")
assert rc == 0 and out == "", (rc, out, err)
rc, out, err = run('{"claims":[]}')
assert rc == 0 and out == ""
rc, out, err = run('{"claims":[{"approval_request_id":"a","pull_number":1,"expected_head_sha":"' + "b"*40 + '"}]}')
assert rc == 0 and out.startswith("a\t1\t")
rc, out, err = run("<html>nope</html>")
assert rc != 0
print("vectors ok")
PY

grep -q 'approval_dispatch_claims.py' "$WF" && ok "workflow uses tested parser" || bad "workflow must call approval_dispatch_claims.py"
grep -q 'actions/checkout@v4' "$WF" && ok "workflow checks out parser" || bad "workflow must checkout before python helper"
grep -q '1-59/5 \* \* \* \*' "$WF" \
  && grep -q '2-59/5 \* \* \* \*' "$WF" \
  && grep -q '3-59/5 \* \* \* \*' "$WF" \
  && grep -q '4-59/5 \* \* \* \*' "$WF" \
  && ok "relay has staggered minute pickup schedules" \
  || bad "relay must retain all staggered pickup schedules"
if grep -q 'json.load(open(sys.argv' "$WF"; then
  bad "workflow must not inline json.load on the relay body"
else
  ok "workflow no longer inlines json.load on the relay body"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "approval-dispatch-check: ALL PASS"
  exit 0
fi
echo "approval-dispatch-check: $FAILS FAIL(S)" >&2
exit 1
