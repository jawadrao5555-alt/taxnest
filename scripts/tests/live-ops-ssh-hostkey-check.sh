#!/bin/bash
# Prove Live Ops Diagnose/Remediate SSH uses the committed pinned ED25519
# host key with StrictHostKeyChecking=yes. Does NOT SSH, deploy, or print keys.
# Usage: bash scripts/tests/live-ops-ssh-hostkey-check.sh
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

PIN_IP="115.186.164.126"
PIN_TYPE="ssh-ed25519"
PIN_KEY="AAAAC3NzaC1lZDI1NTE5AAAAIFWHBLLwOkihrQuSTweFrbLjLTE2vdYZiDnnpiUmCEsE"
PIN_LINE="${PIN_IP} ${PIN_TYPE} ${PIN_KEY}"
FAKE_KEY="AAAAC3NzaC1lZDI1NTE5AAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

KH="$ROOT/scripts/lib/live-known-hosts"
HOST="$ROOT/scripts/lib/live-host.sh"
DIAG="$ROOT/scripts/ci-live-ops-diagnose.sh"
REM="$ROOT/scripts/ci-live-ops-remediate.sh"
OWN="$ROOT/scripts/ci-live-ops-owner-command.sh"
WF="$ROOT/.github/workflows/live-ops-diagnose.yml"
REM_WF="$ROOT/.github/workflows/live-ops-remediate.yml"
OWN_WF="$ROOT/.github/workflows/live-ops-owner-bridge.yml"

for f in "$KH" "$HOST" "$DIAG" "$REM" "$OWN" "$WF" "$REM_WF" "$OWN_WF"; do
  [ -f "$f" ] || bad "missing $f"
done

# --------------------------------------------------------------------------- pinned known_hosts
if [ -f "$KH" ]; then
  grep -Fxq "$PIN_LINE" "$KH" \
    && ok "live-known-hosts contains the exact pinned ED25519 line" \
    || bad "live-known-hosts must contain exactly: ${PIN_IP} ${PIN_TYPE} <pinned key>"

  if grep -Fq "$FAKE_KEY" "$KH"; then
    bad "live-known-hosts must not contain the unpinned/fake test key"
  else
    ok "unpinned/fake host key is not in live-known-hosts"
  fi

  if grep -qiE 'ssh-rsa |ecdsa-sha2-' "$KH"; then
    bad "live-known-hosts should pin only the documented ED25519 key"
  else
    ok "live-known-hosts has no extra RSA/ECDSA host keys"
  fi

  if grep -qE 'ssh-keyscan|StrictHostKeyChecking=no' "$KH"; then
    bad "live-known-hosts must be host-key lines only"
  else
    ok "live-known-hosts is a known_hosts file (no ssh-keyscan / no StrictHostKeyChecking)"
  fi

  if command -v ssh-keygen >/dev/null 2>&1; then
    FOUND=$(ssh-keygen -F "$PIN_IP" -f "$KH" 2>/dev/null | grep -v '^#' | tr -s ' ' || true)
    echo "$FOUND" | grep -Fq "$PIN_KEY" \
      && ok "ssh-keygen -F finds the pinned key for ${PIN_IP}" \
      || bad "ssh-keygen -F did not find the pinned key in live-known-hosts"

    TMP=$(mktemp)
    echo "${PIN_IP} ${PIN_TYPE} ${FAKE_KEY}" > "$TMP"
    FAKE_FOUND=$(ssh-keygen -F "$PIN_IP" -f "$TMP" 2>/dev/null | grep -v '^#' | tr -s ' ' || true)
    rm -f "$TMP"
    echo "$FAKE_FOUND" | grep -Fq "$PIN_KEY" \
      && bad "fake known_hosts must not match the pinned key" \
      || ok "arbitrary/unpinned host key does not match the pinned ED25519 key"
  else
    ok "ssh-keygen not present — skipped fingerprint lookup (static pin still checked)"
  fi
fi

# --------------------------------------------------------------------------- live-host.sh
if [ -f "$HOST" ]; then
  grep -q 'StrictHostKeyChecking=yes' "$HOST" \
    && ok "live-host.sh sets StrictHostKeyChecking=yes" \
    || bad "live-host.sh must set StrictHostKeyChecking=yes"

  if grep -qE 'StrictHostKeyChecking=no|StrictHostKeyChecking=accept-new' "$HOST"; then
    bad "live-host.sh must not weaken StrictHostKeyChecking"
  else
    ok "live-host.sh does not set StrictHostKeyChecking=no/accept-new"
  fi

  grep -q 'UserKnownHostsFile=' "$HOST" \
    && ok "live-host.sh pins UserKnownHostsFile" \
    || bad "live-host.sh must set UserKnownHostsFile"

  grep -q 'GlobalKnownHostsFile=/dev/null' "$HOST" \
    && ok "live-host.sh ignores runner global known_hosts" \
    || bad "live-host.sh must set GlobalKnownHostsFile=/dev/null so only the pin is trusted"

  if grep -q 'LIVE_KNOWN_HOSTS="${LIVE_KNOWN_HOSTS:-/home/runner/workspace/scripts/lib/live-known-hosts}"' "$HOST"; then
    bad "live-host.sh must not default known_hosts to the Replit /home/runner/workspace path"
  else
    ok "live-host.sh no longer defaults known_hosts to /home/runner/workspace"
  fi

  grep -q '_LIVE_LIB_DIR' "$HOST" && grep -q 'live-known-hosts' "$HOST" \
    && ok "live-host.sh defaults known_hosts next to this lib file" \
    || bad "live-host.sh must resolve live-known-hosts relative to scripts/lib"

  grep -q "$PIN_IP" "$HOST" \
    && ok "live-host.sh still targets ${PIN_IP} only as default IP" \
    || bad "live-host.sh must default LIVE_SSH_IP to the pinned VPS"
fi

# --------------------------------------------------------------------------- diagnose / remediate CI helpers
for f in "$DIAG" "$REM" "$OWN"; do
  bn=$(basename "$f")
  bash -n "$f" && ok "$bn bash -n" || bad "$bn bash -n failed"

  grep -q 'LIVE_KNOWN_HOSTS="$ROOT/scripts/lib/live-known-hosts"' "$f" \
    && ok "$bn exports checkout-relative live-known-hosts" \
    || bad "$bn must export LIVE_KNOWN_HOSTS=\$ROOT/scripts/lib/live-known-hosts before SSH"

  grep -q 'require_live_key' "$f" \
    && ok "$bn requires the pinned known_hosts file to exist" \
    || bad "$bn must call require_live_key before SSH"

  grep -q 'StrictHostKeyChecking=yes' "$f" \
    && ok "$bn asserts StrictHostKeyChecking=yes" \
    || bad "$bn must assert StrictHostKeyChecking=yes"

  if grep -vE '^\s*#' "$f" | grep -qE 'ssh-keyscan|-o StrictHostKeyChecking=no|-o StrictHostKeyChecking=accept-new|UserKnownHostsFile=/dev/null'; then
    bad "$bn must not ssh-keyscan or disable host-key checking"
  else
    ok "$bn has no ssh-keyscan / no StrictHostKeyChecking=no"
  fi

  if grep -vE '^\s*#' "$f" | grep -q 'echo.*PRODUCTION_SSH_PRIVATE_KEY'; then
    bad "$bn must not echo the production SSH private key"
  else
    ok "$bn does not echo PRODUCTION_SSH_PRIVATE_KEY"
  fi
done

# Diagnose stays read-only artisan diagnose; remediate stays separate
grep -q 'live-ops:diagnose' "$DIAG" \
  && ok "diagnose SSH path still runs live-ops:diagnose only" \
  || bad "diagnose must keep allow-listed live-ops:diagnose"
if grep -q 'live-ops:remediate' "$DIAG"; then
  bad "diagnose must not invoke live-ops:remediate"
else
  ok "diagnose does not invoke remediate"
fi
grep -q 'live-ops:remediate' "$REM" \
  && ok "remediate helper remains the separate remediate path" \
  || bad "remediate helper must still call live-ops:remediate"

grep -q 'live-ops:owner-command' "$OWN" \
  && ok "owner-command SSH path runs live-ops:owner-command" \
  || bad "owner-command helper must call live-ops:owner-command"
if grep -q 'live-ops:remediate' "$OWN"; then
  bad "owner-command helper must not invoke live-ops:remediate"
else
  ok "owner-command helper does not invoke remediate"
fi

python3 - "$WF" "$REM_WF" "$OWN_WF" <<'PY' && ok "Live Ops Diagnose/Remediate/Owner Bridge stay on Environment production" || bad "Live Ops Environment split broken"
import re, sys
texts = [open(p, encoding="utf-8").read() for p in sys.argv[1:]]
for text in texts:
    if not re.search(r"(?m)^\s+environment:\s+production\s*$", text):
        sys.exit(1)
    if re.search(r"(?m)^\s+environment:\s+production-deploy\s*$", text):
        sys.exit(1)
sys.exit(0)
PY

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "live-ops-ssh-hostkey-check: ALL PASS"
  exit 0
fi
echo "live-ops-ssh-hostkey-check: $FAILS FAIL(S)" >&2
exit 1
