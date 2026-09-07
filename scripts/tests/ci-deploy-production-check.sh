#!/bin/bash
# Static validation for the GitHub Actions production deploy layer.
# Does NOT SSH, deploy, or require secrets.
# Usage: bash scripts/tests/ci-deploy-production-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

WF="$ROOT/.github/workflows/deploy-production.yml"
CI="$ROOT/scripts/ci-deploy-production.sh"
LIB="$ROOT/scripts/lib/live-remote-apply.sh"
KH="$ROOT/scripts/lib/live-known-hosts"
DOC="$ROOT/docs/ops/github-production-deploy.md"
DL="$ROOT/scripts/deploy-live.sh"

[ -f "$WF" ] || bad "missing $WF"
[ -f "$CI" ] || bad "missing $CI"
[ -f "$LIB" ] || bad "missing $LIB"
[ -f "$KH" ] || bad "missing $KH"
[ -f "$DOC" ] || bad "missing $DOC"
[ -f "$DL" ] || bad "missing $DL"

# --- workflow shape
if [ -f "$WF" ]; then
  grep -q 'environment: production' "$WF" \
    && ok "workflow uses environment: production" \
    || bad "workflow missing environment: production"

  grep -q 'PRODUCTION_SSH_PRIVATE_KEY' "$WF" \
    && ok "workflow references PRODUCTION_SSH_PRIVATE_KEY" \
    || bad "workflow missing PRODUCTION_SSH_PRIVATE_KEY"

  grep -q 'group: production-deploy' "$WF" \
    && ok "workflow has concurrency group production-deploy" \
    || bad "workflow missing concurrency group"

  grep -q 'cancel-in-progress: false' "$WF" \
    && ok "concurrency does not cancel in-progress deploys" \
    || bad "concurrency should set cancel-in-progress: false"

  grep -qE 'branches:[[:space:]]*$|[[:space:]]+- main' "$WF" \
    && ok "workflow triggers on main" \
    || bad "workflow should trigger on main"

  grep -q 'workflow_dispatch' "$WF" \
    && ok "workflow supports workflow_dispatch" \
    || bad "workflow missing workflow_dispatch"

  grep -q 'ref: \${{ github.sha }}' "$WF" \
    && ok "checkout uses github.sha" \
    || bad "checkout must pin ref to github.sha"

  # Must never disable host key checks in the workflow itself (ignore comments)
  if grep -vE '^\s*#' "$WF" | grep -qiE 'StrictHostKeyChecking\s*=\s*no|UserKnownHostsFile\s*=\s*/dev/null'; then
    bad "workflow disables SSH host verification"
  else
    ok "workflow does not disable SSH host verification"
  fi

  # Must not use the Replit key (mentioning it only to forbid it in comments is OK)
  if grep -vE '^\s*#' "$WF" | grep -q 'nayatel_vps_key'; then
    bad "workflow references Replit nayatel_vps_key"
  else
    ok "workflow does not reference Replit nayatel_vps_key"
  fi

  # Must not push to main from the workflow
  if grep -qiE 'git push|push origin' "$WF"; then
    bad "workflow appears to git push"
  else
    ok "workflow does not git push"
  fi

  # No private key material patterns (BEGIN blocks)
  if grep -qE 'BEGIN (OPENSSH |RSA |EC )?PRIVATE KEY' "$WF"; then
    bad "workflow contains a private key block"
  else
    ok "workflow contains no private key block"
  fi
fi

# --- CI script shape
if [ -f "$CI" ]; then
  grep -q 'live-known-hosts' "$CI" \
    && ok "ci script pins live-known-hosts" \
    || bad "ci script must use live-known-hosts"

  grep -q 'StrictHostKeyChecking=yes' "$CI" \
    && ok "ci script asserts StrictHostKeyChecking=yes" \
    || bad "ci script must assert StrictHostKeyChecking=yes"

  grep -q 'live-remote-apply.sh' "$CI" \
    && ok "ci script sources live-remote-apply.sh" \
    || bad "ci script must source live-remote-apply.sh"

  if grep -qiE 'git push|push origin HEAD:main' "$CI"; then
    bad "ci script must never git push"
  else
    ok "ci script does not git push"
  fi

  if grep -vE '^\s*#|refusing Replit key|NEVER uses the Replit key' "$CI" | grep -q 'nayatel_vps_key'; then
    # Allow the refuse-guard case paths only
    if grep -vE '^\s*#|refusing Replit key|NEVER uses the Replit key|\*/\.local/ssh/nayatel_vps_key|\*/nayatel_vps_key\)' "$CI" | grep -q 'nayatel_vps_key'; then
      bad "ci script mentions nayatel_vps_key without refuse guard"
    else
      ok "ci script refuses Replit key path"
    fi
  else
    ok "ci script refuses Replit key path"
  fi

  if grep -vE '^\s*#' "$CI" | grep -qiE 'StrictHostKeyChecking=no|UserKnownHostsFile=/dev/null'; then
    bad "ci script disables SSH host verification"
  else
    ok "ci script does not disable SSH host verification"
  fi

  # Must not embed secrets
  if grep -qE 'BEGIN (OPENSSH |RSA |EC )?PRIVATE KEY' "$CI"; then
    bad "ci script contains a private key block"
  else
    ok "ci script contains no private key block"
  fi

  # Skip Replit-local preflight scripts
  for badpat in pos-white-screen-check plan-gate-check pos-caller-dial-check fbr-pharmacy-counter-check live-screen-smoke; do
    if grep -q "$badpat" "$CI"; then
      bad "ci script still invokes Replit-local check $badpat"
    fi
  done
  ok "ci script skips Replit-local browser/MySQL preflights"

  grep -q 'insert_committed_elaan_spec' "$CI" \
    && ok "ci script inserts committed Elaan spec after SSH" \
    || bad "ci script must insert deploy/elaan.yml after SSH"

  grep -q 'elaan-insert.sh' "$CI" \
    && ok "ci script uses existing elaan-insert.sh" \
    || bad "ci script must call scripts/elaan-insert.sh"

  python3 - "$CI" <<'PY' && ok "ci script inserts spec before freshness check / remote_apply" || bad "ci elaan insert must run before check_elaan_freshness and remote_apply"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
if "\ninsert_committed_elaan_spec\ncheck_elaan_freshness" not in text:
    sys.exit(1)
if text.find("\ninsert_committed_elaan_spec\ncheck_elaan_freshness") > text.find("remote_apply"):
    sys.exit(1)
sys.exit(0)
PY

  bash -n "$CI" && ok "ci script bash -n clean" || bad "ci script bash -n failed"
fi

# --- shared remote apply
if [ -f "$LIB" ]; then
  grep -q 'git checkout -B main' "$LIB" \
    && ok "remote apply checks out exact TARGET_SHA on main" \
    || bad "remote apply should checkout exact TARGET_SHA"

  if grep -qE 'git pull origin main' "$LIB"; then
    bad "remote apply still uses blind git pull origin main (should checkout TARGET_SHA)"
  else
    ok "remote apply does not blind-pull origin/main tip"
  fi

  grep -q 'flock -w 300' "$LIB" \
    && ok "remote apply holds deploy flock" \
    || bad "remote apply missing flock"

  grep -q 'artisan down' "$LIB" \
    && ok "remote apply opens maintenance window" \
    || bad "remote apply missing artisan down"

  grep -q 'OPCACHE_RESET_OK' "$LIB" \
    && ok "remote apply proves PHP-FPM OPcache reset" \
    || bad "remote apply missing OPcache proof"

  grep -q 'taxnest-queue\|LIVE_QUEUE_SERVICE' "$LIB" \
    && ok "remote apply restarts queue service" \
    || bad "remote apply missing queue restart"

  bash -n "$LIB" && ok "live-remote-apply.sh bash -n clean" || bad "live-remote-apply.sh bash -n failed"
fi

# --- deploy-live still sources shared helper (manual path preserved)
if [ -f "$DL" ]; then
  grep -q 'live-remote-apply.sh' "$DL" \
    && ok "deploy-live.sh sources shared live-remote-apply.sh" \
    || bad "deploy-live.sh must source live-remote-apply.sh"

  grep -q 'git push origin HEAD:main' "$DL" \
    && ok "deploy-live.sh still pushes for manual path" \
    || bad "deploy-live.sh manual push path missing (behavior regression)"

  bash -n "$DL" && ok "deploy-live.sh bash -n clean" || bad "deploy-live.sh bash -n failed"
fi

# --- known hosts pin
if [ -f "$KH" ]; then
  grep -q '115.186.164.126' "$KH" \
    && ok "live-known-hosts pins Islamabad VPS IP" \
    || bad "live-known-hosts missing approved IP"
  if grep -qiE 'StrictHostKeyChecking|PasswordAuthentication' "$KH"; then
    bad "live-known-hosts should only contain host key lines"
  else
    ok "live-known-hosts looks like a known_hosts file"
  fi
fi

# --- docs
if [ -f "$DOC" ]; then
  for needle in 'environment' 'production' 'PRODUCTION_SSH_PRIVATE_KEY' 'taxnest-production-deploy' 'nayatel_vps_key' 'ROLLBACK.md' 'required reviewers' 'deploy/elaan.yml' 'elaan-insert'; do
    grep -qi "$needle" "$DOC" || bad "docs missing mention of: $needle"
  done
  ok "docs cover Environment, secret, key, reviewers, rollback, Elaan spec"
fi

# --- handoff pointer
if [ -f "$ROOT/CLOUD_AGENT_HANDOFF.md" ]; then
  grep -q 'github-production-deploy.md' "$ROOT/CLOUD_AGENT_HANDOFF.md" \
    && ok "CLOUD_AGENT_HANDOFF.md links production deploy docs" \
    || bad "CLOUD_AGENT_HANDOFF.md should link docs/ops/github-production-deploy.md"
  grep -q 'deploy/elaan.yml' "$ROOT/CLOUD_AGENT_HANDOFF.md" \
    && ok "CLOUD_AGENT_HANDOFF.md mentions deploy/elaan.yml" \
    || bad "CLOUD_AGENT_HANDOFF.md should mention deploy/elaan.yml"
fi

# --- committed spec parser
if [ -f "$ROOT/scripts/tests/elaan-spec-parse-check.sh" ]; then
  bash "$ROOT/scripts/tests/elaan-spec-parse-check.sh" \
    && ok "elaan spec parser checks passed" \
    || bad "elaan spec parser checks failed"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
