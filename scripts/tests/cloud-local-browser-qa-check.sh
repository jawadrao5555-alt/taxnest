#!/bin/bash
# Static validation for Cloud Agent local browser / UI QA infrastructure.
# Does NOT require Chrome or a running server for most checks.
# Usage: bash scripts/tests/cloud-local-browser-qa-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

LIB="$ROOT/scripts/lib/local-browser.mjs"
SMOKE="$ROOT/scripts/cloud-local-ui-smoke.mjs"
SEED="$ROOT/scripts/cloud-local-qa-seed.sh"
DOC="$ROOT/docs/ops/cloud-agent-local-browser-qa.md"
GUARD="$ROOT/app/Support/DevStagingGuard.php"
HAND="$ROOT/CLOUD_AGENT_HANDOFF.md"
DEVDOC="$ROOT/docs/ops/cloud-agent-development.md"
LIVE="$ROOT/scripts/live-screen-smoke.sh"
UNIT="$ROOT/tests/Unit/DevStagingGuardTest.php"

for f in "$LIB" "$SMOKE" "$SEED" "$DOC" "$GUARD" "$UNIT"; do
  [ -f "$f" ] && ok "present $(basename "$f")" || bad "missing $f"
done

# Fail-closed helpers must refuse production hosts
if [ -f "$LIB" ]; then
  grep -q 'assertLocalOnlyBaseUrl' "$LIB" && ok "local-browser exports assertLocalOnlyBaseUrl" || bad "missing assertLocalOnlyBaseUrl"
  grep -q 'taxnest\.pk' "$LIB" && ok "local-browser blocks taxnest.pk" || bad "local-browser missing production host block"
  grep -q 'qa.fullaudit@taxnest.com.pk' "$LIB" && ok "local-browser blocks live QA login" || bad "local-browser missing live QA login block"
  grep -qiE 'BEGIN (OPENSSH|RSA) PRIVATE KEY|LIVE_QA_PASS\s*=\s*[^$]' "$LIB" && bad "local-browser contains secret-like material" || ok "local-browser has no embedded secrets"
fi

if [ -f "$SMOKE" ]; then
  grep -q "from './lib/local-browser.mjs'" "$SMOKE" && ok "ui-smoke imports shared lib" || bad "ui-smoke does not import shared lib"
  grep -q 'assertLocalOnlyBaseUrl' "$SMOKE" && ok "ui-smoke uses assertLocalOnlyBaseUrl" || bad "ui-smoke missing URL guard"
  grep -q 'data-tn-sale-root' "$SMOKE" && ok "ui-smoke asserts sale root" || bad "ui-smoke missing sale assertion"
  # Must not default BASE_URL to production
  if grep -E "BASE_URL.*taxnest\.pk|https://taxnest\.pk" "$SMOKE" | grep -q .; then
    bad "ui-smoke references production URL"
  else
    ok "ui-smoke has no production BASE_URL default"
  fi
fi

if [ -f "$SEED" ]; then
  bash -n "$SEED" && ok "bash -n cloud-local-qa-seed.sh" || bad "bash -n cloud-local-qa-seed.sh"
  if grep -qE 'taxnest_dev\|taxnest_staging|taxnest_dev\|taxnest_staging' "$SEED" || grep -q 'taxnest_dev' "$SEED"; then
    ok "seed restricts DB name"
  else
    bad "seed missing DB name restriction"
  fi
  grep -q 'VideoDemoShopSeeder' "$SEED" && ok "seed uses VideoDemoShopSeeder" || bad "seed missing VideoDemoShopSeeder"
  grep -qiE '115\.186\.164\.126|PRODUCTION_SSH|qa\.fullaudit@taxnest' "$SEED" && bad "seed references production/live QA" || ok "seed has no production wiring"
  grep -q '\.local/qa-creds.env' "$SEED" && ok "seed writes gitignored creds file" || bad "seed missing .local creds path"
fi

if [ -f "$GUARD" ]; then
  grep -q 'taxnest_dev' "$GUARD" && ok "DevStagingGuard allows taxnest_dev" || bad "DevStagingGuard missing taxnest_dev"
  grep -q 'taxnest_staging' "$GUARD" && ok "DevStagingGuard keeps taxnest_staging" || bad "DevStagingGuard dropped taxnest_staging"
  grep -q "127.0.0.1" "$GUARD" && ok "DevStagingGuard requires local host" || bad "DevStagingGuard host check missing"
fi

if [ -f "$DOC" ]; then
  grep -qi 'fail-closed\|loopback\|127.0.0.1' "$DOC" && ok "browser QA doc mentions fail-closed/local" || bad "browser QA doc missing safety section"
  grep -q 'cloud-local-ui-smoke.mjs' "$DOC" && ok "browser QA doc references smoke script" || bad "browser QA doc missing smoke script"
  grep -qi 'never.*production\|must not.*production\|refuse.*production' "$DOC" && ok "browser QA doc forbids production" || bad "browser QA doc missing production forbid"
fi

if [ -f "$DEVDOC" ]; then
  grep -q 'cloud-agent-local-browser-qa.md' "$DEVDOC" && ok "development.md links browser QA doc" || bad "development.md missing browser QA link"
fi

if [ -f "$HAND" ]; then
  grep -q 'cloud-local-ui-smoke\|local-browser-qa\|Local browser' "$HAND" && ok "handoff mentions local browser QA" || bad "handoff missing local browser QA"
fi

# live-screen-smoke must remain explicitly LIVE (not reused as Cloud default)
if [ -f "$LIVE" ]; then
  grep -q 'https://taxnest.pk' "$LIVE" && ok "live-screen-smoke stays production-oriented" || bad "live-screen-smoke unexpectedly changed"
fi

# Node syntax check of library + smoke (no browser launch)
if command -v node >/dev/null 2>&1; then
  node --check "$LIB" && ok "node --check local-browser.mjs" || bad "node --check local-browser.mjs"
  node --check "$SMOKE" && ok "node --check cloud-local-ui-smoke.mjs" || bad "node --check cloud-local-ui-smoke.mjs"

  # Unit-test the fail-closed URL helper without Chrome
  node --input-type=module <<'EOF' && ok "assertLocalOnlyBaseUrl allow/refuse matrix" || bad "assertLocalOnlyBaseUrl allow/refuse matrix"
import { assertLocalOnlyBaseUrl } from './scripts/lib/local-browser.mjs';
let fails = 0;
const mustPass = ['http://127.0.0.1:8000', 'http://localhost:8000/', 'http://[::1]:8000'];
const mustFail = ['https://taxnest.pk', 'http://taxnest.pk/pos', 'https://www.taxnest.pk', 'http://115.186.164.126', 'http://example.com', 'https://staging.example.com'];
for (const u of mustPass) {
  try { assertLocalOnlyBaseUrl(u); } catch (e) { console.error('expected allow:', u, e.message); fails++; }
}
for (const u of mustFail) {
  try { assertLocalOnlyBaseUrl(u); console.error('expected refuse:', u); fails++; } catch { /* ok */ }
}
process.exit(fails ? 1 : 0);
EOF
else
  bad "node not available"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "cloud-local-browser-qa-check: ALL PASS"
  exit 0
fi
echo "cloud-local-browser-qa-check: $FAILS FAIL(S)" >&2
exit 1
