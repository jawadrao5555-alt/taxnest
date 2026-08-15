#!/bin/bash
# Post-deploy LIVE screen smoke test (Task 714).
#
# Logs into the standing live QA company (id 35, "QA Full Audit Restaurant" —
# see .agents/memory/live-pos-test-company.md) and asserts that each headline
# feature's page still renders its language-independent marker. This turns the
# manual "curl + grep markers" verification (Task 709) into one command, and
# settles "feature nahi dikh raha" fast: marker present = deployed; marker
# missing = deploy gap or regression.
#
# State-gates respected (QA 35 is PRA-reporting OFF, Pro plan):
#   - exempt stream: only the UI tab LINK is asserted (tab=exempt) — the
#     stream itself needs PRA reporting ON (.agents/memory/pos-billing-scope.md).
#   - X-Report card: only rendered when the day is OPEN and has >0 bills; if
#     absent we WARN (state-gated), never fail — closed-day links count as pass.
#
# Usage:
#   bash scripts/live-screen-smoke.sh
#   LIVE_URL=https://taxnest.com.pk SMOKE_LOGIN=... SMOKE_PASS=... bash scripts/live-screen-smoke.sh
#
# Credentials: qa.fullaudit@taxnest.com.pk + LIVE_QA_PASS from the untracked
# .local/qa-creds.env (repo is PUBLIC — never hardcode passwords here).
# POS login gotcha: the POST field is `login`, NOT `email`.
#
# Exit codes: 0 = all markers present, 1 = MARKER MISSING (loud, page named),
#             2 = could not run (network/login failure) — verify manually.
set -uo pipefail
cd "$(dirname "$0")/.."

LIVE_URL="${LIVE_URL:-https://taxnest.com.pk}"
if [ -f .local/qa-creds.env ]; then . .local/qa-creds.env; fi
LOGIN="${SMOKE_LOGIN:-qa.fullaudit@taxnest.com.pk}"
PASS="${SMOKE_PASS:-${LIVE_QA_PASS:-}}"
# On the live server itself (cPanel auto-deploy path) the workspace-only
# .local/qa-creds.env doesn't exist — the QA password lives in the untracked,
# chmod-600 ~/.qa_pass file (same secret, rotated together).
# File format tolerated: either the raw password, or env style LIVE_QA_PASS=...
if [ -z "$PASS" ] && [ -f "$HOME/.qa_pass" ]; then
  PASS=$(grep -m1 . "$HOME/.qa_pass" | sed 's/^[A-Za-z_]*=//' | tr -d ' "\047\r\n')
fi
if [ -z "$PASS" ]; then
  echo "live-screen-smoke: ERROR — no password (set SMOKE_PASS or LIVE_QA_PASS in .local/qa-creds.env)" >&2
  exit 2
fi

FAIL=0
say()  { echo "==> $*"; }
bad()  { echo "    FAIL: $*" >&2; FAIL=1; }
warn() { echo "    WARN: $*" >&2; }

TMPD=$(mktemp -d /tmp/live-smoke.XXXXXX)
trap 'rm -rf "$TMPD"' EXIT
JAR="$TMPD/jar"
CURL=(curl -s --max-time 40 -b "$JAR" -c "$JAR")

# ------------------------------------------------------------------ login
say "Login to live QA company as $LOGIN ($LIVE_URL/pos/login)"
PAGE=$("${CURL[@]}" "$LIVE_URL/pos/login") || { echo "    Cannot reach $LIVE_URL — network/Cloudflare issue." >&2; exit 2; }
TOKEN=$(echo "$PAGE" | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"$//')
[ -n "$TOKEN" ] || { echo "    Could not extract CSRF token from /pos/login." >&2; exit 2; }
CODE=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode "_token=$TOKEN" \
  --data-urlencode "login=$LOGIN" \
  --data-urlencode "password=$PASS" \
  "$LIVE_URL/pos/login")
[ "$CODE" = "302" ] || { echo "    Login POST returned $CODE (expected 302) — password rotated? (.local/qa-creds.env LIVE_QA_PASS)" >&2; exit 2; }
CODE=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$LIVE_URL/pos/dashboard")
[ "$CODE" = "200" ] || { echo "    Post-login /pos/dashboard returned $CODE — login likely failed." >&2; exit 2; }
echo "    Logged in."

# fetch <path>  -> $TMPD/page.html ; returns 1 on non-200/login-bounce
fetch() {
  local path="$1" code
  code=$("${CURL[@]}" -o "$TMPD/page.html" -w '%{http_code}' "$LIVE_URL$path")
  if [ "$code" != "200" ]; then bad "$path returned HTTP $code (expected 200)"; return 1; fi
  # Login-bounce detection: a form POSTing to /pos/login. (Do NOT key on
  # name="password" alone — /pos/team's add-cashier form has password inputs.)
  if grep -qE 'action="[^"]*pos/login"' "$TMPD/page.html"; then bad "$path served the LOGIN page (session lost?)"; return 1; fi
  return 0
}

# require <path> <feature-name> <regex>
require() {
  local path="$1" name="$2" regex="$3"
  if grep -qE "$regex" "$TMPD/page.html"; then
    echo "    OK: $name marker present on $path"
  else
    bad "$path MISSING marker for '$name' (regex: $regex) — feature not rendering (deploy gap or regression)"
  fi
}

# ------------------------------------------------------------------ pages
say "/pos/dashboard — Aaj ka Khaata stream cards + What's New wiring"
if fetch "/pos/dashboard"; then
  require "/pos/dashboard" "Aaj ka Khaata stream cards" 'id="today-khata"'
  require "/pos/dashboard" "What's New announcements" 'whats-new/seen'
fi

say "/pos/invoice/create — print-confirm setting baked into sale screen"
if fetch "/pos/invoice/create"; then
  require "/pos/invoice/create" "printConfirmAsk state" 'printConfirmAsk'
fi

say "/pos/day-close — auto-close toggle + X-Report card (state-gated)"
if fetch "/pos/day-close"; then
  require "/pos/day-close" "auto day-close toggle" 'dc-auto-close-chk'
  # X-Report card renders only when day OPEN and >0 bills; closed-day report
  # links are equally proof the day-close feature set rendered. Neither =
  # state (0 bills, day open) — WARN only, never fail on state.
  if grep -qE 'day-close/x-report/' "$TMPD/page.html"; then
    echo "    OK: X-Report card present on /pos/day-close"
  elif grep -qE 'pos/day-close/(pdf|thermal)/' "$TMPD/page.html"; then
    echo "    OK: /pos/day-close shows closed-day report links (X-Report card correctly hidden — day already closed)"
  else
    warn "/pos/day-close: X-Report card not visible — likely state-gated (0 bills today, day open). Not failing; verify manually if a bill exists today."
  fi
fi

say "/pos/transactions — exempt stream tab LINK (QA 35 is PRA-OFF: link only)"
if fetch "/pos/transactions"; then
  require "/pos/transactions" "exempt tab link" 'tab=exempt'
fi

say "/pos/team — username login field"
if fetch "/pos/team"; then
  require "/pos/team" "username input" 'name="username"'
fi

echo ""
if [ $FAIL -ne 0 ]; then
  echo "LIVE SCREEN SMOKE: FAILED — a feature marker is missing on live (pages named above)." >&2
  echo "Decide: deploy gap (check 'git log -1' on live) vs real regression." >&2
  exit 1
fi
echo "LIVE SCREEN SMOKE: PASS — all feature markers present on live QA company."
exit 0
