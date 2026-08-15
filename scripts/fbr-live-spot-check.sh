#!/bin/bash
# FBR POS LIVE spot-check — run AFTER every live deploy.
#
# Logs into the standing LIVE FBR POS QA company (company 39 "QA FBR Audit
# Store" — FBR reporting OFF whole life, provisional/local bills only, nothing
# ever submitted to FBR) and verifies the key FBR screens actually RENDER:
#   HTTP 200 + a language-independent content marker + every inline <script>
#   passes `node --check` (same white-screen class as scripts/pos-white-screen-check.sh).
#
# Credentials come from env or the untracked .local/qa-creds.env:
#   LIVE_FBR_QA_LOGIN / LIVE_FBR_QA_PASS   (repo is PUBLIC — never hardcode)
#
# Usage:
#   bash scripts/fbr-live-spot-check.sh                 # against https://taxnest.com.pk
#   BASE_URL=http://127.0.0.1:5000 LIVE_FBR_QA_LOGIN=... LIVE_FBR_QA_PASS=... bash scripts/fbr-live-spot-check.sh
#
# Exit codes: 0 = all good, 1 = a page failed, 2 = could not run (login/server)
set -uo pipefail
cd "$(dirname "$0")/.."

BASE_URL="${BASE_URL:-https://taxnest.com.pk}"
if [ -f .local/qa-creds.env ]; then . .local/qa-creds.env; fi
LOGIN="${LIVE_FBR_QA_LOGIN:-}"
PASSWORD="${LIVE_FBR_QA_PASS:-}"
if [ -z "$LOGIN" ] || [ -z "$PASSWORD" ]; then
  echo "ERROR: set LIVE_FBR_QA_LOGIN / LIVE_FBR_QA_PASS (or .local/qa-creds.env)" >&2
  exit 2
fi

FAIL=0
bad() { echo "    FAIL: $*" >&2; FAIL=1; }

TMPD=$(mktemp -d /tmp/fbr-spot.XXXXXX)
trap 'rm -rf "$TMPD"' EXIT
JAR="$TMPD/jar"
CURL=(curl -s --max-time 30 -H "X-Forwarded-Proto: https" -b "$JAR" -c "$JAR")

echo "==> FBR POS live spot-check against $BASE_URL"
page=$("${CURL[@]}" "$BASE_URL/fbr-pos/login") || { echo "Cannot reach $BASE_URL" >&2; exit 2; }
token=$(echo "$page" | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"$//')
[ -n "$token" ] || { echo "Could not extract CSRF token from /fbr-pos/login" >&2; exit 2; }
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode "_token=$token" \
  --data-urlencode "login=$LOGIN" \
  --data-urlencode "password=$PASSWORD" \
  "$BASE_URL/fbr-pos/login")
if [ "$code" != "302" ]; then
  echo "Login POST returned $code (expected 302) for $LOGIN" >&2
  echo "PASSWORD DRIFT? Run the CANONICAL reset: bash scripts/fbr-qa-reset-password.sh" >&2
  echo "NEVER rotate this password to a new value (Task 735 — see live-pos-test-company.md)" >&2
  exit 2
fi
echo "    Logged in as $LOGIN"

# path | language-independent marker regex proving real content rendered
PAGES=(
  "/fbr-pos/dashboard|fbr-pos/day-close|fbr-pos/create"
  "/fbr-pos/create|manualItemNameInput|restaurantPos\("
  "/fbr-pos/tax-reports|tax-reports/csv|tax-reports/pdf"
  "/fbr-pos/reports|name=\"from\"|raDailyTrend|analyticsLockedCard"
  "/fbr-pos/day-close|fbr-pos/day-close|dayclose"
)

for entry in "${PAGES[@]}"; do
  path="${entry%%|*}"
  markers="${entry#*|}"
  body="$TMPD/page.html"
  code=$("${CURL[@]}" -o "$body" -w '%{http_code}' "$BASE_URL$path")
  if [ "$code" != "200" ]; then bad "$path returned HTTP $code (expected 200)"; continue; fi
  if grep -qE 'name="password"' "$body"; then bad "$path served the LOGIN page (session lost?)"; continue; fi
  if ! grep -qE "$markers" "$body"; then bad "$path missing content marker (regex: $markers)"; continue; fi
  js_errors=$(python3 - "$body" "$TMPD" <<'PYEOF'
import re, subprocess, sys
body = open(sys.argv[1], encoding="utf-8", errors="replace").read()
tmpd = sys.argv[2]
errs = []
for i, m in enumerate(re.finditer(r"<script\b([^>]*)>(.*?)</script>", body, re.S | re.I)):
    attrs, js = m.group(1), m.group(2)
    if "src=" in attrs:
        continue
    t = re.search(r'type\s*=\s*["\']([^"\']+)', attrs)
    if t and "javascript" not in t.group(1) and t.group(1) != "module":
        continue
    if not js.strip():
        continue
    f = f"{tmpd}/inline_{i}.js"
    open(f, "w", encoding="utf-8").write(js)
    r = subprocess.run(["node", "--check", f], capture_output=True, text=True)
    if r.returncode != 0:
        line = body[:m.start(2)].count("\n") + 1
        lines = r.stderr.strip().splitlines()
        first = next((l for l in lines if "Error" in l), lines[0] if lines else "syntax error")
        errs.append(f"inline <script> at rendered line {line}: {first}")
print("\n".join(errs))
PYEOF
)
  if [ -n "$js_errors" ]; then
    echo "$js_errors" >&2
    bad "$path has inline JS SYNTAX ERROR(s) — white-screen risk"
    continue
  fi
  echo "    OK: $path (200, marker present, inline JS parses)"
done

# CSV export must actually stream CSV, not an error page.
csv_code=$("${CURL[@]}" -o "$TMPD/tax.csv" -w '%{http_code}' "$BASE_URL/fbr-pos/tax-reports/csv")
if [ "$csv_code" != "200" ]; then
  bad "/fbr-pos/tax-reports/csv returned HTTP $csv_code"
elif grep -qiE '<html|<!DOCTYPE' "$TMPD/tax.csv"; then
  bad "/fbr-pos/tax-reports/csv returned HTML, not CSV"
else
  echo "    OK: /fbr-pos/tax-reports/csv (200, CSV body)"
fi

# ── DATA ASSERTIONS (Task 730) ──────────────────────────────────────────────
# Company 39 carries a PERMANENT QA dataset (5 products + 2 reporting-OFF
# final bills + 2 local provisionals). Re-create/repair it any time with
# `bash scripts/fbr-qa-seed.sh` (idempotent) — the seed writes the ACTUAL
# serials the server assigned to .local/fbr-qa-seed-state.env, which we assert
# EXACTLY here. Without the state file we fall back to mode-correct prefix
# checks (FPOS-* only lives on the fbr tab, FLOCAL-* only on the local tab).
STATE_FILE="${QA_STATE_FILE:-.local/fbr-qa-seed-state.env}"
FBR_QA_FINAL_SERIALS=""; FBR_QA_LOCAL_SERIALS=""
[ -f "$STATE_FILE" ] && . "$STATE_FILE"

# 1) FBR tab (default) — seeded finals (invoice_mode fbr/NULL rows only).
tx_body="$TMPD/tx.html"
tx_code=$("${CURL[@]}" -o "$tx_body" -w '%{http_code}' "$BASE_URL/fbr-pos/transactions")
if [ "$tx_code" != "200" ]; then
  bad "/fbr-pos/transactions returned HTTP $tx_code"
elif [ -n "$FBR_QA_FINAL_SERIALS" ]; then
  miss=""
  for s in $FBR_QA_FINAL_SERIALS; do grep -qF "$s" "$tx_body" || miss="$miss $s"; done
  if [ -n "$miss" ]; then bad "/fbr-pos/transactions missing seeded final(s):$miss — run scripts/fbr-qa-seed.sh"; else
    echo "    OK: /fbr-pos/transactions shows seeded finals ($FBR_QA_FINAL_SERIALS)"; fi
elif ! grep -qE 'FPOS-[0-9]{4}-' "$tx_body"; then
  bad "/fbr-pos/transactions has NO FPOS-* final bills — run scripts/fbr-qa-seed.sh"
else
  echo "    OK: /fbr-pos/transactions has FPOS finals (no state file — prefix check)"
fi

# 1b) Local tab — seeded provisionals (invoice_mode='local' rows are EXCLUDED
# from the default fbr tab). Company 39 has no confidential PIN, so tab=local
# renders directly.
txl_body="$TMPD/tx-local.html"
txl_code=$("${CURL[@]}" -o "$txl_body" -w '%{http_code}' "$BASE_URL/fbr-pos/transactions?tab=local")
if [ "$txl_code" != "200" ]; then
  bad "/fbr-pos/transactions?tab=local returned HTTP $txl_code (PIN gate set on QA company?)"
elif [ -n "$FBR_QA_LOCAL_SERIALS" ]; then
  miss=""
  for s in $FBR_QA_LOCAL_SERIALS; do grep -qF "$s" "$txl_body" || miss="$miss $s"; done
  if [ -n "$miss" ]; then bad "/fbr-pos/transactions?tab=local missing seeded provisional(s):$miss — run scripts/fbr-qa-seed.sh"; else
    echo "    OK: /fbr-pos/transactions?tab=local shows seeded provisionals ($FBR_QA_LOCAL_SERIALS)"; fi
elif ! grep -qE 'FLOCAL-[0-9]{4}-' "$txl_body"; then
  bad "/fbr-pos/transactions?tab=local has NO FLOCAL-* provisionals — run scripts/fbr-qa-seed.sh"
else
  echo "    OK: /fbr-pos/transactions?tab=local has FLOCAL provisionals (no state file — prefix check)"
fi
# 2) Products page lists at least one seeded product.
pr_body="$TMPD/products.html"
pr_code=$("${CURL[@]}" -o "$pr_body" -w '%{http_code}' "$BASE_URL/fbr-pos/products")
if [ "$pr_code" != "200" ]; then
  bad "/fbr-pos/products returned HTTP $pr_code"
elif ! grep -qE 'Chai Patti 500g|Basmati Rice 5kg' "$pr_body"; then
  bad "/fbr-pos/products has NO seeded products — QA seed data missing?"
else
  echo "    OK: /fbr-pos/products shows seeded products"
fi
# 3) Tax-report CSV carries real numbers. The report is CURRENT-MONTH only
# (no month selector), so only assert non-zero when this month has sales:
# non-zero total => hard PASS requirement on structure; zero total => SKIP
# with a note (fresh month with no bills yet — same philosophy as the
# 0-bill-day X-report skip). Structure line must exist either way.
if ! grep -qE '"Total Sales excl. Tax \(PKR\)",[0-9]' "$TMPD/tax.csv"; then
  bad "tax-report CSV missing the 'Total Sales excl. Tax' summary row — export shape changed?"
elif grep -qE '"Total Sales excl. Tax \(PKR\)",[0-9]*[1-9]' "$TMPD/tax.csv"; then
  echo "    OK: tax-report CSV has non-zero monthly totals (real data rows)"
else
  echo "    NOTE: tax-report CSV total is 0.00 this month (no bills yet) — non-zero check skipped"
fi

echo ""
if [ $FAIL -ne 0 ]; then
  echo "FBR LIVE SPOT-CHECK: FAILED" >&2
  exit 1
fi
echo "FBR LIVE SPOT-CHECK: PASS — key FBR POS screens render on live."
exit 0
