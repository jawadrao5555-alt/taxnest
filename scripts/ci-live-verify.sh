#!/bin/bash
# Post-deploy LIVE verification for GitHub Actions (Environment "production").
#
# Runs ONLY after scripts/ci-deploy-production.sh has applied EXPECTED_SHA.
# Cloud Agents must NEVER run this with production secrets — they observe
# the Actions conclusion via scripts/cloud-issue-to-live-observe.sh (secret-free).
#
# Verifies (beyond HTTP 200):
#   1. Live git HEAD == EXPECTED_SHA (SSH with taxnest-production-deploy)
#   2. Public /up health endpoint is 200
#   3. NestPOS QA login + feature markers on the standing live QA company
#      (same company as scripts/live-screen-smoke.sh — fictional QA, not a customer)
#
# Safety:
#   - Never prints passwords or .env
#   - Refuses Replit key path
#   - Default profile skips destructive bill-seed probes (no X-Report seeding)
#   - Does not modify customer data; QA company only
#   - Does not use skip_elaan
#
# Required env (Actions Environment "production"):
#   EXPECTED_SHA                 — github.sha just deployed
#   PRODUCTION_SSH_PRIVATE_KEY   — or LIVE_SSH_KEY file path
#   LIVE_QA_PASS                 — password for LIVE_QA_LOGIN
#
# Optional:
#   LIVE_URL           default https://taxnest.pk
#   LIVE_QA_LOGIN      default qa.fullaudit@taxnest.com.pk
#   LIVE_VERIFY_PROFILE  core|extended  (default extended)
#
# Exit: 0 PASS, 1 FAIL (regression/gap), 2 could not run (config/SSH/login)
#
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

fail() { echo "CI LIVE VERIFY FAILED: $*" >&2; exit 1; }
cannot() { echo "CI LIVE VERIFY: could not run — $*" >&2; exit 2; }
ok() { echo "    OK: $*"; }
bad() { echo "    FAIL: $*" >&2; FAIL=1; }
say() { echo ""; echo "==> $*"; }

EXPECTED_SHA="${EXPECTED_SHA:-${GITHUB_SHA:-}}"
LIVE_URL="${LIVE_URL:-https://taxnest.pk}"
LIVE_URL="${LIVE_URL%/}"
LOGIN="${LIVE_QA_LOGIN:-qa.fullaudit@taxnest.com.pk}"
PASS="${LIVE_QA_PASS:-${SMOKE_PASS:-}}"
PROFILE="${LIVE_VERIFY_PROFILE:-extended}"
FAIL=0

case "$EXPECTED_SHA" in
  ""|*[!0-9a-fA-F]*) cannot "EXPECTED_SHA missing or not hex" ;;
esac
if [ "${#EXPECTED_SHA}" -lt 7 ]; then
  cannot "EXPECTED_SHA too short"
fi
# Expand to full SHA when possible
if [ "${#EXPECTED_SHA}" -ne 40 ]; then
  FULL=$(git rev-parse --verify "${EXPECTED_SHA}^{commit}" 2>/dev/null || true)
  [ -n "$FULL" ] && EXPECTED_SHA="$FULL"
fi

[ -n "$PASS" ] || cannot "LIVE_QA_PASS is empty — add Environment secret LIVE_QA_PASS on 'production'"

# --------------------------------------------------------------------------- SSH key (same rules as ci-deploy-production.sh)
KEY_TMPDIR=""
cleanup_key() {
  if [ -n "$KEY_TMPDIR" ] && [ -d "$KEY_TMPDIR" ]; then
    rm -rf "$KEY_TMPDIR"
  fi
}
trap cleanup_key EXIT

if [ -n "${PRODUCTION_SSH_PRIVATE_KEY:-}" ]; then
  KEY_TMPDIR=$(mktemp -d)
  chmod 700 "$KEY_TMPDIR"
  umask 077
  printf '%s\n' "$PRODUCTION_SSH_PRIVATE_KEY" > "$KEY_TMPDIR/taxnest-production-deploy"
  chmod 600 "$KEY_TMPDIR/taxnest-production-deploy"
  export LIVE_SSH_KEY="$KEY_TMPDIR/taxnest-production-deploy"
elif [ -n "${LIVE_SSH_KEY:-}" ] && [ -f "${LIVE_SSH_KEY}" ]; then
  case "$LIVE_SSH_KEY" in
    */.local/ssh/nayatel_vps_key|*/nayatel_vps_key)
      cannot "refusing Replit key path ($LIVE_SSH_KEY)"
      ;;
  esac
else
  cannot "PRODUCTION_SSH_PRIVATE_KEY / LIVE_SSH_KEY not available for SHA check"
fi

export LIVE_KNOWN_HOSTS="$ROOT/scripts/lib/live-known-hosts"
# shellcheck source=scripts/lib/live-host.sh
source "$ROOT/scripts/lib/live-host.sh"
live_host_assert_not_retired || cannot "retired live host refused"
require_live_key || cannot "live SSH key missing"

SSH_OPTS=("${LIVE_SSH_OPTS[@]}")
HOST="$LIVE_SSH_HOST"
DIR="$LIVE_DIR"

say "Expected deployed SHA ${EXPECTED_SHA}"
say "Live URL ${LIVE_URL} (profile=${PROFILE})"

# --------------------------------------------------------------------------- 1) live HEAD
say "Verify live git HEAD matches EXPECTED_SHA"
LIVE_HEAD=$(timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" "cd '$DIR' && git rev-parse HEAD" 2>/dev/null) \
  || cannot "cannot read live git HEAD over SSH"
echo "    live HEAD: $LIVE_HEAD"
if [ "$LIVE_HEAD" != "$EXPECTED_SHA" ]; then
  fail "live HEAD ($LIVE_HEAD) != EXPECTED_SHA ($EXPECTED_SHA) — deploy gap or wrong commit"
fi
ok "live HEAD matches EXPECTED_SHA"

# --------------------------------------------------------------------------- 2) /up
say "Public health /up"
UP_CODE=$(curl -sS -o /tmp/ci-live-up.body -w '%{http_code}' --max-time 30 "${LIVE_URL}/up" || echo "000")
if [ "$UP_CODE" != "200" ]; then
  fail "/up returned HTTP $UP_CODE (expected 200)"
fi
ok "/up HTTP 200"

# --------------------------------------------------------------------------- 3) NestPOS QA smoke (markers, not merely 200)
TMPD=$(mktemp -d /tmp/ci-live-verify.XXXXXX)
trap 'cleanup_key; rm -rf "$TMPD"' EXIT
JAR="$TMPD/jar"
CURL=(curl -sS --max-time 40 -b "$JAR" -c "$JAR")

say "NestPOS login as live QA identity (non-customer standing QA company)"
PAGE=$("${CURL[@]}" "${LIVE_URL}/pos/login") || cannot "cannot reach ${LIVE_URL}/pos/login"
TOKEN=$(echo "$PAGE" | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"$//')
[ -n "$TOKEN" ] || cannot "CSRF token missing on /pos/login"
CODE=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode "_token=$TOKEN" \
  --data-urlencode "login=$LOGIN" \
  --data-urlencode "password=$PASS" \
  "${LIVE_URL}/pos/login")
[ "$CODE" = "302" ] || cannot "login POST returned $CODE (expected 302) — check LIVE_QA_PASS"
CODE=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${LIVE_URL}/pos/dashboard")
[ "$CODE" = "200" ] || cannot "post-login /pos/dashboard returned $CODE"
ok "authenticated NestPOS session"

fetch() {
  local path="$1" code
  code=$("${CURL[@]}" -o "$TMPD/page.html" -w '%{http_code}' "${LIVE_URL}${path}")
  if [ "$code" != "200" ]; then bad "$path HTTP $code"; return 1; fi
  if grep -qE 'action="[^"]*pos/login"' "$TMPD/page.html"; then
    bad "$path served LOGIN page (session lost?)"; return 1
  fi
  return 0
}

require() {
  local path="$1" name="$2" regex="$3"
  if grep -qE "$regex" "$TMPD/page.html"; then
    ok "$name on $path"
    return 0
  fi
  bad "$path MISSING marker for '$name' (/$regex/)"
  return 1
}

say "/pos/dashboard markers"
if fetch "/pos/dashboard"; then
  require "/pos/dashboard" "Aaj ka Khaata" 'id="today-khata"'
  require "/pos/dashboard" "What's New wiring" 'whats-new/seen'
fi

say "/pos/invoice/create (NestPOS sale) markers"
if fetch "/pos/invoice/create"; then
  require "/pos/invoice/create" "sale document root" 'data-tn-sale-document="pra".*data-tn-sale-root'
  require "/pos/invoice/create" "sale boot watchdog" 'window\.tnSaleBoot'
fi

if [ "$PROFILE" = "extended" ] || [ "$PROFILE" = "nestpos-extended" ]; then
  say "/pos/day-close markers (safe — no bill seeding)"
  if fetch "/pos/day-close"; then
    require "/pos/day-close" "auto day-close toggle" 'dc-auto-close-chk'
    # X-Report card is state-dependent; presence of either card OR closed-day
    # report links OR open-day empty is acceptable — we only FAIL if the page
    # itself is broken (already covered by fetch). Soft note only:
    if grep -qE 'day-close/x-report/|pos/day-close/[0-9]+/(pdf|thermal)|dc-auto-close-chk' "$TMPD/page.html"; then
      ok "day-close page has expected controls/state markers"
    else
      bad "/pos/day-close missing expected controls"
    fi
  fi

  say "/pos/transactions markers"
  if fetch "/pos/transactions"; then
    require "/pos/transactions" "PRA tab" 'tab=pra'
  fi

  say "/pos/team markers"
  if fetch "/pos/team"; then
    require "/pos/team" "username field" 'name="username"'
  fi
fi

# Optional issue-specific markers from the deployed commit (non-secret).
# Format: path|feature-name|regex   (lines starting with # ignored)
SPEC=""
if [ -f "$ROOT/deploy/live-verify.markers" ]; then
  SPEC="$ROOT/deploy/live-verify.markers"
fi
if [ -n "$SPEC" ]; then
  say "Commit-specific markers from deploy/live-verify.markers"
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      ''|\#*) continue ;;
    esac
    path=$(printf '%s' "$line" | cut -d'|' -f1)
    name=$(printf '%s' "$line" | cut -d'|' -f2)
    regex=$(printf '%s' "$line" | cut -d'|' -f3-)
    [ -n "$path" ] && [ -n "$name" ] && [ -n "$regex" ] || continue
    if fetch "$path"; then
      require "$path" "$name" "$regex"
    fi
  done < "$SPEC"
fi

# Summary for Actions UI
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "## CI live verify"
    echo "- EXPECTED_SHA: \`$EXPECTED_SHA\`"
    echo "- live HEAD: \`$LIVE_HEAD\`"
    echo "- LIVE_URL: $LIVE_URL"
    echo "- profile: $PROFILE"
    if [ "$FAIL" -eq 0 ]; then
      echo "- result: **PASS**"
    else
      echo "- result: **FAIL** ($FAIL assertion(s))"
    fi
  } >> "$GITHUB_STEP_SUMMARY"
fi

echo ""
if [ "$FAIL" -ne 0 ]; then
  echo "CI LIVE VERIFY: FAILED ($FAIL assertion(s)) — treat as NEW diagnosis cycle for the original issue." >&2
  echo "Cloud Agent: do NOT claim LIVE VERIFIED. Collect Actions logs, fix locally, new cursor/* PR." >&2
  exit 1
fi
echo "CI LIVE VERIFY: PASS — SHA matched and NestPOS markers present (profile=${PROFILE})."
exit 0
