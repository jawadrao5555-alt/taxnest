#!/usr/bin/env bash
# Disposable-only RC migration and native database labs.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CTL="$ROOT/scripts/rc-mariadb-lab.sh"; LAB_ROOT="$(realpath -m "${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-${UID}}")"
PORT="${RC_MARIADB_PORT:-33116}"; SOCKET="$LAB_ROOT/run/mariadb.sock"; MODE=all; KEEP=0
fail(){ printf 'rc-mariadb-migration-lab: %s\n' "$*" >&2; exit 1; }
for arg in "$@"; do case "$arg" in --all)MODE=all;;--full)MODE=full;;--upgrade)MODE=upgrade;;--concurrency)MODE=concurrency;;--di)MODE=di;;--mysql-skips)MODE=mysql-skips;;--category)MODE=category;;--keep-running)KEEP=1;;*)fail "unknown option $arg";;esac;done
[[ "$LAB_ROOT" == /tmp/taxnest-rc-mariadb-* ]] && [[ "$PORT" =~ ^[0-9]+$ ]] && ((PORT!=9000 && PORT!=33117)) || fail 'unsafe lab target'
cleanup(){ ((KEEP)) || bash "$CTL" stop || true; }; trap cleanup EXIT
bash "$CTL" start
CLIENT="$(bash "$CTL" env | awk -F= '$1=="RC_MARIADB_CLIENT"{print substr($0,index($0,"=")+1)}')"
export APP_ENV=rc-mariadb APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$PORT" DB_SOCKET="$SOCKET" DB_DATABASE=taxnest_rc_migration DB_USERNAME=root DB_PASSWORD='' DB_CONNECT_TIMEOUT=3 HONOR_DATABASE_URL=0 CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=null TAXNEST_RC_NO_EXTERNAL_FISCAL=1
export FBR_API_URL='' FBR_SANDBOX_URL='' FBR_PRODUCTION_URL='' FBR_TOKEN='' PRA_API_URL='' PRA_SANDBOX_URL='' PRA_PRODUCTION_URL='' PRA_TOKEN=''
unset DATABASE_URL
mysql(){ "$CLIENT" --protocol=socket --socket="$SOCKET" -uroot "$@"; }
reset(){ [[ "$1" =~ ^taxnest_rc_[a-z0-9_]+$ ]] || fail unsafe-db; mysql -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; export DB_DATABASE="$1"; }
checks(){ find "$ROOT/database/migrations" -maxdepth 1 -name '*.php' -printf '%f\n'|LC_ALL=C sort >"$LAB_ROOT/migration-files.txt"; [[ "$(sort "$LAB_ROOT/migration-files.txt"|uniq -d|wc -l)" == 0 ]] || fail duplicate-migrations; awk '!/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_.+\.php$/{bad=1}END{exit bad}' "$LAB_ROOT/migration-files.txt" || fail invalid-migration-name; awk -F_ '{print $1"_"$2"_"$3"_"$4,$0}' "$LAB_ROOT/migration-files.txt"|sort|awk '$1==p{print p" => "n" | "$2}{p=$1;n=$2}' >"$LAB_ROOT/migration-timestamp-ties.txt"; }
full(){ reset taxnest_rc_migration; php artisan migrate --force --no-interaction; php "$ROOT/tests/native/rc_mariadb_schema.php"; php artisan migrate --force --no-interaction|grep -q 'Nothing to migrate' || fail non-idempotent; }
upgrade(){ local stage="$LAB_ROOT/upgrade-prefix"; reset taxnest_rc_upgrade; rm -rf "$stage";mkdir -p "$stage"; while read -r f;do [[ "$f" < 2026_09_14_999999 ]]&&ln -s "$ROOT/database/migrations/$f" "$stage/$f";done<"$LAB_ROOT/migration-files.txt"; php artisan migrate --force --no-interaction --path="$stage" --realpath; php artisan migrate --force --no-interaction; php "$ROOT/tests/native/rc_mariadb_schema.php"; }
concurrency(){ export DB_DATABASE=taxnest_rc_migration; php "$ROOT/tests/native/rc_mariadb_concurrency.php"; }
di(){ printf 'DI lab is supplied by scripts/tests/di-fiscal-mariadb-check.sh; recovery harness intentionally not recertified after 11:04 UTC worktree loss.\n' >&2; return 2; }
mysql_skips(){ printf 'MariaDB skipped-test command is documented; recovery harness intentionally not recertified after 11:04 UTC worktree loss.\n' >&2; return 2; }
category(){ printf 'Category native-lab command is documented; recovery harness intentionally not recertified after 11:04 UTC worktree loss.\n' >&2; return 2; }
checks
case "$MODE" in all)full;upgrade;concurrency;;full)full;;upgrade)upgrade;;concurrency)concurrency;;di)di;;mysql-skips)mysql_skips;;category)category;;esac
printf 'PASS: MariaDB RC lab mode=%s completed\n' "$MODE"