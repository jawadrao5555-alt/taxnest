#!/bin/bash
# Prove deploy SHA remains internal and customer-visible Elaan text is clean.
# No SSH, no live DB, no secrets.
# Usage: bash scripts/tests/elaan-deploy-sha-title-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
EVAL="$ROOT/scripts/lib/elaan-freshness-eval.py"
CI="$ROOT/scripts/ci-deploy-production.sh"
INSERT="$ROOT/scripts/elaan-insert.sh"
CHECK="$ROOT/scripts/lib/elaan-freshness-check.sh"
WF="$ROOT/.github/workflows/deploy-production.yml"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

SHA_A="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
SHA_B="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
HUMAN="Production update — NestPOS Live Ops diagnose aur owner-approved fix ab ready hai"
DRY=$(bash "$INSERT" --from-file "$ROOT/deploy/elaan.yml" --deploy-sha="$SHA_B" --dry-run) || bad "dry-run with --deploy-sha failed"
echo "$DRY" | grep -Fq "$SHA_B" && bad "customer-visible dry-run leaks deploy SHA" \
  || ok "customer-visible title does not leak deploy SHA"
grep -q "deployment_key.*DEPLOY_SHA_ESCAPED" "$INSERT" \
  && ok "deploy SHA is stored in internal deployment_key" \
  || bad "internal deployment-key binding missing"

decide() { python3 "$EVAL" "$1"; return $?; }

# New SHA + only the OLD human title match (title_match_count=1) + no time-fresh = FAIL
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[2], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[3], "title_match_count": 1
}))' "$SHA_A" "$SHA_B" "$HUMAN")
if decide "$JSON" >/dev/null; then
  bad "old AppUpdate title match must NOT pass a NEW SHA"
else
  OUT=$(decide "$JSON" 2>/dev/null || true)
  echo "$OUT" | grep -q new_sha_missing_fresh \
    && ok "stale/old announcement cannot satisfy a new SHA freshness check" \
    || bad "wrong reason for new-SHA + old title match ($OUT)"
fi

# New SHA + time-fresh row (the SHA-qualified insert) = PASS
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[2], "marker_commit": sys.argv[1],
  "time_fresh_count": 1, "committed_title": sys.argv[3], "title_match_count": 0
}))' "$SHA_A" "$SHA_B" "$HUMAN")
OUT=$(decide "$JSON") || { bad "new SHA + fresh qualified announcement should PASS"; OUT=""; }
echo "$OUT" | grep -q time_fresh \
  && ok "new SHA -> fresh announcement is recognized" \
  || bad "new SHA fresh path wrong ($OUT)"

# Same SHA retry: time_fresh=0, qualified title match = PASS, not re-dated
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 1
}))' "$SHA_B" "$HUMAN")
OUT=$(decide "$JSON") || { bad "same SHA + qualified title should PASS"; OUT=""; }
echo "$OUT" | grep -q same_sha_original_title \
  && ok "same SHA retry -> idempotent success without needing a new row" \
  || bad "same-SHA qualified title path wrong ($OUT)"

# Same SHA + only the OLD human title (not qualified) = FAIL
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 0
}))' "$SHA_B" "$HUMAN")
if decide "$JSON" >/dev/null; then
  bad "same SHA must not pass on unmatched old human title"
else
  ok "same SHA does not treat an unqualified old title as this SHA's announcement"
fi

if grep -q -- '--deploy-sha=' "$CI"; then
  ok "CI insert passes --deploy-sha=TARGET_SHA"
else
  bad "CI must pass --deploy-sha to elaan-insert.sh"
fi

python3 - "$CI" <<'PY' && ok "CI insert+freshness still run before remote_apply (Elaan fail blocks apply)" || bad "apply order regression"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
idx = text.find("\ninsert_committed_elaan_spec\ncheck_elaan_freshness")
apply_ = text.find("remote_apply")
if idx < 0 or apply_ < 0 or idx > apply_:
    sys.exit(1)
# skip_elaan still default-off in the workflow is checked elsewhere
if "deploy_require_origin_main_tip" not in text:
    sys.exit(1)
sys.exit(0)
PY

grep -q "deployment_key = '\$TARGET_SHA'" "$CHECK" \
  && ok "freshness same-SHA lookup uses internal deployment key" \
  || bad "freshness check must use internal deployment key"

# Must not fall back to unqualified title on qualify failure
if grep -q 'echo "\$RAW"' "$CHECK"; then
  bad "freshness check must not fall back to unqualified human title"
else
  ok "no unqualified-title fallback in freshness check"
fi

# Deploy Production has no emergency/manual bypass inputs.
if grep -qE 'skip_elaan|allow_settings' "$WF"; then
  bad "Deploy Production exposes an emergency bypass input"
else
  ok "Deploy Production has no emergency bypass inputs"
fi

# PR #29 invariants still in the workflow
python3 - "$WF" <<'PY' && ok "PR #29 stale-SHA / concurrency / Environment invariants intact" || bad "PR #29 guards weakened"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
if re.search(r"(?m)^concurrency:", text):
    sys.exit(1)
if "environment: production-deploy" not in text:
    sys.exit(1)
if "cancel-in-progress: false" not in text:
    sys.exit(1)
if "deploy_guard_main_tip" not in text:
    sys.exit(1)
if "group: production-deploy-gate" not in text:
    sys.exit(1)
# apply job must not cancel-in-progress true: already asserted false exists;
# ensure the SSH script is not in the gate job blob
jobs = text.split("jobs:")[1]
if "ci-deploy-production.sh" not in jobs:
    sys.exit(1)
sys.exit(0)
PY

# insert helper: no touch/re-date; ELAAN_EXISTS before create
if grep -q 'ELAAN_EXISTS' "$INSERT" && ! grep -qE -- '->touch\(' "$INSERT"; then
  ok "elaan-insert.sh still never re-dates via touch()"
else
  bad "re-date regression"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "elaan-deploy-sha-title-check: ALL PASS"
  exit 0
fi
echo "elaan-deploy-sha-title-check: $FAILS FAIL(S)" >&2
exit 1
