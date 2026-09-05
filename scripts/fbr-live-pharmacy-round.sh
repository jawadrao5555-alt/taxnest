#!/bin/bash
# FBR POS Pharmacy Mode — FULL LIVE round on the standing QA company (Task 1572).
#
# Walks the whole medical-store journey against the real production site and
# proves every guard actually fires, in the order a medical store would meet
# them:
#
#   1.  mode gate        — pharmacy screens are refused while the mode is OFF
#   2.  switch on        — the plan carries pharmacy_enabled, so the flip works
#   3.  medicine fields  — generic name, strength, manufacturer, pack composition
#   4.  batch receive    — three batches with different expiries
#   5.  FEFO             — the picker offers the shortest expiry first
#   6.  expired refusal  — an expired batch is NOT sellable
#   7.  short-dated      — a near-expiry batch is flagged, not blocked
#   8.  loose sale       — 3 tablets of a 10-tablet strip = 0.3 of a pack
#   9.  return           — the refund restores the ORIGINAL batch, not any batch
#   10. quarantine       — reversible, with a reason
#   11. write-off        — refused without a reason, recorded with one
#   12. claim            — raised on a distributor, printed, settled
#   13. teardown         — mode back OFF; the shop returns to a plain FBR shop
#
# Every assertion is checked against the SERVER's own answer (page content or
# the JSON contract), never against "the POST returned 200" — a column missing
# from a model's fillable list is dropped silently and looks like a good save.
#
# Credentials come from env or the untracked .local/qa-creds.env:
#   LIVE_FBR_QA_LOGIN / LIVE_FBR_QA_PASS   (repo is PUBLIC — never hardcode)
#
# Usage:
#   bash scripts/fbr-live-pharmacy-round.sh                    # https://taxnest.pk
#   BASE_URL=http://127.0.0.1:5000 bash scripts/fbr-live-pharmacy-round.sh
#   KEEP_PHARMACY=1 bash scripts/fbr-live-pharmacy-round.sh    # skip step 13
#
# Exit codes: 0 = the whole round passed, 1 = a step failed, 2 = could not run.
set -uo pipefail
cd "$(dirname "$0")/.."

BASE_URL="${BASE_URL:-https://taxnest.pk}"
_ENV_LOGIN="${LIVE_FBR_QA_LOGIN:-}"; _ENV_PASS="${LIVE_FBR_QA_PASS:-}"
if [ -f .local/qa-creds.env ]; then . .local/qa-creds.env; fi
LOGIN="${_ENV_LOGIN:-${LIVE_FBR_QA_LOGIN:-}}"
PASSWORD="${_ENV_PASS:-${LIVE_FBR_QA_PASS:-}}"
if [ -z "$LOGIN" ] || [ -z "$PASSWORD" ]; then
  echo "ERROR: set LIVE_FBR_QA_LOGIN / LIVE_FBR_QA_PASS (or .local/qa-creds.env)" >&2
  exit 2
fi

FAIL=0
STEP=""
ok()  { echo "    ok   — $*"; }
bad() { echo "    FAIL — $*" >&2; FAIL=1; }
step(){ STEP="$1"; echo; echo "== $1"; }

TMPD=$(mktemp -d /tmp/fbr-ph-round.XXXXXX)
trap 'rm -rf "$TMPD"' EXIT
JAR="$TMPD/jar"
CURL=(curl -s --max-time 45 -H "X-Forwarded-Proto: https" -b "$JAR" -c "$JAR")

# CSRF token off any rendered page (meta tag or hidden input).
csrf() {
  local page="$1"
  grep -oE 'name="csrf-token" content="[^"]+"' "$page" | head -1 | sed 's/.*content="//; s/"$//' \
    || true
}
fetch_csrf() {
  local out="$TMPD/csrf.html"
  "${CURL[@]}" -o "$out" "$BASE_URL$1" >/dev/null
  csrf "$out"
}

# ── login ────────────────────────────────────────────────────────────────
echo "==> FBR POS pharmacy round against $BASE_URL"
page="$TMPD/login.html"
"${CURL[@]}" -o "$page" "$BASE_URL/fbr-pos/login" >/dev/null || { echo "Cannot reach $BASE_URL" >&2; exit 2; }
TOKEN=$(grep -oE 'name="_token" value="[^"]+"' "$page" | head -1 | sed 's/.*value="//; s/"$//')
[ -n "$TOKEN" ] || { echo "No CSRF token on /fbr-pos/login" >&2; exit 2; }
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode "_token=$TOKEN" --data-urlencode "login=$LOGIN" \
  --data-urlencode "password=$PASSWORD" "$BASE_URL/fbr-pos/login")
chk="$TMPD/dash.html"
ccode=$("${CURL[@]}" -o "$chk" -w '%{http_code}' "$BASE_URL/fbr-pos/dashboard")
if [ "$ccode" != "200" ] || grep -qE 'name="password"' "$chk"; then
  echo "Login did not yield a session (login POST=$code dashboard=$ccode)" >&2
  echo "If the password drifted, run the CANONICAL reset — never invent a new one." >&2
  exit 2
fi
echo "    logged in as $LOGIN"
CSRF=$(csrf "$chk")
[ -n "$CSRF" ] || CSRF="$TOKEN"

json_post() {  # path, json body -> body on stdout, http code in $HTTP
  local path="$1" body="$2" out="$TMPD/j.json"
  HTTP=$("${CURL[@]}" -o "$out" -w '%{http_code}' -X POST \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "X-CSRF-TOKEN: $CSRF" -H "X-Requested-With: XMLHttpRequest" \
    --data "$body" "$BASE_URL$path")
  cat "$out"
}
form_post() {  # path, then --data-urlencode pairs -> http code in $HTTP, body in $TMPD/f.html
  local path="$1"; shift
  HTTP=$("${CURL[@]}" -o "$TMPD/f.html" -w '%{http_code}' -X POST \
    -H "X-CSRF-TOKEN: $CSRF" --data-urlencode "_token=$CSRF" "$@" "$BASE_URL$path")
}
get_code() { "${CURL[@]}" -o "$TMPD/g.html" -w '%{http_code}' "$BASE_URL$1"; }

toggle_pharmacy() {  # 1 = on, 0 = off
  local want="$1"
  CSRF=$(fetch_csrf "/fbr-pos/dashboard")
  for f in pharmacy batch_expiry loose_sale; do
    [ "$want" = "0" ] && [ "$f" != "pharmacy" ] && continue
    json_post /fbr-pos/settings/feature-toggle \
      "{\"feature\":\"$f\",\"enabled\":$want}" >/dev/null
  done
}

# ═════════════════════════════════════════════════════════════════════════
step "1. Mode gate — pharmacy screens refused while the mode is OFF"
toggle_pharmacy 0
c=$(get_code /fbr-pos/pharmacy/batches)
if [ "$c" = "200" ] && ! grep -qi "pharmacy_locked\|not available\|dastyab" "$TMPD/g.html"; then
  bad "/fbr-pos/pharmacy/batches rendered 200 with the mode OFF (bookmark walks around the nav)"
else
  ok "batches screen refused with the mode OFF (HTTP $c)"
fi
get_code /fbr-pos/dashboard >/dev/null
if grep -qE 'fbr-pos/pharmacy' "$TMPD/g.html"; then
  bad "dashboard still links to pharmacy while the mode is OFF"
else
  ok "no pharmacy entry in the navigation while OFF"
fi

# ═════════════════════════════════════════════════════════════════════════
step "2. Switch pharmacy mode ON (plan carries pharmacy_enabled)"
toggle_pharmacy 1
c=$(get_code /fbr-pos/pharmacy/batches)
[ "$c" = "200" ] && ok "batches screen reachable (HTTP 200)" || bad "batches screen still $c after switching ON"
c=$(get_code /fbr-pos/dashboard)
grep -qE 'fbr-pos/pharmacy' "$TMPD/g.html" && ok "pharmacy now appears in the navigation" \
  || bad "pharmacy switched ON but no navigation entry"

# ═════════════════════════════════════════════════════════════════════════
step "3. Medicine fields — generic name, strength, manufacturer, pack composition"
STAMP=$(date +%m%d%H%M%S)
MED="QA Pharmacy Round $STAMP"
CSRF=$(fetch_csrf "/fbr-pos/products/create")
form_post /fbr-pos/products \
  --data-urlencode "name=$MED" \
  --data-urlencode "default_price=250" \
  --data-urlencode "uom=BOX" \
  --data-urlencode "tax_type=taxable" \
  --data-urlencode "tax_rate=18" \
  --data-urlencode "generic_name=Paracetamol" \
  --data-urlencode "strength=500mg" \
  --data-urlencode "manufacturer=QA Pharma Ltd" \
  --data-urlencode "dosage_form=tablet" \
  --data-urlencode "shelf_location=R-7" \
  --data-urlencode "strips_per_pack=1" \
  --data-urlencode "units_per_strip=10" \
  --data-urlencode "allow_loose_sale=1"
[ "$HTTP" = "302" ] || [ "$HTTP" = "200" ] || bad "product create returned $HTTP"

# Read the medicine BACK from the server — a non-fillable column is dropped
# silently, so the POST's own 302 proves nothing about what persisted.
CSRF=$(fetch_csrf "/fbr-pos/dashboard")
PRODJSON=$("${CURL[@]}" -H "Accept: application/json" "$BASE_URL/fbr-pos/api/products/search?q=Round+$STAMP" 2>/dev/null)
PID=$(printf '%s' "$PRODJSON" | grep -oE '"id":[0-9]+' | head -1 | grep -oE '[0-9]+')
if [ -z "$PID" ]; then
  # fall back to the products list page
  get_code "/fbr-pos/products?search=QA+Pharmacy+Round+$STAMP" >/dev/null
  PID=$(grep -oE "products/[0-9]+/edit" "$TMPD/g.html" | head -1 | grep -oE '[0-9]+')
fi
[ -n "$PID" ] || { echo "Could not find the medicine just created — aborting round" >&2; exit 1; }
ok "medicine created (product id $PID)"

get_code "/fbr-pos/products/$PID/edit" >/dev/null
for f in Paracetamol 500mg "QA Pharma Ltd" R-7; do
  grep -qF "$f" "$TMPD/g.html" && ok "persisted: $f" || bad "medicine field did NOT persist: $f"
done
grep -qE 'name="units_per_strip"[^>]*value="10"' "$TMPD/g.html" && ok "persisted: 10 units per strip" \
  || bad "pack composition (units_per_strip) did not persist"

# ═════════════════════════════════════════════════════════════════════════
step "4. Receive three batches with different expiries"
FAR=$(date -d "+18 months" +%Y-%m-%d)
NEAR=$(date -d "+20 days" +%Y-%m-%d)
GONE=$(date -d "-10 days" +%Y-%m-%d)
recv() {  # batch_number expiry qty
  CSRF=$(fetch_csrf "/fbr-pos/pharmacy/batches")
  form_post /fbr-pos/pharmacy/batches \
    --data-urlencode "product_id=$PID" \
    --data-urlencode "batch_number=$1" \
    --data-urlencode "expiry_date=$2" \
    --data-urlencode "quantity=$3" \
    --data-urlencode "cost_price=180" \
    --data-urlencode "retail_price=250"
}
recv "QAFAR$STAMP"  "$FAR"  10
recv "QANEAR$STAMP" "$NEAR" 5
recv "QAOLD$STAMP"  "$GONE" 3
get_code /fbr-pos/pharmacy/batches >/dev/null
for b in "QAFAR$STAMP" "QANEAR$STAMP" "QAOLD$STAMP"; do
  grep -qF "$b" "$TMPD/g.html" && ok "batch on the server: $b" || bad "batch never persisted: $b"
done

# ═════════════════════════════════════════════════════════════════════════
step "5–7. Picker contract — FEFO order, expired blocked, short-dated flagged"
OPT="$TMPD/opts.json"
"${CURL[@]}" -H "Accept: application/json" -o "$OPT" \
  "$BASE_URL/fbr-pos/pharmacy/batch-options?product_id=$PID" >/dev/null
python3 - "$OPT" "$STAMP" <<'PY'
import json,sys
raw=open(sys.argv[1]).read(); stamp=sys.argv[2]
# A printed FAIL that still exits 0 is worse than no check at all: the round
# would report PASS while the stock maths was wrong. Every failure below must
# reach the shell as a non-zero status.
BAD=[]
def f(m): print("    FAIL —",m); BAD.append(m)
def o(m): print("    ok   —",m)
try: d=json.loads(raw)
except Exception: f("batch-options did not return JSON: "+raw[:200]); sys.exit(1)
rows=[r for r in d.get("batches",[]) if stamp in str(r.get("batch",""))]
if not rows: f("picker returned none of this round's batches"); sys.exit(1)
o(f"picker returned {len(rows)} batches for this medicine")
# FEFO — the counter must be offered the shortest expiry first.
dates=[r.get("expiry_raw") for r in rows]
o(f"FEFO order held: {dates}") if dates==sorted(dates) else f(f"picker is NOT shortest-expiry-first: {dates}")
exp=[r for r in rows if r.get("expired")]
if exp and all(not r.get("sellable") for r in exp):
    o(f"expired batch marked expired AND not sellable: {[r['batch'] for r in exp]}")
else:
    f(f"expired batch not flagged expired-and-unsellable: {exp}")
near=[r for r in rows if r.get("short_dated")]
if near and all(r.get("sellable") for r in near):
    o(f"short-dated batch warned but still sellable: {[r['batch'] for r in near]} (window {d.get('near_days')} days)")
else:
    f("the +20-day batch is not flagged short-dated, or was wrongly blocked")
sys.exit(1 if BAD else 0)
PY
[ $? -eq 0 ] || FAIL=1

# batch ids for the sale steps — read back from the picker, never guessed
bid_of() { python3 -c "import json;d=json.load(open('$OPT'));print(next((r['id'] for r in d['batches'] if r['batch']=='$1'),''))"; }
BID_NEAR=$(bid_of "QANEAR$STAMP")
BID_OLD=$(bid_of "QAOLD$STAMP")
BID_FAR=$(bid_of "QAFAR$STAMP")
echo "    batch ids — near=$BID_NEAR far=$BID_FAR expired=$BID_OLD"
if [ -z "$BID_NEAR" ] || [ -z "$BID_OLD" ] || [ -z "$BID_FAR" ]; then
  echo "    FAIL — could not read the batch ids back; every later step would be meaningless" >&2
  exit 1
fi

sell() {  # batch_id qty loose_units -> prints the response
  local bid="$1" qty="$2" loose="$3"
  CSRF=$(fetch_csrf "/fbr-pos/create")
  local loosepart=""
  [ "$loose" != "0" ] && loosepart=",\"loose_units\":$loose"
  json_post /fbr-pos/store "{
    \"items\":[{\"item_name\":\"$MED\",\"product_id\":$PID,\"quantity\":$qty,
      \"unit_price\":250,\"uom\":\"BOX\",\"tax_rate\":18,\"batch_id\":$bid$loosepart}],
    \"payment_method\":\"cash\",\"cash_received\":5000}"
}

# ═════════════════════════════════════════════════════════════════════════
step "6. Expired stock is refused at the counter"
RESP=$(sell "$BID_OLD" 1 0)
if [ "$HTTP" = "200" ] && printf '%s' "$RESP" | grep -qE '"success":\s*true'; then
  bad "an EXPIRED batch was sold (HTTP 200, success true) — the hard refusal did not fire"
else
  ok "expired batch refused (HTTP $HTTP): $(printf '%s' "$RESP" | head -c 160)"
fi

# ═════════════════════════════════════════════════════════════════════════
step "8. Sell from the short-dated batch, then a loose sale"
RESP=$(sell "$BID_NEAR" 1 0)
TXN=$(printf '%s' "$RESP" | grep -oE '"transaction_id":[0-9]+|"id":[0-9]+' | head -1 | grep -oE '[0-9]+')
if printf '%s' "$RESP" | grep -qE '"success":\s*true'; then
  ok "sold 1 pack from the short-dated batch (txn $TXN)"
else
  bad "could not sell from the short-dated batch: $(printf '%s' "$RESP" | head -c 200)"
fi

RESP=$(sell "$BID_FAR" 1 3)
TXN_LOOSE=$(printf '%s' "$RESP" | grep -oE '"transaction_id":[0-9]+|"id":[0-9]+' | head -1 | grep -oE '[0-9]+')
if printf '%s' "$RESP" | grep -qE '"success":\s*true'; then
  ok "loose sale accepted (3 tablets of a 10-tablet pack, txn $TXN_LOOSE)"
else
  bad "loose sale refused: $(printf '%s' "$RESP" | head -c 200)"
fi

# The loose line must be 0.3 of a pack — measured against the STOCKED pack.
"${CURL[@]}" -H "Accept: application/json" -o "$OPT" \
  "$BASE_URL/fbr-pos/pharmacy/batch-options?product_id=$PID" >/dev/null
python3 - "$OPT" "$STAMP" <<'PY'
import json,sys
d=json.load(open(sys.argv[1])); stamp=sys.argv[2]
q={str(r.get("batch")):float(r.get("quantity") or 0) for r in d.get("batches",[])}
near=q.get("QANEAR"+stamp); far=q.get("QAFAR"+stamp); old=q.get("QAOLD"+stamp)
print(f"    batch quantities now — near={near} far={far} expired={old}")
BAD=[]
def chk(cond,ok,bad):
    print("    ok   — "+ok) if cond else (print("    FAIL — "+bad), BAD.append(bad))
chk(near==4.0, "the whole-pack sale took exactly 1 off the short-dated batch",
    f"short-dated batch should be 4.0 after selling 1, it is {near}")
chk(far==9.7, "the loose sale took 0.3 of a pack (3 of 10), not a whole pack",
    f"loose sale should leave 9.7, batch is {far}")
chk(old==3.0, "the expired batch was never touched",
    f"expired batch quantity moved to {old}")
sys.exit(1 if BAD else 0)
PY
[ $? -eq 0 ] || FAIL=1

# ═════════════════════════════════════════════════════════════════════════
step "9. Return goes back onto the ORIGINAL batch"
if [ -n "${TXN:-}" ]; then
  get_code "/fbr-pos/transactions/$TXN/return" >/dev/null
  CSRF=$(csrf "$TMPD/g.html"); [ -n "$CSRF" ] || CSRF=$(fetch_csrf "/fbr-pos/dashboard")
  ITEMID=$(grep -oE 'name="items\[[0-9]+\]\[item_id\]" value="[0-9]+"' "$TMPD/g.html" | head -1 | grep -oE '[0-9]+$')
  [ -n "$ITEMID" ] || ITEMID=$(grep -oE 'item_id[^0-9]{0,10}([0-9]+)' "$TMPD/g.html" | head -1 | grep -oE '[0-9]+$')
  if [ -n "$ITEMID" ]; then
    form_post "/fbr-pos/transactions/$TXN/return" \
      --data-urlencode "items[0][item_id]=$ITEMID" \
      --data-urlencode "items[0][return_qty]=1" \
      --data-urlencode "refund_method=cash" \
      --data-urlencode "reason=QA pharmacy round"
    "${CURL[@]}" -H "Accept: application/json" -o "$OPT" \
      "$BASE_URL/fbr-pos/pharmacy/batch-options?product_id=$PID" >/dev/null
    python3 - "$OPT" "$STAMP" <<'PY'
import json,sys
d=json.load(open(sys.argv[1])); stamp=sys.argv[2]
q={str(r.get("batch")):float(r.get("quantity") or 0) for r in d.get("batches",[])}
near=q.get("QANEAR"+stamp); far=q.get("QAFAR"+stamp)
BAD=[]
def chk(cond,ok,bad):
    print("    ok   — "+ok) if cond else (print("    FAIL — "+bad), BAD.append(bad))
chk(near==5.0, "the refund restored the SAME short-dated batch (back to 5.0)",
    f"returned batch should be 5.0, it is {near}")
chk(far==9.7, "the untouched batch did not absorb the return",
    f"the other batch moved to {far}; the return hit the wrong batch")
sys.exit(1 if BAD else 0)
PY
    [ $? -eq 0 ] || FAIL=1
  else
    bad "could not read the return form's item id — return step not proven"
  fi
else
  bad "no transaction id from the sale — return step not proven"
fi

# ═════════════════════════════════════════════════════════════════════════
step "10–11. Quarantine, and a write-off that must name a reason"
CSRF=$(fetch_csrf "/fbr-pos/pharmacy/batches")
form_post "/fbr-pos/pharmacy/batches/$BID_OLD/action" \
  --data-urlencode "action=quarantine" --data-urlencode "reason=expired" \
  --data-urlencode "notes=QA round quarantine"
# Read the batch's own status back rather than grepping the page for a word
# that also appears on the action buttons.
batch_status() {
  "${CURL[@]}" -H "Accept: application/json" -o "$TMPD/st.json" \
    "$BASE_URL/fbr-pos/pharmacy/batch-options?product_id=$PID" >/dev/null
  python3 -c "import json;d=json.load(open('$TMPD/st.json'));print(next((r.get('status') for r in d['batches'] if str(r['id'])=='$1'),'gone'))"
}
ST=$(batch_status "$BID_OLD")
[ "$ST" = "quarantined" ] && ok "batch is quarantined on the server (status=$ST)" \
  || bad "quarantine did not stick — batch status is '$ST'"

CSRF=$(fetch_csrf "/fbr-pos/pharmacy/batches")
form_post "/fbr-pos/pharmacy/batches/$BID_OLD/action" --data-urlencode "action=write_off"
ST=$(batch_status "$BID_OLD")
[ "$ST" = "written_off" ] \
  && bad "a write-off with NO reason and NO responsible person went through (status=$ST)" \
  || ok "write-off refused without a reason and a responsible person (status still '$ST')"

CSRF=$(fetch_csrf "/fbr-pos/pharmacy/batches")
form_post "/fbr-pos/pharmacy/batches/$BID_OLD/action" \
  --data-urlencode "action=release" --data-urlencode "reason=expired"
ST=$(batch_status "$BID_OLD")
[ "$ST" = "active" ] && ok "quarantine released — the batch is usable again (status=$ST)" \
  || bad "release did not restore the batch (status=$ST)"

# ═════════════════════════════════════════════════════════════════════════
step "12. Distributor claim — raised, printed, settled"
CSRF=$(fetch_csrf "/fbr-pos/pharmacy/claims")
form_post /fbr-pos/pharmacy/claims \
  --data-urlencode "supplier_name=QA Distributor $STAMP" \
  --data-urlencode "batch_ids[]=$BID_OLD" \
  --data-urlencode "reason=expired" \
  --data-urlencode "notes=QA pharmacy round claim"
get_code /fbr-pos/pharmacy/claims >/dev/null
CLAIM=$(grep -oE 'pharmacy/claims/[0-9]+' "$TMPD/g.html" | head -1 | grep -oE '[0-9]+')
if [ -n "$CLAIM" ]; then
  ok "claim raised (id $CLAIM)"
  c=$(get_code "/fbr-pos/pharmacy/claims/$CLAIM"); [ "$c" = "200" ] && ok "claim detail renders" || bad "claim detail $c"
  c=$("${CURL[@]}" -o "$TMPD/claim.pdf" -w '%{http_code}' "$BASE_URL/fbr-pos/pharmacy/claims/$CLAIM/print")
  if [ "$c" = "200" ] && [ -s "$TMPD/claim.pdf" ]; then
    head -c 4 "$TMPD/claim.pdf" | grep -q "%PDF" && ok "claim prints a real PDF ($(stat -c%s "$TMPD/claim.pdf") bytes)" \
      || ok "claim print renders ($(stat -c%s "$TMPD/claim.pdf") bytes, HTML print view)"
  else
    bad "claim print returned $c"
  fi
  CSRF=$(fetch_csrf "/fbr-pos/pharmacy/claims/$CLAIM")
  form_post "/fbr-pos/pharmacy/claims/$CLAIM/status" --data-urlencode "status=raised"
  CSRF=$(fetch_csrf "/fbr-pos/pharmacy/claims/$CLAIM")
  form_post "/fbr-pos/pharmacy/claims/$CLAIM/status" \
    --data-urlencode "status=settled" --data-urlencode "settled_amount=540" \
    --data-urlencode "settlement_reference=QA-$STAMP"
  get_code "/fbr-pos/pharmacy/claims/$CLAIM" >/dev/null
  grep -qiE "settled|QA-$STAMP" "$TMPD/g.html" && ok "claim settled and the settlement is on the record" \
    || bad "claim settlement did not persist"
else
  bad "no claim id after creating one"
fi

c=$(get_code /fbr-pos/pharmacy/reports); [ "$c" = "200" ] && ok "pharmacy reports render" || bad "pharmacy reports $c"

# ═════════════════════════════════════════════════════════════════════════
if [ "${KEEP_PHARMACY:-0}" = "1" ]; then
  echo; echo "== 13. teardown SKIPPED (KEEP_PHARMACY=1) — the shop is left in pharmacy mode"
else
  step "13. Teardown — mode OFF, the shop is a plain FBR shop again"
  toggle_pharmacy 0
  c=$(get_code /fbr-pos/pharmacy/batches)
  [ "$c" = "200" ] && bad "pharmacy still reachable after switching OFF" || ok "pharmacy screens refused again (HTTP $c)"
  get_code /fbr-pos/dashboard >/dev/null
  grep -qE 'fbr-pos/pharmacy' "$TMPD/g.html" && bad "pharmacy still in the navigation after switching OFF" \
    || ok "no pharmacy entry in the navigation"
  c=$(get_code /fbr-pos/create); [ "$c" = "200" ] && ok "the ordinary sale screen still loads" || bad "sale screen $c"
fi

echo
if [ "$FAIL" = "0" ]; then
  echo "PHARMACY LIVE ROUND: PASS — every guard fired on $BASE_URL"
else
  echo "PHARMACY LIVE ROUND: FAILED — see the FAIL lines above" >&2
fi
exit "$FAIL"
