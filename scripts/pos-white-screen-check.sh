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

# ------------------------------------------------------------------
# 1b2. STATIC: sale-screen i18n subset guard (Task 658). The sale screens bake
#      only the TXT.* keys they use — this fails if a referenced key is
#      missing in ANY of en/rur/ur, or if a dynamic TXT[expr] access appears
#      (the extractor can't see it → silently unbaked key → undefined label).
# ------------------------------------------------------------------
say "Static scan: sale-screen i18n baked-subset keys (en/rur/ur)"
if php scripts/pos-i18n-check.php; then
  echo "    OK: every TXT.* key the sale screens use exists in all three locales."
else
  bad "pos-i18n-check failed — a sale-screen TXT.* key is missing from a locale or accessed dynamically"
fi

# ------------------------------------------------------------------
# 1c. STATIC: hardcoded Roman Urdu in USER-VISIBLE text (Task 238).
#     English mode must never show Roman Urdu again — Task 237 cleaned the
#     live screens; this scan blocks any regression. Checks HTML text nodes,
#     placeholder/title/aria-label/alt attributes, and JS string literals in
#     inline <script>; ignores Blade/HTML/JS comments and @php blocks.
#     Legacy pre-conversion files are skipped via scripts/roman-urdu-legacy.txt
#     (shrink-only list — NEVER add new files; new code uses __() keys).
# ------------------------------------------------------------------
say "Static scan: hardcoded Roman Urdu in user-visible Blade text"
RU_OUT=$(python3 - <<'PYEOF'
import os, re, sys

ROOT = "resources/views"
LEGACY_FILE = "scripts/roman-urdu-legacy.txt"

# Strong Roman Urdu words only — each is essentially never valid English.
WORDS = r"""
hai hain hoga hogi honge hogaya hogayi
karein karain kariye kijiye krein karke
nahi nahin nahee
apna apni apne
kholein dekhein chunein likhein dabayen dabayein banayen banayein bhejein
dobara zaroori zaruri mehrbani meharbani shukriya
sakte sakta sakti chahiye chahiyay
gaya gayi gaye
raha rahi rahe
wala wali walay walon
sirf lekin magar taake taakay
paisay paise rupay
hojaye hojayen hojata hojati
milega milegi jayega jayegi
karna karni karne hona hone honi
"""
WORD_RE = re.compile(r"\b(" + "|".join(WORDS.split()) + r")\b", re.I)
# Two-word phrases that are strong even when the single words are too weak.
PHRASE_RE = re.compile(
    r"\b(ke liye|se pehle|ho gaya|ho gayi|kar diya|kar dein|ki gayi|kya aap)\b",
    re.I,
)

BLADE_COMMENT_RE = re.compile(r"\{\{--.*?--\}\}", re.S)
HTML_COMMENT_RE = re.compile(r"<!--.*?-->", re.S)
SCRIPT_RE = re.compile(r"<script\b([^>]*)>(.*?)</script>", re.S | re.I)
ATTR_RE = re.compile(
    r"""\b(?:placeholder|title|aria-label|alt|data-tip|data-tooltip)\s*=\s*("([^"]*)"|'([^']*)')""",
    re.I,
)
JS_STR_RE = re.compile(
    r"'((?:[^'\\\n]|\\.)*)'|\"((?:[^\"\\\n]|\\.)*)\"|`((?:[^`\\]|\\.)*)`", re.S
)


def blank_keep_lines(m):
    """Replace a match with same-length blanks, preserving newlines/line numbers."""
    return re.sub(r"[^\n]", " ", m.group(0))


def strip_js_comments(js):
    """State machine: blank // and /* */ comments, respecting string literals."""
    out = list(js)
    i, n = 0, len(js)
    while i < n:
        c = js[i]
        if c in "'\"`":
            q = c
            i += 1
            while i < n:
                if js[i] == "\\":
                    i += 2
                    continue
                if js[i] == q:
                    i += 1
                    break
                if q != "`" and js[i] == "\n":  # unterminated line string — bail
                    break
                i += 1
        elif c == "/" and i + 1 < n and js[i + 1] == "/":
            while i < n and js[i] != "\n":
                out[i] = " "
                i += 1
        elif c == "/" and i + 1 < n and js[i + 1] == "*":
            while i < n:
                if js[i] == "*" and i + 1 < n and js[i + 1] == "/":
                    out[i] = out[i + 1] = " "
                    i += 2
                    break
                if js[i] != "\n":
                    out[i] = " "
                i += 1
        else:
            i += 1
    return "".join(out)


def check_segment(text, base_line, path, kind, hits):
    for m in list(WORD_RE.finditer(text)) + list(PHRASE_RE.finditer(text)):
        line = base_line + text[: m.start()].count("\n")
        ctx = text[max(0, m.start() - 40): m.start() + 50].replace("\n", " ").strip()
        hits.append((path, line, kind, m.group(0), ctx))


def scan_file(path, hits):
    try:
        src = open(path, encoding="utf-8", errors="replace").read()
    except OSError:
        return
    src = BLADE_COMMENT_RE.sub(blank_keep_lines, src)
    src = HTML_COMMENT_RE.sub(blank_keep_lines, src)
    # @php ... @endphp blocks are server-side code, never user-visible.
    src = re.sub(r"@php\b.*?@endphp", blank_keep_lines, src, flags=re.S)
    # Blank {{ ... }} / {!! ... !!} echoes — translation keys etc. are fine.
    src = re.sub(r"\{\{.*?\}\}|\{!!.*?!!\}", blank_keep_lines, src, flags=re.S)

    # Pull out inline script bodies; blank them from the HTML copy.
    scripts = []
    for m in SCRIPT_RE.finditer(src):
        attrs, body = m.group(1), m.group(2)
        line = src[: m.start(2)].count("\n") + 1
        t = re.search(r'type\s*=\s*["\']([^"\']+)', attrs)
        if "src=" not in attrs and (not t or "javascript" in t.group(1) or t.group(1) == "module"):
            scripts.append((line, body))
    html = SCRIPT_RE.sub(blank_keep_lines, src)
    html = re.sub(r"<style\b[^>]*>.*?</style>", blank_keep_lines, html, flags=re.S | re.I)

    # 1. user-visible attribute values (before tags are blanked)
    for m in ATTR_RE.finditer(html):
        val = m.group(2) if m.group(2) is not None else m.group(3)
        line = html[: m.start()].count("\n") + 1
        check_segment(val, line, path, "attr", hits)

    # 2. HTML text nodes: blank all tags, keep text
    text_only = re.sub(r"<[^>]*>", blank_keep_lines, html)
    check_segment(text_only, 1, path, "text", hits)

    # 3. JS string literals inside inline scripts (comments stripped)
    for base_line, body in scripts:
        body = strip_js_comments(body)
        for m in JS_STR_RE.finditer(body):
            s = next(g for g in m.groups() if g is not None)
            line = base_line + body[: m.start()].count("\n")
            check_segment(s, line, path, "js-string", hits)


def main():
    legacy = set()
    if os.path.exists(LEGACY_FILE):
        for ln in open(LEGACY_FILE, encoding="utf-8"):
            ln = ln.strip()
            if ln and not ln.startswith("#"):
                legacy.add(ln)

    stale = sorted(p for p in legacy if not os.path.exists(p))
    hits = []
    for dirpath, _dirs, files in os.walk(ROOT):
        for fn in sorted(files):
            if not fn.endswith(".blade.php"):
                continue
            path = os.path.join(dirpath, fn)
            if path in legacy:
                continue
            scan_file(path, hits)

    for p in stale:
        print(f"STALE legacy entry (file gone — remove line from {LEGACY_FILE}): {p}")

    if hits:
        seen = set()
        for path, line, kind, word, ctx in hits:
            key = (path, line, word.lower())
            if key in seen:
                continue
            seen.add(key)
            print(f"{path}:{line}: [{kind}] '{word}' in: {ctx}")
        sys.exit(1)
    if stale:
        sys.exit(1)


main()
PYEOF
)
RU_RC=$?
if [ $RU_RC -ne 0 ] && [ -z "$RU_OUT" ]; then
  bad "Roman Urdu scan itself failed to run (python3 exit $RU_RC) — fix the environment, do not deploy blind"
elif [ -n "$RU_OUT" ]; then
  echo "$RU_OUT" >&2
  bad "hardcoded Roman Urdu in user-visible text — move the string into lang/en+lang/ur pos.php keys and render via __()/@js() (English mode must stay pure English)"
else
  echo "    OK: no hardcoded Roman Urdu in enforced Blade views."
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
  # Aug 2026 strict plan binding: analytics-pdf/export links are plan-gated and
  # the charts need bills in range — marker = the always-rendered date filter.
  "/fbr-pos/reports|name=\"from\"|raDailyTrend"
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
  "/fbr-pos-landing|fbr-pos/login"
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
