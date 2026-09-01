#!/bin/bash
# Seed the standing LIVE FBR POS QA company (39 "QA FBR Audit Store") with the
# fixed QA dataset that scripts/fbr-live-spot-check.sh data-asserts (Task 730).
#
# SAFETY (fail-closed preflight — no write happens unless BOTH hold):
#   * The logged-in session belongs to the expected QA company (name asserted
#     on the dashboard; override with QA_COMPANY_NAME for the mock test).
#   * FBR reporting is OFF (sale screen bakes `var fbrCharge = 0`) — so seeded
#     bills can NEVER be submitted to FBR.
#
# IDEMPOTENT — safe to re-run any time:
#   * Products: skipped when the exact name already exists.
#   * Bills: every POST carries a FIXED offline_uuid, so the server-side replay
#     guard returns the existing bill instead of a duplicate.
#
# DURABLE VERIFICATION STATE: the ACTUAL invoice serials returned by the server
# (fresh or replayed) are written to .local/fbr-qa-seed-state.env; the
# spot-check asserts those exact serials (year-rollover / pre-existing-serial
# proof) and falls back to prefix checks when the state file is absent.
#
# Credentials: LIVE_FBR_QA_LOGIN / LIVE_FBR_QA_PASS from env or .local/qa-creds.env.
# Usage: bash scripts/fbr-qa-seed.sh            # against https://taxnest.pk
#        BASE_URL=... bash scripts/fbr-qa-seed.sh
set -uo pipefail
cd "$(dirname "$0")/.."

BASE_URL="${BASE_URL:-https://taxnest.pk}"
QA_COMPANY_NAME="${QA_COMPANY_NAME:-QA FBR Audit Store}"
STATE_FILE="${QA_STATE_FILE:-.local/fbr-qa-seed-state.env}"
if [ -f .local/qa-creds.env ]; then . .local/qa-creds.env; fi
LOGIN="${LIVE_FBR_QA_LOGIN:-}"
PASSWORD="${LIVE_FBR_QA_PASS:-}"
if [ -z "$LOGIN" ] || [ -z "$PASSWORD" ]; then
  echo "ERROR: set LIVE_FBR_QA_LOGIN / LIVE_FBR_QA_PASS (or .local/qa-creds.env)" >&2
  exit 2
fi

TMPD=$(mktemp -d /tmp/fbr-seed.XXXXXX)
trap 'rm -rf "$TMPD"' EXIT
JAR="$TMPD/jar"
CURL=(curl -s --max-time 30 -H "X-Forwarded-Proto: https" -b "$JAR" -c "$JAR")

echo "==> FBR QA seed against $BASE_URL"
page=$("${CURL[@]}" "$BASE_URL/fbr-pos/login") || { echo "Cannot reach $BASE_URL" >&2; exit 2; }
token=$(echo "$page" | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"$//')
[ -n "$token" ] || { echo "Could not extract CSRF token" >&2; exit 2; }
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode "_token=$token" \
  --data-urlencode "login=$LOGIN" \
  --data-urlencode "password=$PASSWORD" \
  "$BASE_URL/fbr-pos/login")
if [ "$code" != "302" ]; then
  echo "Login failed ($code) for $LOGIN — run the CANONICAL reset: bash scripts/fbr-qa-reset-password.sh" >&2
  echo "NEVER rotate this password to a new value (Task 735 — see live-pos-test-company.md)" >&2
  exit 2
fi

# ── FAIL-CLOSED PREFLIGHT ───────────────────────────────────────────────────
dash="$TMPD/dash.html"
"${CURL[@]}" -o "$dash" "$BASE_URL/fbr-pos/dashboard"
tok=$(grep -oE 'name="csrf-token" content="[^"]+"' "$dash" | head -1 | sed 's/.*content="//; s/"$//')
[ -n "$tok" ] || { echo "No csrf-token on dashboard (login bounced?)" >&2; exit 2; }
if ! grep -qF "$QA_COMPANY_NAME" "$dash"; then
  echo "ABORT: dashboard does not show expected QA company '$QA_COMPANY_NAME' — creds may point at the WRONG tenant. NO data written." >&2
  exit 2
fi
create="$TMPD/create.html"
"${CURL[@]}" -o "$create" "$BASE_URL/fbr-pos/create"
# Reporting-OFF markers: classic create screen bakes `var fbrCharge = 0`;
# the universal screen bakes Alpine state `fbrEnabled: false`.
if ! grep -qE 'var fbrCharge = 0|fbrEnabled: false' "$create"; then
  echo "ABORT: FBR reporting is NOT OFF on this company (fbrCharge marker != 0) — seeding could submit bills to FBR. NO data written." >&2
  exit 2
fi
echo "    Preflight OK: company '$QA_COMPANY_NAME', FBR reporting OFF"

FAIL=0

# ── Products (name|price|tax_type) — skip when exact name already exists ────
seed_product() {
  local name="$1" price="$2" tt="$3"
  local found
  found=$("${CURL[@]}" -G --data-urlencode "q=$name" "$BASE_URL/fbr-pos/api/products/search" | python3 -c '
import json,sys
q=sys.argv[1].lower()
try: d=json.load(sys.stdin)
except Exception: d=[]
items=d.get("products", d) if isinstance(d, dict) else d
print(any((p.get("name") or "").lower()==q for p in items if isinstance(p, dict)))
' "$name")
  if [ "$found" = "True" ]; then echo "    product exists: $name"; return; fi
  local code
  code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
    --data-urlencode "_token=$tok" \
    --data-urlencode "name=$name" \
    --data-urlencode "default_price=$price" \
    --data-urlencode "tax_type=$tt" \
    --data-urlencode "uom=U" \
    --data-urlencode "show_on_sale=1" \
    "$BASE_URL/fbr-pos/products")
  if [ "$code" = "302" ]; then echo "    product created: $name"; else echo "    FAIL product $name ($code)" >&2; FAIL=1; fi
}
seed_product "Chai Patti 500g" 850 taxable
seed_product "Cooking Oil 1L" 620 taxable
seed_product "Basmati Rice 5kg" 2350 taxable
seed_product "Surf Excel 1kg" 590 taxable
seed_product "Roti Plain" 25 exempt

# ── Bills — FIXED offline_uuid per bill = server replay guard makes re-runs
# return the SAME existing bill (no duplicates). UUIDs are QA constants.
# The serial the server actually assigned is captured for the state file —
# never assume a specific year/sequence.
FINAL_SERIALS=()
LOCAL_SERIALS=()
seed_bill() { # uuid, json-body (without offline_uuid), label, kind(final|local)
  local uuid="$1" body="$2" label="$3" kind="$4"
  local resp serial mode
  resp=$("${CURL[@]}" -H "Content-Type: application/json" -H "Accept: application/json" -H "X-CSRF-TOKEN: $tok" \
    -X POST -d "${body%\}},\"offline_uuid\":\"$uuid\"}" "$BASE_URL/fbr-pos/store")
  if ! echo "$resp" | grep -qE '"success": ?true'; then
    echo "    FAIL bill $label: $resp" >&2; FAIL=1; return
  fi
  serial=$(echo "$resp" | grep -oE '"invoice_number": ?"[^"]+"' | head -1 | sed 's/.*: *"//; s/"$//')
  mode=$(echo "$resp" | grep -oE '"invoice_mode": ?"[^"]+"' | head -1 | sed 's/.*: *"//; s/"$//')
  # Belt & braces: a bill meant to stay local/provisional must come back local;
  # finals must come back fbr-mode WITHOUT an FBR invoice number (reporting OFF).
  if [ "$kind" = "local" ] && [ "$mode" != "local" ]; then
    echo "    FAIL bill $label: expected invoice_mode=local, got '$mode' ($serial)" >&2; FAIL=1; return
  fi
  if [ "$kind" = "final" ] && echo "$resp" | grep -qE '"fbr_invoice_number": ?"[^"]'; then
    echo "    FAIL bill $label: got a REAL FBR invoice number — reporting was not OFF!" >&2; FAIL=1; return
  fi
  if [ "$kind" = "final" ]; then FINAL_SERIALS+=("$serial"); else LOCAL_SERIALS+=("$serial"); fi
  echo "    bill OK ($label): $serial$(echo "$resp" | grep -oE '"replayed": ?true' | sed 's/.*/ [already seeded]/')"
}
seed_bill "9a730001-0000-4000-8000-fbrqa2026a001" \
  '{"items":[{"item_name":"Chai Patti 500g","quantity":2,"unit_price":850,"tax_rate":18},{"item_name":"Roti Plain","quantity":10,"unit_price":25,"tax_rate":0,"is_tax_exempt":true}],"payment_method":"cash","cash_received":99999}' \
  "final #1 (cash, mixed rates)" final
seed_bill "9a730001-0000-4000-8000-fbrqa2026a002" \
  '{"items":[{"item_name":"Cooking Oil 1L","quantity":1,"unit_price":620,"tax_rate":18},{"item_name":"Basmati Rice 5kg","quantity":1,"unit_price":2350,"tax_rate":18}],"payment_method":"card"}' \
  "final #2 (card)" final
seed_bill "9a730001-0000-4000-8000-fbrqa2026a003" \
  '{"items":[{"item_name":"Surf Excel 1kg","quantity":3,"unit_price":590,"tax_rate":18}],"payment_method":"cash","cash_received":99999,"save_as_provisional":true}' \
  "provisional #1" local
seed_bill "9a730001-0000-4000-8000-fbrqa2026a004" \
  '{"items":[{"item_name":"Chai Patti 500g","quantity":1,"unit_price":850,"tax_rate":18},{"item_name":"Roti Plain","quantity":4,"unit_price":25,"tax_rate":0,"is_tax_exempt":true}],"payment_method":"cash","cash_received":99999,"save_as_provisional":true}' \
  "provisional #2" local

echo ""
if [ $FAIL -ne 0 ]; then echo "FBR QA SEED: FAILED (state file NOT updated)" >&2; exit 1; fi

mkdir -p "$(dirname "$STATE_FILE")"
{
  echo "# Written by scripts/fbr-qa-seed.sh $(date -u +%FT%TZ) — actual serials on live."
  echo "FBR_QA_FINAL_SERIALS=\"${FINAL_SERIALS[*]}\""
  echo "FBR_QA_LOCAL_SERIALS=\"${LOCAL_SERIALS[*]}\""
} > "$STATE_FILE"
echo "FBR QA SEED: OK — dataset present; serials recorded in $STATE_FILE"
echo "    finals: ${FINAL_SERIALS[*]}   locals: ${LOCAL_SERIALS[*]}"
