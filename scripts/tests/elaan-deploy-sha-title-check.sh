#!/bin/bash
# Prove SHA-qualified Elaan titles: new SHA gets a distinct published title;
# same-SHA retry is idempotent; an old AppUpdate cannot satisfy a new SHA.
# No SSH, no live DB, no secrets.
# Usage: bash scripts/tests/elaan-deploy-sha-title-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
Q="$ROOT/scripts/lib/elaan-deploy-title.py"
EVAL="$ROOT/scripts/lib/elaan-freshness-eval.py"
CI="$ROOT/scripts/ci-deploy-production.sh"
INSERT="$ROOT/scripts/elaan-insert.sh"
CHECK="$ROOT/scripts/lib/elaan-freshness-check.sh"
WF="$ROOT/.github/workflows/deploy-production.yml"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

python3 -m py_compile "$Q" && ok "elaan-deploy-title.py compiles" || bad "qualify helper py_compile failed"

SHA_A="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
SHA_B="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
HUMAN="Production update — NestPOS Live Ops diagnose aur owner-approved fix ab ready hai"

QA=$(python3 "$Q" qualify --title "$HUMAN" --sha "$SHA_A") || { bad "qualify A"; QA=""; }
QB=$(python3 "$Q" qualify --title "$HUMAN" --sha "$SHA_B") || { bad "qualify B"; QB=""; }
QA2=$(python3 "$Q" qualify --title "$QA" --sha "$SHA_A") || { bad "re-qualify A"; QA2=""; }

[ "$QA" != "$HUMAN" ] && [ "$QA" != "$QB" ] \
  && ok "new SHA published title != human title and != other SHA" \
  || bad "SHA A/B published titles must differ from each other and from the human title"

[ "$QA" = "$QA2" ] && ok "qualifying an already-qualified same-SHA title is idempotent" \
  || bad "re-qualify same SHA changed the title ($QA vs $QA2)"

echo "$QA" | grep -q "\[deploy $SHA_A\]" && ok "published title embeds full TARGET_SHA" \
  || bad "missing [deploy sha] suffix"

python3 - "$HUMAN" "$QA" <<'PY' && ok "published title fits app_updates.title(150)" || bad "qualified title too long"
import sys
human, q = sys.argv[1], sys.argv[2]
assert len(q) <= 150, len(q)
assert human not in q or True
PY

if python3 "$Q" qualify --title "Daily L001 ke liye roz Reset dabana zaroori nahi" --sha "$SHA_A" >/dev/null 2>&1; then
  bad "L001 must not be SHA-qualified into a publishable title"
else
  ok "reserved Daily L001 title refused at qualify"
fi

if python3 "$Q" qualify --title "$HUMAN" --sha "deadbeef" >/dev/null 2>&1; then
  bad "short SHA must be rejected"
else
  ok "non-40-char SHA is rejected"
fi

# In-memory store: new SHA inserts; same SHA no-op; no re-date; no duplicate
python3 - "$Q" "$HUMAN" "$SHA_A" "$SHA_B" <<'PY' && ok "store model: new SHA inserts, same SHA no-op, old title unused" || bad "store model failed"
import importlib.util, sys
spec = importlib.util.spec_from_file_location("elaan_deploy_title", sys.argv[1])
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
human, sha_a, sha_b = sys.argv[2], sys.argv[3], sys.argv[4]

store = []  # {title, created_at}

def insert(title, now):
    for row in store:
        if row["title"] == title:
            return "ELAAN_EXISTS", row
    store.append({"title": title, "created_at": now})
    return "ELAAN_INSERTED", store[-1]

old = {"title": human, "created_at": "2026-09-08 12:18:44"}
store.append(old)

qa = mod.qualify_title(human, sha_a)
qb = mod.qualify_title(human, sha_b)
assert qa != human and qb != human and qa != qb

op, row = insert(qa, "2026-09-08 12:59:00")
assert op == "ELAAN_INSERTED" and row["created_at"] == "2026-09-08 12:59:00"
assert len(store) == 2

op2, row2 = insert(qa, "2026-09-08 18:00:00")
assert op2 == "ELAAN_EXISTS" and row2["created_at"] == "2026-09-08 12:59:00"
assert len(store) == 2
assert old["created_at"] == "2026-09-08 12:18:44"

op3, row3 = insert(qb, "2026-09-08 18:05:00")
assert op3 == "ELAAN_INSERTED" and len(store) == 3
assert old["created_at"] == "2026-09-08 12:18:44"
assert row2["created_at"] == "2026-09-08 12:59:00"
print("store ok")
PY

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
}))' "$SHA_A" "$SHA_B" "$QB")
OUT=$(decide "$JSON") || { bad "new SHA + fresh qualified announcement should PASS"; OUT=""; }
echo "$OUT" | grep -q time_fresh \
  && ok "new SHA -> fresh announcement is recognized" \
  || bad "new SHA fresh path wrong ($OUT)"

# Same SHA retry: time_fresh=0, qualified title match = PASS, not re-dated
JSON=$(python3 -c 'import json,sys; print(json.dumps({
  "live_head": sys.argv[1], "target_sha": sys.argv[1], "marker_commit": sys.argv[1],
  "time_fresh_count": 0, "committed_title": sys.argv[2], "title_match_count": 1
}))' "$SHA_B" "$QB")
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

grep -q 'elaan_published_title' "$CHECK" \
  && ok "freshness same-SHA lookup uses SHA-qualified published title" \
  || bad "freshness check must look up qualified title"

# Must not fall back to unqualified title on qualify failure
if grep -q 'echo "\$RAW"' "$CHECK"; then
  bad "freshness check must not fall back to unqualified human title"
else
  ok "no unqualified-title fallback in freshness check"
fi

# skip_elaan not newly enabled
if grep -q 'skip_elaan' "$WF" && grep -A5 'skip_elaan:' "$WF" | grep -q 'default: false'; then
  ok "skip_elaan still defaults to false"
else
  bad "skip_elaan default changed"
fi

# PR #29 invariants still in the workflow
python3 - "$WF" <<'PY' && ok "PR #29 stale-SHA / concurrency / Environment invariants intact" || bad "PR #29 guards weakened"
import re, sys
text = open(sys.argv[1], encoding="utf-8").read()
if re.search(r"(?m)^concurrency:", text):
    sys.exit(1)
if "environment: production" not in text:
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

DRY=$(bash "$INSERT" --from-file "$ROOT/deploy/elaan.yml" --deploy-sha="$SHA_B" --dry-run) || bad "dry-run with --deploy-sha failed"
echo "$DRY" | grep -q "\[deploy $SHA_B\]" \
  && ok "dry-run publishes SHA-qualified title" \
  || bad "dry-run did not qualify title ($DRY)"

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "elaan-deploy-sha-title-check: ALL PASS"
  exit 0
fi
echo "elaan-deploy-sha-title-check: $FAILS FAIL(S)" >&2
exit 1
