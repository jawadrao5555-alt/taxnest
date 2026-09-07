#!/bin/bash
# Focused tests for NestPOS login redirect diagnostics in CI live verify.
# Usage: bash scripts/tests/ci-live-verify-login-redirect-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
EVAL="$ROOT/scripts/lib/ci-live-verify-login-redirect.py"
VERIFY="$ROOT/scripts/ci-live-verify.sh"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

[ -f "$EVAL" ] || bad "missing $EVAL"
[ -f "$VERIFY" ] || bad "missing $VERIFY"

classify() {
  python3 "$EVAL" "$1" "${2:-https://taxnest.pk}"
  return $?
}

# Back to login = auth failure
OUT=$(classify "/pos/login" || true)
echo "$OUT" | grep -q '"reason": "back_to_login"' \
  && echo "$OUT" | grep -q 'redirected back to /pos/login' \
  && ok "relative /pos/login classified as login failure" \
  || bad "relative /pos/login: $OUT"

OUT=$(classify "https://taxnest.pk/pos/login" || true)
echo "$OUT" | grep -q back_to_login \
  && ok "absolute /pos/login classified as login failure" \
  || bad "absolute /pos/login: $OUT"

OUT=$(classify "https://taxnest.pk/pos/login?error=1" || true)
echo "$OUT" | grep -q back_to_login \
  && ok "/pos/login with query still login failure" \
  || bad "/pos/login?query: $OUT"

# Valid NestPOS authenticated routes
for loc in \
  "/pos/invoice/create" \
  "/pos/dashboard" \
  "https://taxnest.pk/pos/invoice/create" \
  "/pos/archive" \
  "/pos/waiter"
do
  if OUT=$(classify "$loc"); then
    echo "$OUT" | grep -q pos_authenticated_route \
      && ok "OK route: $loc" \
      || bad "wrong reason for $loc ($OUT)"
  else
    bad "should OK: $loc ($OUT)"
  fi
done

# Wrong portals
OUT=$(classify "/admin/dashboard" || true)
echo "$OUT" | grep -q wrong_portal_admin \
  && ok "admin portal rejected" \
  || bad "admin: $OUT"

OUT=$(classify "/fbr-pos/dashboard" || true)
echo "$OUT" | grep -q wrong_portal_fbr \
  && ok "fbr portal rejected" \
  || bad "fbr: $OUT"

OUT=$(classify "" || true)
echo "$OUT" | grep -q missing_location \
  && ok "missing Location rejected" \
  || bad "empty location: $OUT"

OUT=$(classify "https://evil.example/pos/dashboard" || true)
echo "$OUT" | grep -q foreign_host \
  && ok "foreign host rejected" \
  || bad "foreign: $OUT"

# 302 alone is never treated as success by the classifier (needs Location path)
if classify "" >/dev/null 2>&1; then
  bad "empty Location must not be OK"
else
  ok "empty Location is not treated as authenticated"
fi

# Wiring in ci-live-verify.sh
grep -q 'ci-live-verify-login-redirect.py' "$VERIFY" \
  && ok "ci-live-verify uses login redirect classifier" \
  || bad "ci-live-verify missing classifier call"

grep -q 'NestPOS login failed: login POST redirected back to /pos/login' "$EVAL" \
  && ok "exact back-to-login message present" \
  || bad "missing exact back-to-login message"

grep -q 'authentication/session failure, not a NestPOS marker miss' "$VERIFY" \
  && ok "dashboard 302 message distinguishes auth/session failure" \
  || bad "dashboard failure message not hardened"

grep -q -- '-D "$LOGIN_HDR"' "$VERIFY" \
  && ok "login POST captures response headers" \
  || bad "login POST must capture headers via -D"

# Must not dump Set-Cookie / password into logs from this path
if grep -E 'cat \$LOGIN_HDR|cat "\$LOGIN_HDR"|Set-Cookie|echo "\$PASS"|echo \$PASS' "$VERIFY" | grep -vq '^#'; then
  # allow comments only
  if grep -vE '^\s*#' "$VERIFY" | grep -qE 'cat \$LOGIN_HDR|cat "\$LOGIN_HDR"|echo "\$PASS"|echo \$PASS'; then
    bad "must not dump login headers or password"
  else
    ok "does not dump login headers or password"
  fi
else
  ok "does not dump login headers or password"
fi

# Dashboard still must be HTTP 200
grep -q 'post-login /pos/dashboard returned' "$VERIFY" \
  && ok "dashboard HTTP 200 requirement retained" \
  || bad "dashboard 200 check missing"

# Still refuses treating login 302 alone as enough without Location classify
python3 - "$VERIFY" <<'PY' && ok "login Location classified before dashboard GET" || bad "login classify order wrong"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
a = text.find("ci-live-verify-login-redirect.py")
b = text.find('"${LIVE_URL}/pos/dashboard"')
# There may be multiple dashboard fetches; first after classify must be the auth check
if a < 0 or b < 0 or a > b:
    sys.exit(1)
sys.exit(0)
PY

bash -n "$VERIFY" && ok "ci-live-verify.sh bash -n clean" || bad "bash -n failed"
python3 -m py_compile "$EVAL" && ok "login-redirect classifier compiles" || bad "compile failed"

# SHA / marker /up checks still present (not weakened)
for needle in EXPECTED_SHA '/up' 'data-tn-sale-root' 'id="today-khata"' 'LIVE_QA_PASS'; do
  grep -q "$needle" "$VERIFY" && ok "still has $needle" || bad "missing $needle"
done

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL LOGIN-REDIRECT DIAGNOSTIC CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
