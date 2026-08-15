#!/bin/bash
# CANONICAL password reset for the standing LIVE FBR QA account (Task 735).
#
# WHY THIS EXISTS: on 15 Aug 2026 the QA password (qa.fbraudit@taxnest.com.pk,
# user 61, company 39) drifted twice in 10 minutes because separate isolated
# task-agent sessions each SSH-reset it to a NEW value of their own — and
# .local/ is gitignored, so those values never reached the main workspace.
#
# THE RULE: nobody invents a new password. This script re-asserts the ONE
# canonical value, LIVE_FBR_QA_PASS from .local/qa-creds.env, onto the live
# bcrypt hash. It never writes qa-creds.env. If your login fails:
#   1. run this script (it makes live match qa-creds.env),
#   2. if it still fails your qa-creds.env snapshot is STALE — STOP and
#      report; do NOT rotate the password to something new.
#
# Also resets the PRA QA admin (company 35) when --pra is passed, using
# LIVE_QA_PASS the same way.
#
# Usage: bash scripts/fbr-qa-reset-password.sh [--pra]
# Exit:  0 = hash now matches canonical value, non-zero = failed.
set -uo pipefail
cd "$(dirname "$0")/.."

KEY=".local/ssh/cpanel_deploy_key"
HOST="taxnestc@cpanel.taxnest.com.pk"
SSH_OPTS=(-i "$KEY" -p 22 -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new)
LIVE_DIR="/home/taxnestc/public_html"

[ -f .local/qa-creds.env ] || { echo "ERROR: .local/qa-creds.env missing" >&2; exit 2; }
. .local/qa-creds.env

if [ "${1:-}" = "--pra" ]; then
  EMAIL="qa.fullaudit@taxnest.com.pk"; PASS="${LIVE_QA_PASS:-}"
else
  EMAIL="${LIVE_FBR_QA_LOGIN:-qa.fbraudit@taxnest.com.pk}"; PASS="${LIVE_FBR_QA_PASS:-}"
fi
[ -n "$PASS" ] || { echo "ERROR: canonical password missing from qa-creds.env" >&2; exit 2; }
[ -f "$KEY" ] || { echo "ERROR: SSH key not found at $KEY" >&2; exit 2; }

echo "==> Re-asserting canonical password for $EMAIL on live"
# ssh joins its command args with spaces (no positional params) — feed the
# script over stdin via `bash -s` so EMAIL/PASS arrive as REAL argv entries.
REMOTE_SCRIPT=$(cat <<REMOTE
cd $LIVE_DIR && php -r '
require "vendor/autoload.php";
\$app = require "bootstrap/app.php";
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$u = DB::table("users")->where("email", \$argv[1])->first();
if (!\$u) { echo "NOUSER\n"; exit(1); }
if (Hash::check(\$argv[2], \$u->password)) { echo "ALREADY-CANONICAL\n"; exit(0); }
DB::table("users")->where("id", \$u->id)->update(["password" => Hash::make(\$argv[2])]);
\$u = DB::table("users")->where("id", \$u->id)->first();
echo Hash::check(\$argv[2], \$u->password) ? "RESET-OK\n" : "RESET-FAILED\n";
' "\$1" "\$2"
REMOTE
)

run_reset() {
  timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" "bash -s -- $(printf '%q %q' "$EMAIL" "$PASS")" 2>/dev/null <<<"$REMOTE_SCRIPT"
}

OUT=$(run_reset)
# Transient SSH flake (empty output / no verdict marker): back off briefly and
# retry ONCE before giving up (Task 746).
case "$OUT" in
  *ALREADY-CANONICAL*|*RESET-OK*|*NOUSER*|*RESET-FAILED*) : ;;
  *)
    echo "    WARN: SSH attempt returned no verdict (output: '$OUT') — retrying once after 5s..." >&2
    sleep 5
    OUT=$(run_reset)
    ;;
esac
echo "    $OUT"
case "$OUT" in
  *ALREADY-CANONICAL*|*RESET-OK*) echo "Password matches canonical qa-creds.env value."; exit 0 ;;
  *) echo "ERROR: reset failed ($OUT)" >&2; exit 1 ;;
esac
