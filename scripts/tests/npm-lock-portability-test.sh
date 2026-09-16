#!/usr/bin/env bash
# Run the lock portability guard against an isolated copy and prove that it
# rejects a non-public resolved URL. No install, audit, registry override, or
# firewall bypass is performed.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
NODE_BIN="${NODE_BIN:-node}"
GUARD="$ROOT/scripts/tests/npm-lock-portability.mjs"
tmp="$(mktemp -d "${TMPDIR:-/tmp}/taxnest-npm-lock-guard.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

locks=(
  package-lock.json
  agent-realtime-gateway/package-lock.json
  artifacts/mockup-sandbox/package-lock.json
  pra-agent/package-lock.json
  tools/video-pipeline/package-lock.json
)

for lock in "${locks[@]}"; do
  mkdir -p "$tmp/$(dirname "$lock")"
  cp "$ROOT/$lock" "$tmp/$lock"
  cp "$ROOT/${lock%package-lock.json}package.json" "$tmp/${lock%package-lock.json}package.json"
done

"$NODE_BIN" "$GUARD" --root "$tmp"

"$NODE_BIN" - "$tmp/package-lock.json" <<'NODE'
const fs = require('node:fs');
const file = process.argv[2];
const lock = JSON.parse(fs.readFileSync(file, 'utf8'));
lock.packages['node_modules/__portability_probe__'] = {
  version: '0.0.0',
  resolved: 'https://package-firewall.replit.internal/npm/__portability_probe__/-/__portability_probe__-0.0.0.tgz',
  integrity: 'sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
};
fs.writeFileSync(file, JSON.stringify(lock));
NODE

if "$NODE_BIN" "$GUARD" --root "$tmp" >/dev/null 2>&1; then
  echo "NPM LOCK PORTABILITY GUARD FAILED to reject a private resolved URL" >&2
  exit 1
fi

echo "NPM LOCK PORTABILITY ISOLATED REGRESSION TEST PASSED"