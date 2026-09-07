#!/bin/bash
# Proves Elaan insert outcome classification without SSH or live DB writes.
# Usage: bash scripts/tests/elaan-insert-outcome-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PY="$ROOT/scripts/lib/elaan-insert-outcome.py"
INSERT="$ROOT/scripts/elaan-insert.sh"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

[ -f "$PY" ] || { echo "missing $PY" >&2; exit 1; }
[ -f "$INSERT" ] || { echo "missing $INSERT" >&2; exit 1; }

classify() {
  python3 "$PY" --rc "$1" --text "$2"
}

EXISTING_LEGACY='ERROR: title already exists in app_updates (id=261 created_at=2026-09-07 14:17:05). This script will not duplicate or re-date it.
ELAAN INSERT FAILED: PHP bootstrap failed on live'

got=$(classify 1 "$EXISTING_LEGACY")
[ "$got" = "exists" ] && ok "existing title (legacy STDERR + exit 1) => exists/no-op" \
  || bad "legacy existing title classified as '$got' (want exists)"

got=$(classify 0 $'ELAAN_EXISTS id=261 title="What\'s New" created_at=2026-09-07 14:17:05\n')
[ "$got" = "exists" ] && ok "existing title (ELAAN_EXISTS + exit 0) => exists/no-op" \
  || bad "ELAAN_EXISTS classified as '$got' (want exists)"

got=$(classify 0 'ELAAN_INSERTED id=262 title="Brand new announcement"')
[ "$got" = "inserted" ] && ok "new title (ELAAN_INSERTED) => insert" \
  || bad "ELAAN_INSERTED classified as '$got' (want inserted)"

got=$(classify 1 'Could not open input file: /tmp/elaan_insert_123.php')
[ "$got" = "fail" ] && ok "genuine PHP bootstrap error => fail" \
  || bad "missing php file classified as '$got' (want fail)"

got=$(classify 255 'Fatal error: Uncaught PDOException: SQLSTATE[HY000] [2002] Connection refused')
[ "$got" = "fail" ] && ok "genuine DB connection error => fail" \
  || bad "connection refused classified as '$got' (want fail)"

got=$(classify 1 "ERROR: reserved Daily L001 title — will not re-create or re-date that announcement.")
[ "$got" = "fail" ] && ok "reserved Daily L001 => fail" \
  || bad "L001 classified as '$got' (want fail)"

got=$(classify 1 "ERROR: reserved Daily L001 title — will not re-create or re-date that announcement.
ELAAN_EXISTS id=1")
[ "$got" = "fail" ] && ok "reserved L001 wins over ELAAN_EXISTS" \
  || bad "L001+EXISTS classified as '$got' (want fail)"

got=$(classify 1 "")
[ "$got" = "fail" ] && ok "empty output + non-zero rc => fail" \
  || bad "empty output classified as '$got' (want fail)"

# Helper must classify after capturing rc — never fail-loud on ssh/php status first.
if grep -q 'fail "PHP bootstrap failed on live"' "$INSERT"; then
  # Allowed only after outcome == fail
  python3 - "$INSERT" <<'PY' && ok "live path classifies outcome before failing bootstrap" || bad "live wrapper still fails on ssh/php rc before classifying existing-title"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
# The Deploy #4 trap: capture substitution || fail bootstrap
if re.search(r'\)\s*\|\|\s*\{\s*echo "\$LIVE_OUT".*fail "PHP bootstrap failed on live"', text, re.S):
    sys.exit(1)
if "elaan-insert-outcome.py" not in text:
    sys.exit(1)
if "LIVE_RC=" not in text:
    sys.exit(1)
sys.exit(0)
PY
else
  bad "live path must still fail genuine bootstrap errors with PHP bootstrap failed on live"
fi

if grep -q 'elaan-insert-outcome.py' "$INSERT" \
  && grep -q 'DEV_RC=' "$INSERT"; then
  python3 - "$INSERT" <<'PY' && ok "dev path classifies outcome instead of failing on PHP rc alone" || bad "dev path still treats any PHP exit 1 as bootstrap failure"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
# Must not fail on DEV_RC before classify
idx = text.find("[ $DEV_RC -eq 0 ] || fail \"PHP bootstrap failed on dev")
if idx >= 0:
    sys.exit(1)
if "elaan-insert-outcome.py" not in text:
    sys.exit(1)
sys.exit(0)
PY
else
  bad "dev path must call elaan-insert-outcome.py"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
