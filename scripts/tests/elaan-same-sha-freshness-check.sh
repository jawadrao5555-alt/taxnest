#!/bin/bash
# Proves SAME-SHA Elaan freshness rerun safety without SSH or live DB.
# Covers requirements A–I from the same-SHA Elaan fix.
# Usage: bash scripts/tests/elaan-same-sha-freshness-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
EVAL="$ROOT/scripts/lib/elaan-freshness-eval.py"
CHECK="$ROOT/scripts/lib/elaan-freshness-check.sh"
CI="$ROOT/scripts/ci-deploy-production.sh"
DL="$ROOT/scripts/deploy-live.sh"
INSERT="$ROOT/scripts/elaan-insert.sh"
PHP_MODEL="$ROOT/scripts/tests/elaan-insert-idempotency.php"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

[ -f "$EVAL" ] || bad "missing $EVAL"
[ -f "$CHECK" ] || bad "missing $CHECK"
[ -f "$CI" ] || bad "missing $CI"
[ -f "$DL" ] || bad "missing $DL"

SHA_A="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
SHA_B="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
TITLE="Production update — exact approved commit ab live par aata hai"
OTHER="Some unrelated old What's New from last month"

decide() {
  python3 "$EVAL" "$1"
  return $?
}

# A. NEW SHA + no fresh announcement = FAIL
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[2], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[3], "title_match_count": 0
}))' "$SHA_A" "$SHA_B" "$TITLE")
if decide "$JSON" >/dev/null; then
  bad "A: NEW SHA + no fresh announcement should FAIL"
else
  OUT=$(decide "$JSON" 2>/dev/null || true)
  echo "$OUT" | grep -q new_sha_missing_fresh \
    && ok "A: NEW SHA + no fresh announcement = FAIL" \
    || bad "A: wrong reason ($OUT)"
fi

# B. NEW SHA + fresh announcement = PASS
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[2], "marker_commit": sys.argv[1],
  "time_fresh_count": 1, "committed_title": sys.argv[3], "title_match_count": 0
}))' "$SHA_A" "$SHA_B" "$TITLE")
OUT=$(decide "$JSON") || { bad "B: NEW SHA + fresh announcement should PASS"; OUT=""; }
echo "$OUT" | grep -q time_fresh \
  && ok "B: NEW SHA + fresh announcement = PASS" \
  || bad "B: wrong reason ($OUT)"

# C. SAME SHA + existing original published announcement + matching live marker = PASS
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 1
}))' "$SHA_B" "$TITLE")
OUT=$(decide "$JSON") || { bad "C: SAME SHA + original title should PASS"; OUT=""; }
echo "$OUT" | grep -q same_sha_original_title \
  && ok "C: SAME SHA + original published title + matching marker = PASS" \
  || bad "C: wrong reason ($OUT)"

# D. SAME SHA + no matching original announcement = FAIL
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 0
}))' "$SHA_B" "$TITLE")
if decide "$JSON" >/dev/null; then
  bad "D: SAME SHA + missing original title should FAIL"
else
  OUT=$(decide "$JSON" 2>/dev/null || true)
  echo "$OUT" | grep -q same_sha_title_missing \
    && ok "D: SAME SHA + no matching original announcement = FAIL" \
    || bad "D: wrong reason ($OUT)"
fi

# E. SAME SHA + unrelated old announcement only = FAIL
# (time_fresh_count=0 and title_match_count=0 even if other old rows exist)
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 0
}))' "$SHA_B" "$TITLE")
if decide "$JSON" >/dev/null; then
  bad "E: unrelated old announcement must not satisfy same-SHA gate"
else
  ok "E: SAME SHA + unrelated old announcement only = FAIL"
fi
# Also: matching a different title must not be expressible as PASS without title_match
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 0
}))' "$SHA_B" "$OTHER")
if decide "$JSON" >/dev/null; then
  bad "E2: wrong committed title with zero match must FAIL"
else
  ok "E2: wrong/unrelated committed title without match = FAIL"
fi

# F + G: existing title remains idempotent and is NOT re-dated; no duplicate
if [ -f "$PHP_MODEL" ]; then
  php "$PHP_MODEL" >/tmp/elaan-idempotency.out \
    && grep -q 'existing row is not re-dated' /tmp/elaan-idempotency.out \
    && grep -q 'no duplicate is created' /tmp/elaan-idempotency.out \
    && ok "F: existing title remains idempotent and is NOT re-dated" \
    && ok "G: no duplicate AppUpdate is created" \
    || bad "F/G: PHP idempotency model failed"
else
  bad "missing $PHP_MODEL"
fi

# Helper must still no-op without re-date (static)
if grep -q 'ELAAN_EXISTS' "$INSERT" && ! grep -qE -- '->touch\(' "$INSERT"; then
  ok "F2: elaan-insert.sh still emits ELAAN_EXISTS without touch/re-date"
else
  bad "F2: elaan-insert.sh re-date/touch regression"
fi

# H. skip_elaan is not required for the same-SHA rerun
# Prove PASS path does not depend on NO_ELAAN/SKIP_ELAAN in the evaluator.
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 1
}))' "$SHA_B" "$TITLE")
OUT=$(decide "$JSON") || { bad "H: same-SHA PASS must work without skip_elaan"; OUT=""; }
echo "$OUT" | grep -q same_sha_original_title \
  && ok "H: same-SHA rerun PASS does not require skip_elaan" \
  || bad "H: wrong reason ($OUT)"

# I. Exact target SHA remains unchanged — CI still refuses HEAD != TARGET_SHA
grep -q 'checkout HEAD.*TARGET_SHA' "$CI" \
  || grep -q 'checkout HEAD (\$LOCAL_HEAD) != TARGET_SHA' "$CI" \
  || true
if grep -q 'checkout HEAD' "$CI" && grep -q 'TARGET_SHA' "$CI" \
  && grep -q 'refuse to deploy a different commit than the workflow trigger' "$CI"; then
  ok "I: exact target SHA guard unchanged in ci-deploy-production.sh"
else
  bad "I: exact TARGET_SHA guard missing or weakened"
fi

# Wiring: CI + deploy-live source shared gate; ELAAN_EXISTS alone is not treated as fresh
grep -q 'elaan-freshness-check.sh' "$CI" \
  && ok "CI sources shared elaan-freshness-check.sh" \
  || bad "CI must source elaan-freshness-check.sh"
grep -q 'elaan-freshness-check.sh' "$DL" \
  && ok "deploy-live sources shared elaan-freshness-check.sh" \
  || bad "deploy-live must source elaan-freshness-check.sh"

grep -q 'elaan_freshness_check' "$CI" \
  && ok "CI calls elaan_freshness_check" \
  || bad "CI must call elaan_freshness_check"
grep -q 'elaan_freshness_check' "$DL" \
  && ok "deploy-live calls elaan_freshness_check" \
  || bad "deploy-live must call elaan_freshness_check"

if grep -qE 'ELAAN_EXISTS.*PASS|PASS.*ELAAN_EXISTS' "$CHECK" "$EVAL"; then
  bad "must not treat every ELAAN_EXISTS as fresh"
else
  ok "ELAAN_EXISTS alone is not treated as fresh"
fi

# Same-SHA path requires marker commit == target and committed title match
grep -q 'same_sha_original_title' "$EVAL" \
  && ok "evaluator has same_sha_original_title PASS reason" \
  || bad "evaluator missing same_sha_original_title"
grep -q 'new_sha_missing_fresh' "$EVAL" \
  && ok "evaluator preserves new_sha_missing_fresh FAIL" \
  || bad "evaluator missing new_sha_missing_fresh"

# Shared check queries exact title (not any old row) on same-SHA path
grep -q 'ELAAN_TITLE_COUNT' "$CHECK" \
  && grep -q 'title =' "$CHECK" \
  && ok "same-SHA path counts exact title match for pos/all published rows" \
  || bad "same-SHA title match query missing"

# skip_elaan remains emergency-only, not default for same-SHA
if grep -q 'same_sha_original_title' "$CHECK" && ! grep -q 'skip_elaan.*same-SHA\|same-SHA.*skip_elaan set' "$CHECK"; then
  ok "same-SHA PASS path does not set skip_elaan"
else
  bad "same-SHA path must not rely on skip_elaan"
fi

bash -n "$CHECK" && ok "elaan-freshness-check.sh bash -n clean" || bad "elaan-freshness-check.sh bash -n failed"
bash -n "$CI" && ok "ci-deploy-production.sh bash -n clean" || bad "ci-deploy-production.sh bash -n failed"
bash -n "$DL" && ok "deploy-live.sh bash -n clean" || bad "deploy-live.sh bash -n failed"
python3 -m py_compile "$EVAL" && ok "elaan-freshness-eval.py compiles" || bad "elaan-freshness-eval.py compile failed"

# ELAAN_EXISTS must not auto-pass when marker is a different SHA (new deploy reuse)
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[2], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[3], "title_match_count": 1
}))' "$SHA_A" "$SHA_B" "$TITLE")
if decide "$JSON" >/dev/null; then
  bad "title match alone on NEW SHA must not PASS without time-fresh count"
else
  ok "title match alone does not weaken NEW-SHA freshness"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL SAME-SHA ELAAN CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
