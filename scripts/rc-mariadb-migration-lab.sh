#!/usr/bin/env bash
# Disposable-only RC migration and native database labs. It uses no application
# .env, external endpoint, shared database, or browser-fixture port.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE=all
KEEP=0
for arg in "$@"; do
    case "$arg" in
        --all) MODE=all ;;
        --full) MODE=full ;;
        --upgrade) MODE=upgrade ;;
        --concurrency) MODE=concurrency ;;
        --di) MODE=di ;;
        --keep-running) KEEP=1 ;;
        *) printf 'rc-mariadb-migration-lab: unknown option %s\n' "$arg" >&2; exit 1 ;;
    esac
done

fail(){ printf 'rc-mariadb-migration-lab: %s\n' "$*" >&2; exit 1; }
RUN_ID="${RC_MARIADB_RUN_ID:-native_${UID}_$(date +%s)_$$_${RANDOM}}"
[[ "$RUN_ID" =~ ^[a-z0-9_]+$ ]] || fail 'RC_MARIADB_RUN_ID must contain only lowercase letters, digits, and underscores'
export RC_MARIADB_ROOT="$(realpath -m "${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-${RUN_ID}}")"
export RC_MARIADB_PORT="${RC_MARIADB_PORT:-33116}"
LAB_ROOT="$RC_MARIADB_ROOT"
SOCKET="$LAB_ROOT/run/mariadb.sock"
EVIDENCE_DIR="${RC_RECERTIFICATION_DIR:-$ROOT/.local/recertification/$RUN_ID}"
FULL_DB="taxnest_rc_${RUN_ID}_full"
UPGRADE_DB="taxnest_rc_${RUN_ID}_upgrade"
DI_DB="taxnest_rc_${RUN_ID}_di"

[[ "$LAB_ROOT" == /tmp/taxnest-rc-mariadb-* ]] || fail 'unsafe MariaDB root'
[[ "$RC_MARIADB_PORT" =~ ^[0-9]+$ ]] && ((RC_MARIADB_PORT >= 1024 && RC_MARIADB_PORT != 9000 && RC_MARIADB_PORT != 33117)) || fail 'unsafe MariaDB port'
for database in "$FULL_DB" "$UPGRADE_DB" "$DI_DB"; do [[ "$database" =~ ^taxnest_rc_[a-z0-9_]+$ ]] || fail 'unsafe database name'; done
mkdir -p "$EVIDENCE_DIR"
LOG="$EVIDENCE_DIR/mariadb-${MODE}.log"
exec > >(tee "$LOG") 2>&1

CTL="$ROOT/scripts/rc-mariadb-lab.sh"
GUARD="$(RC_NETWORK_GUARD_BUILD_DIR="$EVIDENCE_DIR/guard" bash "$ROOT/scripts/rc-network-build.sh")"
[[ -r "$GUARD" ]] || fail 'loopback-only egress guard could not be built'
export RC_MARIADB_LD_PRELOAD="$GUARD" RC_MARIADB_REQUIRE_EGRESS_GUARD=1
cleanup(){ ((KEEP)) || bash "$CTL" stop || true; }
trap cleanup EXIT
bash "$CTL" start
CLIENT="$(bash "$CTL" env | awk -F= '$1=="RC_MARIADB_CLIENT"{print substr($0,index($0,"=")+1)}')"
[[ -x "$CLIENT" ]] || fail 'MariaDB client unavailable'

clean_php() {
    env -i \
        PATH="$PATH" HOME="$(mktemp -d)" \
        APP_ENV=rc-mariadb APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' APP_DEBUG=false \
        DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$RC_MARIADB_PORT" DB_SOCKET="$SOCKET" DB_DATABASE="$DB_DATABASE" DB_USERNAME=root DB_PASSWORD='' DB_CONNECT_TIMEOUT=3 \
        HONOR_DATABASE_URL=0 CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=null \
        TAXNEST_RC_NO_EXTERNAL_FISCAL=1 FBR_API_URL='' FBR_SANDBOX_URL='' FBR_PRODUCTION_URL='' FBR_TOKEN='' PRA_API_URL='' PRA_SANDBOX_URL='' PRA_PRODUCTION_URL='' PRA_TOKEN='' \
        LD_PRELOAD="$GUARD" NO_PROXY='*' no_proxy='*' \
        RC_MARIADB_EVIDENCE_DIR="$EVIDENCE_DIR" \
        "$@"
}
mysql(){ env -i PATH="$PATH" HOME="$LAB_ROOT/home" TMPDIR="$LAB_ROOT/run" LANG=C LC_ALL=C TZ=UTC NO_PROXY='*' no_proxy='*' LD_PRELOAD="$GUARD" "$CLIENT" --protocol=socket --socket="$SOCKET" -uroot "$@"; }
reset(){
    [[ "$1" =~ ^taxnest_rc_[a-z0-9_]+$ ]] || fail unsafe-db
    mysql -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    export DB_DATABASE="$1"
}
checks(){
    find "$ROOT/database/migrations" -maxdepth 1 -name '*.php' -printf '%f\n' | LC_ALL=C sort >"$EVIDENCE_DIR/migration-files.txt"
    [[ "$(sort "$EVIDENCE_DIR/migration-files.txt" | uniq -d | wc -l)" == 0 ]] || fail duplicate-migrations
    awk '!/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_.+\.php$/{bad=1}END{exit bad}' "$EVIDENCE_DIR/migration-files.txt" || fail invalid-migration-name
    awk -F_ '{print $1"_"$2"_"$3"_"$4,$0}' "$EVIDENCE_DIR/migration-files.txt" | sort | awk '$1==p{print p" => "n" | "$2}{p=$1;n=$2}' >"$EVIDENCE_DIR/migration-timestamp-ties.txt"
    printf 'RUN_ID=%s\nPORT=%s\nROOT=%s\n' "$RUN_ID" "$RC_MARIADB_PORT" "$LAB_ROOT"
    printf 'MIGRATION_FILES=%s\nTIMESTAMP_TIES=%s\n' "$(wc -l <"$EVIDENCE_DIR/migration-files.txt")" "$(wc -l <"$EVIDENCE_DIR/migration-timestamp-ties.txt")"
}
full(){
    reset "$FULL_DB"
    clean_php php artisan migrate --force --no-interaction
    clean_php php "$ROOT/tests/native/rc_mariadb_schema.php"
    clean_php php artisan migrate --force --no-interaction | grep -q 'Nothing to migrate' || fail non-idempotent-full-migration
}
upgrade(){
    local stage="$LAB_ROOT/upgrade-prefix"
    reset "$UPGRADE_DB"
    rm -rf "$stage"; mkdir -p "$stage"
    while read -r f; do
        [[ "$f" < 2026_09_15_100000 ]] && ln -s "$ROOT/database/migrations/$f" "$stage/$f"
    done <"$EVIDENCE_DIR/migration-files.txt"
    clean_php php artisan migrate --force --no-interaction --path="$stage" --realpath
    clean_php php "$ROOT/tests/native/rc_mariadb_upgrade_fixture.php" seed
    clean_php php artisan migrate --force --no-interaction
    clean_php php "$ROOT/tests/native/rc_mariadb_upgrade_fixture.php" verify
    clean_php php "$ROOT/tests/native/rc_mariadb_schema.php"
    clean_php php artisan migrate --force --no-interaction | grep -q 'Nothing to migrate' || fail non-idempotent-upgrade-migration
}
concurrency(){
    export DB_DATABASE="$FULL_DB"
    clean_php php "$ROOT/tests/native/rc_mariadb_concurrency.php"
}
di(){
    reset "$DI_DB"
    clean_php php artisan migrate --force --no-interaction
    clean_php php "$ROOT/scripts/tests/di-fiscal-mariadb-check.php"
}
checks
case "$MODE" in
    all) full; upgrade; concurrency; di ;;
    full) full ;;
    upgrade) upgrade ;;
    concurrency) full; concurrency ;;
    di) di ;;
esac
printf 'PASS: MariaDB RC lab mode=%s completed; raw evidence=%s\n' "$MODE" "$EVIDENCE_DIR"