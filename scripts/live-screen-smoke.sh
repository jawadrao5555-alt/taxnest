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
#   - X-Report card (Task 731, deterministic — never silently skipped): closed
#     day = report links count as pass; open day with 0 bills = a tiny
#     reporting-OFF final bill is seeded, the card asserted, then the seed is
#     permanently deleted (details at the day-close section below).
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

# ---------------------------------------------------- stale-cache sanitize
# Task 740: a marker miss can be a FALSE alarm when live's compiled Blade
# views / web OPcache are poisoned by racing cPanel auto-deploys (15 Aug 2026:
# /pos/dashboard 'today-khata' FAILED while the blade md5 matched workspace;
# view:clear+view:cache+opcache_reset made the marker appear instantly — see
# .agents/memory/cpanel-deployment.md). So: on the FIRST marker miss, sanitize
# live's caches ONCE over SSH and retry that page; only a second miss FAILs.
# No SSH key (owner machine) => old behavior (fail on first miss) unchanged.
SSH_KEY="${SMOKE_SSH_KEY:-/home/runner/workspace/.local/ssh/cpanel_deploy_key}"
SSH_HOST="taxnestc@cpanel.taxnest.com.pk"   # DNS-only host; taxnest.com.pk is Cloudflare-proxied (port 22 dead)
SANITIZE_DONE=0   # 0 = not attempted, 1 = succeeded, 2 = attempted & failed (don't retry again)

# sanitize_live_caches -> 0 if the sanitize ran & OPcache reset confirmed
sanitize_live_caches() {
  if [ "$SANITIZE_DONE" = 1 ]; then return 0; fi
  if [ "$SANITIZE_DONE" = 2 ]; then return 1; fi
  if [ ! -f "$SSH_KEY" ]; then SANITIZE_DONE=2; return 1; fi
  echo "    Marker miss — sanitizing live caches once (view:clear+view:cache + web OPcache reset) then retrying (Task 740, stale-compiled-view false alarm guard)..."
  local RPROBE="opr-smoke-$(date +%s%N)$RANDOM.php" OUT
  OUT=$(timeout 120 ssh -i "$SSH_KEY" -p 22 -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new \
    "$SSH_HOST" bash -s -- "$RPROBE" <<'SANITIZE' 2>&1
set -u
RPROBE=$1
LIVE_DIR=/home/taxnestc/public_html
PHP84=/usr/local/bin/ea-php84
trap 'rm -f "$LIVE_DIR/public/$RPROBE"' EXIT INT TERM
cd "$LIVE_DIR" || exit 90
$PHP84 artisan view:clear 2>&1 || exit 94
$PHP84 artisan view:cache 2>&1 || exit 94
echo '<?php opcache_reset(); echo "OPCACHE_RESET_OK"; ?>' > "public/$RPROBE" || exit 95
for TRY in 1 2 3; do
  OP_OUT=$(curl -s --max-time 15 "https://taxnest.com.pk/$RPROBE" || true)
  case "$OP_OUT" in *OPCACHE_RESET_OK*) echo "SANITIZE_OK"; exit 0 ;; esac
  sleep 3
done
exit 98
SANITIZE
  )
  if echo "$OUT" | grep -q "SANITIZE_OK"; then
    SANITIZE_DONE=1
    echo "    Live cache sanitize done (view rebuilt + web OPcache reset confirmed)."
    return 0
  fi
  SANITIZE_DONE=2
  warn "live cache sanitize over SSH did not complete (output: $(echo "$OUT" | tail -c 200)) — falling back to plain marker verdicts"
  return 1
}

# require <path> <feature-name> <regex>
# On a miss: sanitize live caches ONCE (if SSH key available), refetch the
# page, re-check. Fails only if the marker is still missing after the retry.
require() {
  local path="$1" name="$2" regex="$3"
  if grep -qE "$regex" "$TMPD/page.html"; then
    echo "    OK: $name marker present on $path"
    return 0
  fi
  if sanitize_live_caches && fetch "$path"; then
    if grep -qE "$regex" "$TMPD/page.html"; then
      echo "    OK: $name marker present on $path AFTER cache sanitize (was a stale-compiled-view false alarm — no regression)"
      return 0
    fi
  fi
  bad "$path MISSING marker for '$name' (regex: $regex) — feature not rendering (deploy gap or regression)"
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

say "/pos/day-close — auto-close toggle + X-Report card (deterministic, Task 731)"
if fetch "/pos/day-close"; then
  require "/pos/day-close" "auto day-close toggle" 'dc-auto-close-chk'
  # X-Report card renders only when the day is OPEN and has >0 bills in the
  # viewer's stream. Deterministic assertion (Task 731 — no silent skip):
  #   - card present            -> PASS
  #   - closed-day report links -> PASS (card correctly hidden, state proven)
  #   - day open, 0 bills       -> SEED one tiny reporting-OFF FINAL bill
  #     (pos/* is CSRF-exempt; QA 35 is PRA-reporting OFF, so the final gets
  #     an L-series number + invoice_mode='pra' + pra_status NULL — the shape
  #     the admin/'both' day-close stats actually COUNT; a save_as_provisional
  #     bill is invoice_mode='local' and is EXCLUDED from those stats, so it
  #     can never render the card). Re-assert the card, then hard-delete the
  #     seed via DELETE /pos/transaction/{id} so day-close/reports stay clean
  #     (manual delete = permanent; QA 35 admin is not a cashier).
  # Task 744: like `require`, a bespoke miss here can be a stale-compiled-view
  # false alarm — before concluding "day open with 0 bills" (and seeding), if
  # NEITHER marker is present, sanitize live caches once (Task 740 helper,
  # once-per-run) and refetch the page; only then take the seed/FAIL path.
  # No SSH key => sanitize_live_caches returns 1 and behavior is unchanged.
  DC_SANITIZED=""
  DC_PAGE_OK=1   # empty => post-sanitize refetch FAILED; verdict cannot be made
  if ! grep -qE 'day-close/x-report/' "$TMPD/page.html" \
     && ! grep -qE 'pos/day-close/[0-9]+/(pdf|thermal)' "$TMPD/page.html"; then
    if sanitize_live_caches; then
      if fetch "/pos/day-close"; then
        DC_SANITIZED=1
      else
        # fetch already recorded the failure (bad). Do NOT fall through to the
        # marker/seed path on a failed page — that would seed a probe bill and
        # emit a misleading regression verdict off an unusable response.
        DC_PAGE_OK=""
      fi
    fi
  fi
  if [ -z "$DC_PAGE_OK" ]; then
    warn "/pos/day-close refetch after cache sanitize failed — skipping X-Report card assertion (failure already recorded above)"
  elif grep -qE 'day-close/x-report/' "$TMPD/page.html"; then
    echo "    OK: X-Report card present on /pos/day-close${DC_SANITIZED:+ AFTER cache sanitize (was a stale-compiled-view false alarm — no regression)}"
  elif grep -qE 'pos/day-close/[0-9]+/(pdf|thermal)' "$TMPD/page.html"; then
    echo "    OK: /pos/day-close shows closed-day report links (X-Report card correctly hidden — day already closed)${DC_SANITIZED:+ — confirmed after cache sanitize}"
  else
    echo "    Day open with 0 bills — seeding a temporary reporting-OFF final bill to assert the X-Report card deterministically..."
    SEED_JSON=$("${CURL[@]}" -X POST \
      -H 'Content-Type: application/json' -H 'Accept: application/json' \
      --data '{"items":[{"name":"SMOKE X-REPORT PROBE","quantity":1,"unit_price":1,"_manual":true}],"payment_method":"cash","discount_type":"amount","discount_value":0,"cash_received":1}' \
      "$LIVE_URL/pos/invoice/store")
    SEED_ID=$(echo "$SEED_JSON" | grep -oE '"transaction_id"[[:space:]]*:[[:space:]]*[0-9]+' | grep -oE '[0-9]+$')
    if [ -z "$SEED_ID" ]; then
      bad "/pos/day-close: could not seed probe bill via /pos/invoice/store — X-Report check cannot be proven (response: $(echo "$SEED_JSON" | head -c 300))"
    else
      echo "    Seeded probe bill id=$SEED_ID"
      if fetch "/pos/day-close"; then
        if grep -qE 'day-close/x-report/' "$TMPD/page.html"; then
          echo "    OK: X-Report card present on /pos/day-close (with seeded bill)"
        else
          bad "/pos/day-close MISSING X-Report card even with a bill today — regression or deploy gap"
        fi
      fi
      # Cleanup ALWAYS (even after a failed assert): hard-delete the seed so
      # it never pollutes day-close/reports or the quota. Delete = permanent
      # by design (deleteTransaction refuses PRA-fiscal bills; ours has none).
      # deleteTransaction answers with a REDIRECT either way (success and
      # error both 302), so the redirect proves nothing. Deletion is proven
      # POSITIVELY by BOTH of:
      #   a) the transaction detail page answering exactly 302/404 (row gone;
      #      live's 404 handling itself REDIRECTS — verified: DB row deleted
      #      while GET gave 302). 200 = still exists; 000/5xx/anything else =
      #      transport/server trouble — deletion NOT established, fail loudly.
      #   b) /pos/day-close no longer showing the X-Report card (stats back to
      #      0 bills) — also proves the session is still alive, so (a)'s 302
      #      was not a login bounce.
      "${CURL[@]}" -o /dev/null -X POST --data-urlencode "_method=DELETE" \
        "$LIVE_URL/pos/transaction/$SEED_ID"
      GONE=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$LIVE_URL/pos/transaction/$SEED_ID")
      if [ "$GONE" != "302" ] && [ "$GONE" != "404" ]; then
        bad "Cleanup NOT PROVEN: probe bill id=$SEED_ID — /pos/transaction/$SEED_ID returned $GONE (200 = still exists; other = transport/server issue). Verify/delete manually from /pos/transactions on QA 35."
      elif fetch "/pos/day-close" && ! grep -qE 'day-close/x-report/' "$TMPD/page.html"; then
        echo "    Cleanup: probe bill $SEED_ID permanently deleted (detail page $GONE, X-Report card gone again)."
      else
        bad "Cleanup NOT PROVEN: probe bill id=$SEED_ID — /pos/day-close still shows the X-Report card (or did not load) after delete. Verify/delete manually from /pos/transactions on QA 35."
      fi
    fi
  fi
fi

say "/pos/transactions — PRA tab + transactions table render (Task 760: exempt tab now historical-only, hidden on QA 35 which has no exempt_internal bills)"
if fetch "/pos/transactions"; then
  require "/pos/transactions" "PRA tab link" 'tab=pra'
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
