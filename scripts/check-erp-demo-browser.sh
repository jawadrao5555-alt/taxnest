#!/usr/bin/env bash
# Independent fresh ERP lab. Never alters the standalone browser fixture or an existing DB.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
if [[ "${1:-}" != --guarded ]]; then
  exec bash scripts/rc-safe-run -- bash "$0" --guarded "${1:-}"
fi
[[ "${RC_SAFE_RUN:-}" == 1 && -r "${LD_PRELOAD:-}" ]] || { echo 'ERP demo requires egress guard' >&2; exit 2; }
if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
  echo 'Commit source before exact-SHA demo evidence' >&2
  # Filenames/status only, bounded and Git-quoted; never emit diffs or file contents.
  git -c core.quotePath=true status --short --untracked-files=normal | awk 'NR<=40 {print substr($0,1,512)} END {if(NR>40) print "[additional changed paths omitted]"}' >&2
  exit 2
fi
if [[ -n "${2:-}" ]]; then
  [[ "$2" == /* && -d "$2" && ! -L "$2" && "$(realpath "$2")" == "$2" ]] || { echo 'Canonical Playwright cache required' >&2; exit 2; }
  export PLAYWRIGHT_BROWSERS_PATH="$2"
fi
for tool in mariadbd mariadb mariadb-admin mariadb-install-db php node; do command -v "$tool" >/dev/null || { echo "Missing prerequisite: $tool" >&2; exit 2; }; done
[[ -f public/build/manifest.json ]] || { echo 'Build locked assets first' >&2; exit 2; }
STATE="$(mktemp -d /tmp/taxnest-erp-demo-$(id -u)-XXXXXXXX)"; chmod 700 "$STATE"
SUFFIX="${STATE#/tmp/taxnest-erp-demo-}"
export RC_MARIADB_ROOT="/tmp/taxnest-rc-mariadb-erp-demo-$SUFFIX" RC_MARIADB_PORT=33923
export RC_MARIADB_LD_PRELOAD="$LD_PRELOAD" RC_MARIADB_REQUIRE_EGRESS_GUARD=1
export ERP_DEMO_FIXTURE="$STATE/safe-runtime/browser-state/fixture.json"
export APP_ENV=local APP_URL=http://127.0.0.1:5927 DB_CONNECTION=mysql DB_DATABASE=taxnest_erp_demo
export DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD='' DB_SOCKET="$RC_MARIADB_ROOT/run/mariadb.sock"
export MAIL_MAILER=array QUEUE_CONNECTION=sync SESSION_DRIVER=file SESSION_SECURE_COOKIE=false
export LARAVEL_STORAGE_PATH="$STATE/storage" VIEW_COMPILED_PATH="$STATE/storage/framework/views"
export BASE_URL="$APP_URL" ERP_DEMO_SOURCE_SHA="$(git rev-parse HEAD)"
mkdir -p "$STATE/safe-runtime/browser-state" "$LARAVEL_STORAGE_PATH"/{logs,framework/views,framework/cache,framework/sessions,app/public}
server_pid=''
cleanup(){
  code=$?; trap - EXIT
  [[ -z "$server_pid" ]] || { kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; }
  bash scripts/rc-mariadb-lab.sh stop >/dev/null 2>&1 || true
  # Credentials are never an artifact and do not survive a failed browser run.
  rm -f "$ERP_DEMO_FIXTURE" "$ERP_DEMO_FIXTURE.tmp"
  exit "$code"
}
trap cleanup EXIT
bash scripts/rc-mariadb-lab.sh start
mariadb --protocol=socket --socket="$DB_SOCKET" -uroot -e 'CREATE DATABASE taxnest_erp_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
php scripts/erp-demo-fixture.php "$(dirname "$ERP_DEMO_FIXTURE")" "$DB_SOCKET"
php -S 127.0.0.1:5927 -t public scripts/erp-demo-router.php >"$STATE/server.log" 2>&1 & server_pid=$!
for _ in $(seq 1 30); do
  if php -r '$s=@fsockopen("127.0.0.1",5927);if(!$s)exit(1);fclose($s);'; then break; fi
  kill -0 "$server_pid"; sleep .2
done
node scripts/erp-demo-browser.mjs
