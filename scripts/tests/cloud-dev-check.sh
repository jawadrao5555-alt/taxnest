#!/bin/bash
# Static validation for Cloud Agent development bootstrap files.
# Does NOT require MariaDB to be running for most checks.
# Usage: bash scripts/tests/cloud-dev-check.sh

set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

ENVEX="$ROOT/.env.example"
INST="$ROOT/scripts/cloud-dev-install.sh"
START="$ROOT/scripts/cloud-dev-start.sh"
BOOT="$ROOT/scripts/cloud-dev-bootstrap.sh"
EJ="$ROOT/.cursor/environment.json"
DOC="$ROOT/docs/ops/cloud-agent-development.md"
ARCH="$ROOT/docs/ops/cloud-agent-architecture.md"
BROWSER_DOC="$ROOT/docs/ops/cloud-agent-local-browser-qa.md"
LOCK="$ROOT/package-lock.json"
HAND="$ROOT/CLOUD_AGENT_HANDOFF.md"

[ -f "$ENVEX" ] || bad "missing .env.example"
[ -f "$INST" ] || bad "missing cloud-dev-install.sh"
[ -f "$START" ] || bad "missing cloud-dev-start.sh"
[ -f "$BOOT" ] || bad "missing cloud-dev-bootstrap.sh"
[ -f "$EJ" ] || bad "missing .cursor/environment.json"
[ -f "$DOC" ] || bad "missing cloud-agent-development.md"
[ -f "$ARCH" ] || bad "missing cloud-agent-architecture.md"

if [ -f "$ENVEX" ]; then
  grep -q 'APP_ENV=local' "$ENVEX" && ok ".env.example APP_ENV=local" || bad ".env.example APP_ENV"
  grep -q 'DB_DATABASE=taxnest_dev' "$ENVEX" && ok ".env.example local DB name" || bad ".env.example DB name"
  grep -q 'taxnest_local_dev_only' "$ENVEX" && ok ".env.example local placeholder password" || bad ".env.example password placeholder"
  if grep -qiE 'BEGIN (OPENSSH|RSA|EC) PRIVATE KEY|sk-live|AKIA[0-9A-Z]{16}' "$ENVEX"; then
    bad ".env.example contains secret-like material"
  else
    ok ".env.example has no private-key/AWS-like blobs"
  fi
  if grep -E '^(FBR_|PRA_|CLOUDFLARE_|OPENAI_|MAIL_PASSWORD)=' "$ENVEX" | grep -q .; then
    bad ".env.example assigns FBR/PRA/Cloudflare/OpenAI/mail-password values"
  else
    ok ".env.example does not assign regulator/cloud/mail secret envs"
  fi
  if grep -E '^DB_PASSWORD=' "$ENVEX" | grep -vq 'taxnest_local_dev_only'; then
    bad ".env.example DB_PASSWORD is not the documented local placeholder"
  else
    ok ".env.example DB_PASSWORD is the local placeholder only"
  fi
fi

if [ -f "$LOCK" ]; then
  if grep -q 'package-firewall.replit.local' "$LOCK"; then
    bad "package-lock.json still has Replit firewall URLs"
  else
    ok "package-lock.json has no Replit firewall URLs"
  fi
fi

if [ -f "$EJ" ]; then
  grep -q 'cloud-dev-install.sh' "$EJ" && ok "environment.json install script" || bad "environment.json install"
  grep -q 'cloud-dev-start.sh' "$EJ" && ok "environment.json start script" || bad "environment.json start"
  if grep -q '"$schema"' "$EJ"; then
    bad "environment.json must not include a schema field"
  else
    ok "environment.json has no schema field"
  fi
  python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$EJ" \
    && ok "environment.json parses as JSON" \
    || bad "environment.json invalid JSON"
fi

for f in "$INST" "$START" "$BOOT"; do
  [ -f "$f" ] || continue
  bash -n "$f" && ok "bash -n $(basename "$f")" || bad "bash -n $(basename "$f")"
  if grep -qiE '115\.186\.164\.126|PRODUCTION_SSH|nayatel_vps_key|git push origin HEAD:main' "$f"; then
    bad "$(basename "$f") references production deploy paths"
  else
    ok "$(basename "$f") has no production deploy wiring"
  fi
done

if [ -f "$BOOT" ]; then
  grep -q 'DB_HOST' "$BOOT" && grep -q '127.0.0.1' "$BOOT" \
    && ok "bootstrap refuses non-local DB hosts" \
    || bad "bootstrap should guard non-local DB_HOST"
  if grep -E 'db:seed.*DatabaseSeeder|--class=DatabaseSeeder' "$BOOT" | grep -q .; then
    bad "bootstrap must not auto-run DatabaseSeeder"
  else
    ok "bootstrap does not auto-run DatabaseSeeder"
  fi
fi

for needle in 'cloud-dev-bootstrap' 'php artisan test' 'npm run build' 'production' 'NestPOS'; do
  grep -qi "$needle" "$DOC" || bad "development doc missing: $needle"
done
ok "development doc covers bootstrap/test/build/safety"

for needle in 'PosTaxMath' 'pra_status' 'DbCompat' 'CompanyIsolation' 'cursor/'; do
  grep -qi "$needle" "$ARCH" || bad "architecture doc missing: $needle"
done
ok "architecture doc covers core invariants"

if [ -f "$HAND" ]; then
  grep -q 'cloud-agent-development.md' "$HAND" \
    && ok "handoff links cloud-agent-development.md" \
    || bad "CLOUD_AGENT_HANDOFF.md should link cloud-agent-development.md"
fi

if [ -f "$BROWSER_DOC" ]; then
  ok "present cloud-agent-local-browser-qa.md"
else
  bad "missing docs/ops/cloud-agent-local-browser-qa.md"
fi

ISSUE_DOC="$ROOT/docs/ops/cloud-agent-issue-resolution.md"
if [ -f "$ISSUE_DOC" ]; then
  ok "present cloud-agent-issue-resolution.md"
else
  bad "missing docs/ops/cloud-agent-issue-resolution.md"
fi
if [ -f "$HAND" ]; then
  grep -q 'cloud-agent-issue-resolution.md' "$HAND" \
    && ok "handoff links issue-resolution policy" \
    || bad "CLOUD_AGENT_HANDOFF.md should link issue-resolution policy"
fi
if [ -f "$ROOT/AGENTS.md" ]; then
  ok "present AGENTS.md"
else
  bad "missing AGENTS.md"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
