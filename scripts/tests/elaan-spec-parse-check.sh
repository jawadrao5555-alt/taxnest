#!/bin/bash
# Static tests for the committed Elaan spec parser + insert dry-run wiring.
# Does NOT SSH, deploy, or touch production.
# Usage: bash scripts/tests/elaan-spec-parse-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PARSE="$ROOT/scripts/lib/elaan-spec-parse.py"
INSERT="$ROOT/scripts/elaan-insert.sh"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

TMP=$(mktemp -d)
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

python3 "$PARSE" >/dev/null 2>&1
[ $? -eq 2 ] && ok "parser usage exit 2 with no args" || bad "parser should exit 2 with no args"

cat > "$TMP/ok.yml" <<'YAML'
title: "Spec check unique title"
audience: pos
type: improvement
body: "Lead sentence."
points:
  - "Second point."
category: restaurant
YAML

OUT=$(python3 "$PARSE" "$TMP/ok.yml") || { bad "valid spec should parse"; OUT=""; }
echo "$OUT" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d["title"]=="Spec check unique title"; assert d["audience"]=="pos"; assert d["points"][0]=="Lead sentence."; assert d["points"][1]=="Second point."; assert d["categories"]==["restaurant"]' \
  && ok "valid spec JSON shape (body prepends points)" \
  || bad "valid spec JSON shape"

BASH_OUT=$(python3 "$PARSE" --bash "$TMP/ok.yml") || bad "valid spec --bash"
echo "$BASH_OUT" | grep -q "POINTS+=" && ok "--bash emits POINTS+=" || bad "--bash missing POINTS"

cat > "$TMP/l001.yml" <<'YAML'
title: "Daily L001 ke liye roz Reset dabana zaroori nahi"
audience: pos
points:
  - "should never insert"
YAML
if python3 "$PARSE" "$TMP/l001.yml" >/dev/null 2>"$TMP/l001.err"; then
  bad "reserved Daily L001 title must be rejected"
else
  grep -qi 'reserved\|Daily L001' "$TMP/l001.err" \
    && ok "reserved Daily L001 title rejected" \
    || bad "L001 reject message unclear"
fi

cat > "$TMP/empty.yml" <<'YAML'
audience: pos
points:
  - "no title"
YAML
python3 "$PARSE" "$TMP/empty.yml" >/dev/null 2>&1 && bad "missing title should fail" || ok "missing title fails"

cat > "$TMP/bad-aud.yml" <<'YAML'
title: "x"
audience: web
points:
  - "y"
YAML
python3 "$PARSE" "$TMP/bad-aud.yml" >/dev/null 2>&1 && bad "bad audience should fail" || ok "bad audience fails"

# Example file in repo must parse (it is a template with a placeholder title).
if python3 "$PARSE" "$ROOT/deploy/elaan.example.yml" >/dev/null 2>"$TMP/ex.err"; then
  ok "deploy/elaan.example.yml parses"
else
  bad "deploy/elaan.example.yml does not parse: $(cat "$TMP/ex.err")"
fi

if [ -f "$ROOT/deploy/elaan.yml" ]; then
  python3 "$PARSE" "$ROOT/deploy/elaan.yml" >/dev/null \
    && ok "deploy/elaan.yml parses" \
    || bad "deploy/elaan.yml does not parse"
  python3 "$PARSE" "$ROOT/deploy/elaan.yml" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d["title"]!="Daily L001 ke liye roz Reset dabana zaroori nahi"' \
    && ok "committed spec is not the Daily L001 title" \
    || bad "committed spec must not reuse Daily L001 title"
fi

DRY=$(bash "$INSERT" --from-file "$TMP/ok.yml" --dry-run) || bad "elaan-insert --from-file --dry-run failed"
echo "$DRY" | grep -q "ELAAN_DRY_RUN" && ok "elaan-insert dry-run from spec" || bad "dry-run missing ELAAN_DRY_RUN"

# CI deploy script must insert spec AFTER SSH and BEFORE the freshness check.
CI="$ROOT/scripts/ci-deploy-production.sh"
python3 - "$CI" <<'PY' && ok "CI insert_committed_elaan_spec is called before check_elaan_freshness" || bad "CI elaan insert order wrong"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
a = text.find("insert_committed_elaan_spec")
# the definition also contains the name; find the call line after the function
# The call is a line that is exactly insert_committed_elaan_spec then check
idx_call = text.find("\ninsert_committed_elaan_spec\ncheck_elaan_freshness")
if idx_call < 0:
    sys.exit(1)
idx_apply = text.find("remote_apply")
sys.exit(0 if idx_call < idx_apply else 1)
PY

grep -q 'insert_committed_elaan_spec' "$CI" && ok "CI sources committed spec insert" || bad "CI missing insert_committed_elaan_spec"
grep -q 'elaan-insert.sh' "$CI" && ok "CI uses existing elaan-insert.sh" || bad "CI must call elaan-insert.sh"
if grep -q 'skip_elaan set — not inserting' "$CI"; then
  ok "skip_elaan skips committed insert"
else
  bad "skip_elaan must skip committed insert"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
