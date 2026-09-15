#!/usr/bin/env bash
# Executable harness for scripts/lib/settings-baseline.sh — not grep-only.
# Uses a disposable sqlite DB + real artisan commands. Never touches production.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$ROOT"

TMP=$(mktemp -d /tmp/taxnest-settings-harness-XXXXXX)
trap 'rm -rf "$TMP"' EXIT

export LIVE_SETTINGS_BASE="$TMP/.taxnest-settings-before.json"
export LIVE_SETTINGS_BASELINES_DIR="$TMP/.taxnest-settings-baselines"
export LIVE_SETTINGS_RETAINED_DIR="$TMP/.taxnest-settings-retained"
DB="$TMP/lab.sqlite"
export DB_CONNECTION=sqlite
export DB_DATABASE="$DB"
export APP_ENV=local
export APP_KEY="${APP_KEY:-base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=}"
export SESSION_DRIVER=array
export CACHE_STORE=array

# Isolate from any inherited mysql env.
unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_SOCKET || true

export SETTINGS_ARTISAN="php artisan"
# shellcheck disable=SC1091
. "$ROOT/scripts/lib/settings-baseline.sh"
settings_baseline_init_dirs

FAILS=0
ok() { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS + 1)); }

assert_no_raw_settings() {
  local log="$1"
  if grep -E '\["orders"|"service_jobs"|"reports"\]' "$log" >/dev/null 2>&1; then
    bad "raw setting values leaked in output ($log)"
  else
    ok "no raw setting values in $(basename "$log")"
  fi
}

php "$ROOT/scripts/tests/settings-baseline-harness-seed.php" "$DB" "$LIVE_SETTINGS_BASE"
BEFORE_BYTES=$(wc -c < "$LIVE_SETTINGS_BASE" | tr -d ' ')

# --- 1) retained baseline must never be overwritten by capture ---
DEPLOY_A=$(settings_baseline_deploy_id shaA)
# Mutate live so a naive capture would differ, then prove capture refuses while retained exists
php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite");
Illuminate\Support\Facades\DB::reconnect("sqlite");
Illuminate\Support\Facades\DB::table("users")->where("id",1)->update(["pos_custom_access"=>"[\"orders\",\"service_jobs\"]"]);
Illuminate\Support\Facades\DB::table("users")->where("id",3)->update(["pos_custom_access"=>"[\"reports\",\"service_jobs\"]"]);
echo "MUTATED\n";
'
# Capture while retained still present must fail closed (handle_retained restores first).
# First: prove artisan --out on retained path refuses.
if php artisan pos:settings-snapshot --out="$LIVE_SETTINGS_BASE" >/tmp/h-clobber.log 2>&1; then
  bad "snapshot --out onto retained path should refuse"
else
  ok "existing retained baseline is never overwritten by snapshot --out"
fi
AFTER_BYTES=$(wc -c < "$LIVE_SETTINGS_BASE" | tr -d ' ')
[ "$BEFORE_BYTES" = "$AFTER_BYTES" ] && ok "retained file byte-size unchanged after refused clobber" \
  || bad "retained file changed after refused clobber"

# --- 2/3) exact two-row restore; legitimate service_jobs remains ---
settings_baseline_handle_retained "$DEPLOY_A" >"$TMP/restore.log" 2>&1 || true
RC=$?
if [ "$RC" -eq 0 ] && grep -q REMOTE_SETTINGS_RETAINED_RESTORED "$TMP/restore.log"; then
  ok "exact two-row service_jobs-only restoration succeeds"
else
  bad "retained restore failed (rc=$RC)"; cat "$TMP/restore.log" >&2 || true
fi
assert_no_raw_settings "$TMP/restore.log"

U1=$(php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
echo Illuminate\Support\Facades\DB::table("users")->where("id",1)->value("pos_custom_access");
')
U2=$(php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
echo Illuminate\Support\Facades\DB::table("users")->where("id",2)->value("pos_custom_access");
')
U3=$(php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
echo Illuminate\Support\Facades\DB::table("users")->where("id",3)->value("pos_custom_access");
')
[ "$U1" = '["orders"]' ] && ok "user1 restored without service_jobs" || bad "user1=$U1"
[ "$U2" = '["orders","service_jobs"]' ] && ok "legitimate pre-existing service_jobs remains" || bad "user2=$U2"
[ "$U3" = '["reports"]' ] && ok "user3 restored without service_jobs" || bad "user3=$U3"
[ ! -f "$LIVE_SETTINGS_BASE" ] && ok "active retain slot cleared after verified restore" || bad "retain slot still present"
ls "$LIVE_SETTINGS_RETAINED_DIR"/archived-after-restore-* >/dev/null \
  && ok "forensic archive kept after restore" || bad "forensic archive missing"

# --- 4) malformed baseline exits 89 without alteration ---
printf '%s' '{"not":"a-snapshot"}' > "$LIVE_SETTINGS_BASE"
BYTES_BAD=$(wc -c < "$LIVE_SETTINGS_BASE" | tr -d ' ')
php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
Illuminate\Support\Facades\DB::table("users")->where("id",1)->update(["pos_custom_access"=>"[\"orders\",\"team\"]"]);
'
U1_BEFORE=$(php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
echo Illuminate\Support\Facades\DB::table("users")->where("id",1)->value("pos_custom_access");
')
set +e
settings_baseline_handle_retained "$(settings_baseline_deploy_id bad)" >"$TMP/bad.log" 2>&1
BAD_RC=$?
set -e
[ "$BAD_RC" -eq 89 ] && ok "malformed/ambiguous baseline exits 89" || bad "expected 89 got $BAD_RC"
[ -f "$LIVE_SETTINGS_BASE" ] && [ "$(wc -c < "$LIVE_SETTINGS_BASE" | tr -d ' ')" = "$BYTES_BAD" ] \
  && ok "malformed retained baseline left unaltered" || bad "malformed retained was altered"
U1_AFTER=$(php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
echo Illuminate\Support\Facades\DB::table("users")->where("id",1)->value("pos_custom_access");
')
[ "$U1_BEFORE" = "$U1_AFTER" ] && ok "DB unchanged after refused malformed restore" || bad "DB changed on refuse"
rm -f "$LIVE_SETTINGS_BASE"

# Re-seed clean state for remaining cases
php "$ROOT/scripts/tests/settings-baseline-harness-seed.php" "$DB" >/dev/null

# --- 5) live-value race refuses (PHP planner) ---
php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
$snap=new App\Services\PosSettingsSnapshot();
$before=$snap->capture();
Illuminate\Support\Facades\DB::table("users")->where("id",1)->update(["pos_custom_access"=>"[\"orders\",\"service_jobs\"]"]);
$after=$snap->capture();
$plan=$snap->planProtectedRestore($before,$after);
Illuminate\Support\Facades\DB::table("users")->where("id",1)->update(["pos_custom_access"=>"[\"orders\",\"service_jobs\",\"reports\"]"]);
$r=$snap->applyProtectedRestore($plan["restore"], false);
exit(($r["written"]===0 && ($r["refused"][0]["reason"]??"")==="live_value_mismatch")?0:1);
' && ok "live-value race refuses write" || bad "live-value race did not refuse"

# Reset user1
php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
Illuminate\Support\Facades\DB::table("users")->where("id",1)->update(["pos_custom_access"=>"[\"orders\"]"]);
'

# --- 6/8) clean deploy removes only own temp; filenames do not collide ---
D1=$(settings_baseline_deploy_id sha1)
D2=$(settings_baseline_deploy_id sha2)
settings_baseline_capture "$D1" >/dev/null
P1="$SETTINGS_BASE"
settings_baseline_capture "$D2" >/dev/null
P2="$SETTINGS_BASE"
[ "$P1" != "$P2" ] && [ -f "$P1" ] && [ -f "$P2" ] && ok "per-deploy filenames do not collide" \
  || bad "baseline path collision P1=$P1 P2=$P2"
SETTINGS_BASE_OK=1 SETTINGS_BASE="$P1" settings_baseline_post_check "$D1" >"$TMP/clean.log" 2>&1
[ ! -f "$P1" ] && [ -f "$P2" ] && ok "fresh clean deploy removes only its own temporary baseline" \
  || bad "clean cleanup wrong P1_exists=$( [[ -f $P1 ]] && echo y || echo n) P2=$( [[ -f $P2 ]] && echo y || echo n)"
assert_no_raw_settings "$TMP/clean.log"

# --- 7) new regression keeps forensic + canonical copies ---
SETTINGS_BASE="$P2"
SETTINGS_BASE_OK=1
# Mutate after baseline P2 was taken
php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
Illuminate\Support\Facades\DB::table("users")->where("id",1)->update(["pos_custom_access"=>"[\"orders\",\"service_jobs\"]"]);
Illuminate\Support\Facades\DB::table("users")->where("id",3)->update(["pos_custom_access"=>"[\"reports\",\"service_jobs\"]"]);
'
settings_baseline_post_check "$D2" >"$TMP/reg.log" 2>&1
grep -q REMOTE_SETTINGS_REGRESSION "$TMP/reg.log" && ok "regression marker emitted" || bad "no regression marker"
grep -q REMOTE_SETTINGS_RESTORED "$TMP/reg.log" && ok "regression auto-restore verified" || bad "regression restore missing"
[ -f "$LIVE_SETTINGS_BASE" ] && ok "canonical retained copy installed after regression" || bad "canonical retain missing"
ls "$LIVE_SETTINGS_RETAINED_DIR"/retained-after-regression-* >/dev/null \
  && ok "forensic retained-after-regression copy kept" || bad "regression forensic missing"
[ -f "$P2" ] && ok "this deploy baseline kept after regression" || bad "deploy baseline deleted on regression"
assert_no_raw_settings "$TMP/reg.log"

# Prove second regression does not overwrite existing canonical retain
CANON_BYTES=$(wc -c < "$LIVE_SETTINGS_BASE" | tr -d ' ')
# Capture new deploy while clearing retain first via restore path
# (handle_retained will restore+archive the canonical slot)
D3=$(settings_baseline_deploy_id sha3)
settings_baseline_handle_retained "$D3" >/dev/null
settings_baseline_capture "$D3" >/dev/null
P3="$SETTINGS_BASE"
# Force a regression again but pre-create canonical retain with a marker file that must survive
printf '%s' '{"generated_at":"marker","tables":{}}' > "$LIVE_SETTINGS_BASE"
# invalid but present — post_check must leave it untouched when installing
php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(["database.default"=>"sqlite","database.connections.sqlite.database"=>getenv("DB_DATABASE")]);
Illuminate\Support\Facades\DB::purge("sqlite"); Illuminate\Support\Facades\DB::reconnect("sqlite");
Illuminate\Support\Facades\DB::table("users")->where("id",1)->update(["pos_custom_access"=>"[\"orders\",\"service_jobs\"]"]);
'
SETTINGS_BASE_OK=1 settings_baseline_post_check "$D3" >"$TMP/reg2.log" 2>&1
grep -q 'canonical retained baseline already present — left untouched' "$TMP/reg2.log" \
  && ok "new regression keeps existing canonical retain untouched" \
  || bad "canonical retain was overwritten"
grep -q 'generated_at":"marker"' "$LIVE_SETTINGS_BASE" \
  && ok "pre-existing canonical retain content preserved" \
  || bad "canonical retain content changed"

if [ "$FAILS" -eq 0 ]; then
  echo "settings-baseline-retain-harness: ALL PASS"
  exit 0
fi
echo "settings-baseline-retain-harness: $FAILS FAIL(S)" >&2
exit 1
