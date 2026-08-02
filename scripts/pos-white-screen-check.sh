#!/bin/bash
# POS white-screen guard — run BEFORE every live deploy.
#
# Catches the class of failure where a page returns HTTP 200 but renders
# completely blank because an inline <script> has a JS syntax error
# (whole page is x-cloak + Alpine x-data → one bad character = white page,
# no server-side error log). Real incident: /pos/features died from a bare
# {{ __('...') }} inside <script> (apostrophe in translation broke JS).
#
# Two layers:
#   1. STATIC : grep-style scan of ALL Blade views for bare __() echoed
#               inside <script> blocks without @js()/Js::from() wrapping.
#   2. RUNTIME: log in as the standing POS test company and fetch the key
#               POS pages; for each page verify
#                 - HTTP 200 (not a redirect/500)
#                 - a page-specific marker element is present (real content)
#                 - EVERY inline <script> passes `node --check` (JS parses)
#
# Usage:
#   bash scripts/pos-white-screen-check.sh                # static + runtime vs local dev server
#   bash scripts/pos-white-screen-check.sh --static-only  # grep scan only (no server needed)
#   BASE_URL=http://127.0.0.1:5000 POS_CHECK_LOGIN=... POS_CHECK_PASSWORD=... bash scripts/pos-white-screen-check.sh
#   FBR_CHECK_LOGIN=... FBR_CHECK_PASSWORD=... override the FBR POS test company.
#
# Panels covered: PRA POS (/pos/*) and FBR POS (/fbr-pos/*) — both are the
# same Alpine/x-cloak pattern, so both get the identical three checks.
#
# Exit codes: 0 = all good, 1 = FAILURE found, 2 = could not run runtime check (server down / login failed)
set -uo pipefail
cd "$(dirname "$0")/.."

BASE_URL="${BASE_URL:-http://127.0.0.1:5000}"
# Credentials come from env or the untracked .local/qa-creds.env — repo is
# PUBLIC, never hardcode passwords in this file.
if [ -f .local/qa-creds.env ]; then . .local/qa-creds.env; fi
LOGIN="${POS_CHECK_LOGIN:-${DEV_POS_LOGIN:-}}"
PASSWORD="${POS_CHECK_PASSWORD:-${DEV_POS_PASS:-}}"
FBR_LOGIN="${FBR_CHECK_LOGIN:-${DEV_FBR_LOGIN:-}}"
FBR_PASSWORD="${FBR_CHECK_PASSWORD:-${DEV_FBR_PASS:-}}"
if [ -z "$LOGIN" ] || [ -z "$PASSWORD" ]; then
    echo "ERROR: POS check credentials missing — set POS_CHECK_LOGIN/POS_CHECK_PASSWORD or create .local/qa-creds.env" >&2
    exit 2
fi
STATIC_ONLY=0
[ "${1:-}" = "--static-only" ] && STATIC_ONLY=1

FAIL=0
say()  { echo "==> $*"; }
bad()  { echo "    FAIL: $*" >&2; FAIL=1; }

# ------------------------------------------------------------------
# 1. STATIC: bare __() echoed inside <script> blocks in Blade views
# ------------------------------------------------------------------
say "Static scan: bare __() inside <script> in Blade views"
STATIC_OUT=$(python3 - <<'PYEOF'
import os, re, sys

root = "resources/views"
script_re = re.compile(r"<script\b([^>]*)>(.*?)</script>", re.S | re.I)
# A blade echo ({{ ... }} or {!! ... !!}) that contains __( and is NOT Js::from-wrapped.
echo_re = re.compile(r"\{\{(?![^}]*Js::from)[^}]*__\(|\{!!(?![^}]*Js::from)[^}]*__\(")

hits = []
for dirpath, _dirs, files in os.walk(root):
    for fn in files:
        if not fn.endswith(".blade.php"):
            continue
        path = os.path.join(dirpath, fn)
        try:
            src = open(path, encoding="utf-8", errors="replace").read()
        except OSError:
            continue
        for m in script_re.finditer(src):
            attrs, body = m.group(1), m.group(2)
            if "src=" in attrs:
                continue
            t = re.search(r'type\s*=\s*["\']([^"\']+)', attrs)
            if t and "javascript" not in t.group(1) and t.group(1) != "module":
                continue  # ld+json, templates, etc.
            for em in echo_re.finditer(body):
                line = src[:m.start(2) + em.start()].count("\n") + 1
                snippet = body[em.start():em.start() + 90].split("\n")[0]
                hits.append(f"{path}:{line}: {snippet.strip()}")

if hits:
    print("\n".join(hits))
    sys.exit(1)
PYEOF
)
if [ -n "$STATIC_OUT" ]; then
  echo "$STATIC_OUT" >&2
  bad "bare __() echoed inside <script> — wrap with @js(...) or Js::from(...) (an apostrophe in a translation WILL white-screen the page)"
else
  echo "    OK: no bare __() inside <script> blocks."
fi

# ------------------------------------------------------------------
# 1b. STATIC: compile EVERY Blade view, then php -l the compiled output.
#     view:cache alone only WRITES compiled files (it never parses them);
#     php -l is what catches an unmatched endif from a GLUED directive:
#     "months@if(...)" - Blade's \B@ regex skips the glued @if but still
#     compiles the closing @endif -> ParseError 500 on the whole page.
#     Real incident (Aug 2026): Task #220 merge 500'd /pos landing + /pos/billing.
# ------------------------------------------------------------------
say "Static scan: compile all Blade views + php -l compiled output"
ARTISAN="env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE php artisan"
if $ARTISAN view:clear -q && $ARTISAN view:cache -q; then
  LINT_ERRS=$(find storage/framework/views -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors' || true)
  if [ -n "$LINT_ERRS" ]; then
    echo "$LINT_ERRS" >&2
    bad "compiled Blade view fails php -l - usually a glued directive (word@if / @endifword) or a use-statement inside @php"
  else
    echo "    OK: every compiled view parses."
  fi
else
  bad "view:cache itself failed - a view has a broken @-directive structure (unclosed @if/@foreach)"
fi

if [ $STATIC_ONLY -eq 1 ]; then
  [ $FAIL -eq 0 ] && echo "WHITE-SCREEN CHECK (static-only): PASS"
  exit $FAIL
fi

# ------------------------------------------------------------------
# 2. RUNTIME: login + fetch key pages (multi-panel: PRA POS + FBR POS)
# ------------------------------------------------------------------
say "Runtime check against $BASE_URL"
if ! curl -s -o /dev/null --max-time 10 "$BASE_URL/pos/login"; then
  echo "    Cannot reach $BASE_URL — start the Laravel Server workflow (or set BASE_URL)." >&2
  exit 2
fi

TMPD=$(mktemp -d /tmp/pos-check.XXXXXX)
trap 'rm -rf "$TMPD"' EXIT
CURL=()  # (re)built per panel by do_login with a fresh cookie jar

# do_login <login_path> <post_login_path> <login> <password>
# Rebuilds CURL[] with a fresh cookie jar and logs in; exits 2 on failure.
do_login() {
  local login_path="$1" post_path="$2" login="$3" password="$4"
  local jar; jar=$(mktemp "$TMPD/jar.XXXXXX")
  CURL=(curl -s --max-time 30 -H "X-Forwarded-Proto: https" -b "$jar" -c "$jar")
  local page token code
  page=$("${CURL[@]}" "$BASE_URL$login_path")
  token=$(echo "$page" | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"$//')
  [ -n "$token" ] || { echo "    Could not extract CSRF token from $login_path." >&2; exit 2; }
  code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
    --data-urlencode "_token=$token" \
    --data-urlencode "login=$login" \
    --data-urlencode "password=$password" \
    "$BASE_URL$login_path")
  if [ "$code" != "302" ]; then
    echo "    Login POST $login_path returned $code (expected 302) for $login." >&2
    exit 2
  fi
  code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE_URL$post_path")
  if [ "$code" != "200" ]; then
    echo "    Post-login $post_path returned $code — login likely failed." >&2
    exit 2
  fi
  echo "    Logged in as $login."
}

# page path | language-independent marker regex (grep -E) proving real content
PRA_PAGES=(
  "/pos/dashboard|pos/invoice/create|day-opening|pos/reports"
  "/pos/customize|id=\"style\""
  "/pos/features|posWizard\("
  "/pos/customers|id=\"addCustomerForm\"|id=\"custSearchInput\""
  "/pos/receipt-settings|receipt-settings\""
  "/pos/restaurant/kitchen-settings|name=\"kot_compact\"|name=\"print_on_pay\""
  "/pos/reports|pos/reports/csv|raDailyTrend"
  "/pos/invoice/create|restaurantPos\(|manualItemNameInput"
  "/pos/riders/tracking|rt-page|rt-map|riderTracking\("
  "/pos/billing|or PKR|3 months"
)
FBR_PAGES=(
  "/fbr-pos/dashboard|fbr-pos/day-close|fbr-pos/create"
  "/fbr-pos/customize|dashboard-style"
  "/fbr-pos/reports|fbr-pos/reports/analytics-pdf|raDailyTrend"
  "/fbr-pos/settings|name=\"fbr_pos_token\"|name=\"fbr_pos_id\""
  "/fbr-pos/create|manualItemNameInput|restaurantPos\("
)

check_page() {
  local path="$1"; shift
  local markers="$1"
  local body_file="$TMPD/page.html"
  local code
  code=$("${CURL[@]}" -o "$body_file" -w '%{http_code}' "$BASE_URL$path")
  if [ "$code" != "200" ]; then
    bad "$path returned HTTP $code (expected 200)"
    return
  fi
  if grep -qE 'name="password"' "$body_file"; then
    bad "$path served the LOGIN page (session lost?)"
    return
  fi
  if ! grep -qE "$markers" "$body_file"; then
    bad "$path is missing its expected content marker (regex: $markers) — page may be blank/broken"
    return
  fi
  # node --check every inline <script> — THIS is what catches the white screen.
  local js_errors
  js_errors=$(python3 - "$body_file" "$TMPD" <<'PYEOF'
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
        errs.append(f"inline <script> starting at rendered line {line}: {first}")
print("\n".join(errs))
PYEOF
)
  if [ -n "$js_errors" ]; then
    echo "$js_errors" >&2
    bad "$path has inline <script> JS SYNTAX ERROR(s) — this WILL white-screen the page"
    return
  fi
  echo "    OK: $path (200, marker present, all inline JS parses)"
}

# ------------------------------------------------------------------
# 2a. PUBLIC pages (no auth): landings must render - a Blade ParseError
#     here takes the marketing page down for EVERY visitor and no login
#     is needed to see it. Markers are language-independent hrefs/text.
# ------------------------------------------------------------------
say "Public landing pages"
PUBLIC_PAGES=(
  "/pos|pos/login"
  "/fbr-pos|fbr-pos/login"
  "/|login"
)
for entry in "${PUBLIC_PAGES[@]}"; do
  path="${entry%%|*}"
  markers="${entry#*|}"
  pcode=$(curl -sL --max-time 30 -H "X-Forwarded-Proto: https" -o "$TMPD/pub.html" -w '%{http_code}' "$BASE_URL$path")
  if [ "$pcode" != "200" ]; then
    bad "PUBLIC $path returned HTTP $pcode (expected 200)"
  elif ! grep -qE "$markers" "$TMPD/pub.html"; then
    bad "PUBLIC $path missing expected marker (regex: $markers)"
  else
    echo "    OK: PUBLIC $path (200, marker present)"
  fi
done

say "PRA POS panel (/pos/*)"
do_login "/pos/login" "/pos/dashboard" "$LOGIN" "$PASSWORD"
for entry in "${PRA_PAGES[@]}"; do
  path="${entry%%|*}"
  markers="${entry#*|}"
  check_page "$path" "$markers"
done

say "FBR POS panel (/fbr-pos/*)"
do_login "/fbr-pos/login" "/fbr-pos/dashboard" "$FBR_LOGIN" "$FBR_PASSWORD"
for entry in "${FBR_PAGES[@]}"; do
  path="${entry%%|*}"
  markers="${entry#*|}"
  check_page "$path" "$markers"
done

echo ""
if [ $FAIL -ne 0 ]; then
  echo "WHITE-SCREEN CHECK: FAILED — fix the pages above BEFORE deploying." >&2
  exit 1
fi
echo "WHITE-SCREEN CHECK: PASS — all key POS pages render with valid inline JS."
exit 0
