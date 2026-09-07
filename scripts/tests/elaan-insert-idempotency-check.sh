#!/bin/bash
# Proves Elaan insert title idempotency without SSH or live DB writes.
# Usage: bash scripts/tests/elaan-insert-idempotency-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
INSERT="$ROOT/scripts/elaan-insert.sh"
CI="$ROOT/scripts/ci-deploy-production.sh"
PHP_MODEL="$ROOT/scripts/tests/elaan-insert-idempotency.php"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

[ -f "$INSERT" ] || bad "missing $INSERT"
[ -f "$CI" ] || bad "missing $CI"

php "$PHP_MODEL" && ok "PHP store model: first insert, no-op, no duplicate, no re-date, L001 reserved" \
  || bad "PHP store model failed"

if [ -f "$ROOT/scripts/tests/elaan-insert-outcome-check.sh" ]; then
  bash "$ROOT/scripts/tests/elaan-insert-outcome-check.sh" \
    && ok "outcome classifier: existing no-op, new insert, genuine fail" \
    || bad "outcome classifier checks failed"
else
  bad "missing scripts/tests/elaan-insert-outcome-check.sh"
fi

if [ -f "$INSERT" ]; then
  grep -q "Daily L001 ke liye roz Reset dabana zaroori nahi" "$INSERT" \
    && grep -q 'will not re-create or re-date that announcement' "$INSERT" \
    && ok "helper still protects Daily L001" \
    || bad "Daily L001 protection missing"

  if grep -q 'ERROR: title already exists' "$INSERT"; then
    bad "helper must not fail when the exact title already exists"
  else
    ok "helper no longer errors on an existing exact title"
  fi

  python3 - "$INSERT" <<'PY' && ok "helper: nonempty existing title prints ELAAN_EXISTS and exits 0 before create" || bad "helper existing-title path is not a successful no-op"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
# Streamed PHP inside the heredoc
m = re.search(r"\$existing = App\\Models\\AppUpdate::where\('title'", text)
if not m:
    sys.exit(1)
rest = text[m.start():]
create = rest.find("App\\Models\\AppUpdate::create")
if create < 0:
    sys.exit(1)
block = rest[:create]
if "ELAAN_EXISTS" not in block or "exit(0)" not in block:
    sys.exit(1)
if "isNotEmpty()" not in block:
    sys.exit(1)
if "->update(" in block or "->save(" in block or "->touch(" in block:
    sys.exit(1)
if "created_at =" in block or "created_at=>" in block:
    sys.exit(1)
if "exit(1)" in block:
    sys.exit(1)
sys.exit(0)
PY

  if grep -q 'createdTs > \$sinceTs' "$INSERT" || grep -q 'createdTs > $sinceTs' "$INSERT"; then
    bad "helper must not gate exact-title no-op on the deploy marker"
  else
    ok "exact-title no-op is not gated on the last deploy marker"
  fi
fi

if [ -f "$CI" ]; then
  grep -q 'skip_elaan set — not inserting a committed spec' "$CI" \
    && ok "skip_elaan still skips committed insert" \
    || bad "skip_elaan insert skip missing"

  GATE="$ROOT/scripts/lib/elaan-freshness-check.sh"
  if grep -q 'SKIP_ELAAN' "$CI" && [ -f "$GATE" ] && grep -q 'ELAAN SKIPPED' "$GATE"; then
    ok "skip_elaan still skips freshness gate (shared elaan-freshness-check.sh)"
  else
    bad "skip_elaan freshness skip missing"
  fi

  python3 - "$CI" <<'PY' && ok "CI continues to freshness gate then remote_apply after insert exit 0" || bad "CI does not proceed after successful insert/no-op"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
idx = text.find("\ninsert_committed_elaan_spec\ncheck_elaan_freshness")
if idx < 0:
    sys.exit(1)
apply_at = text.find("remote_apply")
if apply_at < 0 or idx > apply_at:
    sys.exit(1)
# non-zero insert still fails the job; zero (INSERTED or EXISTS) continues
if "elaan-insert.sh" not in text or "|| fail \"committed Elaan spec insert failed" not in text:
    sys.exit(1)
sys.exit(0)
PY
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
