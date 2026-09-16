#!/usr/bin/env bash
# Disposable native MariaDB supplements for PHPUnit cases that SQLite must skip.
# Never reads an application .env or connects beyond the local lab socket/TCP listener.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_ID="${RC_MARIADB_SUPPLEMENTAL_RUN_ID:-supp_${UID}_$(date +%s)_$$_${RANDOM}}"
[[ "$RUN_ID" =~ ^[a-z0-9_]+$ ]] || {
    printf 'rc-mariadb-supplemental: run id must contain only lowercase letters, digits, and underscores\n' >&2
    exit 2
}
(( ${#RUN_ID} <= 40 )) || {
    printf 'rc-mariadb-supplemental: run id is too long\n' >&2
    exit 2
}
for forbidden in .env .env.local .env.production .env.backup; do
    [[ ! -e "$ROOT/$forbidden" ]] || {
        printf 'rc-mariadb-supplemental: refusing source tree containing %s\n' "$forbidden" >&2
        exit 2
    }
done
# A standalone proof must not bootstrap serialized application configuration,
# routes, or events from an earlier environment.
for cache in "$ROOT/bootstrap/cache/config.php" "$ROOT/bootstrap/cache/events.php" "$ROOT/bootstrap/cache/routes"*.php; do
    [[ ! -e "$cache" ]] || {
        printf 'rc-mariadb-supplemental: refusing source tree containing bootstrap cache %s\n' "$cache" >&2
        exit 2
    }
done

export RC_MARIADB_ROOT="$(realpath -m "${RC_MARIADB_ROOT:-/tmp/taxnest-rc-mariadb-${RUN_ID}}")"
export RC_MARIADB_PORT="${RC_MARIADB_PORT:-33116}"
[[ "$RC_MARIADB_ROOT" == /tmp/taxnest-rc-mariadb-* ]] || {
    printf 'rc-mariadb-supplemental: refusing root outside /tmp/taxnest-rc-mariadb-*\n' >&2
    exit 2
}
[[ ! -e "$RC_MARIADB_ROOT" ]] || {
    printf 'rc-mariadb-supplemental: refusing pre-existing MariaDB root\n' >&2
    exit 2
}
[[ "$RC_MARIADB_PORT" =~ ^[0-9]+$ ]] && (( RC_MARIADB_PORT >= 1024 && RC_MARIADB_PORT <= 65535 && RC_MARIADB_PORT != 9000 && RC_MARIADB_PORT != 33117 )) || {
    printf 'rc-mariadb-supplemental: unsafe port\n' >&2
    exit 2
}

EVIDENCE_DIR="${RC_RECERTIFICATION_DIR:-$ROOT/.local/recertification/$RUN_ID}"
EVIDENCE_DIR="$(realpath -m "$EVIDENCE_DIR")"
LOG="$EVIDENCE_DIR/mariadb-supplemental.log"
FBR_JUNIT="$EVIDENCE_DIR/fbr-kot-timestamp.junit.xml"
OWNER_JUNIT="$EVIDENCE_DIR/owner-approval-schema.junit.xml"
for artifact in "$LOG" "$FBR_JUNIT" "$OWNER_JUNIT"; do
    [[ ! -e "$artifact" ]] || {
        printf 'rc-mariadb-supplemental: refusing to overwrite prior evidence %s\n' "$artifact" >&2
        exit 2
    }
done
mkdir -p "$EVIDENCE_DIR/phpunit-home" "$EVIDENCE_DIR/compiled-views"
exec > >(tee "$LOG") 2>&1

# These identifiers are constructed only from the validated run id. The DDL below
# is intentionally limited to these two disposable databases and this loopback user.
PROBE_DB="taxnest_rc_${RUN_ID}_probe"
ODAR_DB="taxnest_odar_${RUN_ID}_schema"
PROBE_USER="tnrc_${RUN_ID}"
PROBE_PASSWORD="taxnest_rc_disposable_only"
for identifier in "$PROBE_DB" "$ODAR_DB" "$PROBE_USER"; do
    [[ "$identifier" =~ ^[a-z0-9_]+$ ]] && (( ${#identifier} <= 64 )) || {
        printf 'rc-mariadb-supplemental: unsafe generated identifier\n' >&2
        exit 2
    }
done

CTL="$ROOT/scripts/rc-mariadb-lab.sh"
GUARD="$(RC_NETWORK_GUARD_BUILD_DIR="$EVIDENCE_DIR/guard" bash "$ROOT/scripts/rc-network-build.sh")"
[[ -r "$GUARD" ]] || {
    printf 'rc-mariadb-supplemental: loopback-only egress guard could not be built\n' >&2
    exit 2
}
export RC_MARIADB_LD_PRELOAD="$GUARD" RC_MARIADB_REQUIRE_EGRESS_GUARD=1

MARIADB_BIN="${RC_MARIADB_BIN:-}"
if [[ -n "$MARIADB_BIN" ]]; then
    export RC_MARIADB_SERVER="${RC_MARIADB_SERVER:-$MARIADB_BIN/mariadbd}"
    export RC_MARIADB_CLIENT="${RC_MARIADB_CLIENT:-$MARIADB_BIN/mariadb}"
    export RC_MARIADB_ADMIN="${RC_MARIADB_ADMIN:-$MARIADB_BIN/mariadb-admin}"
    export RC_MARIADB_INSTALL_DB="${RC_MARIADB_INSTALL_DB:-$MARIADB_BIN/mariadb-install-db}"
fi

CLIENT="${RC_MARIADB_CLIENT:-}"
server_started=0
mysql_root() {
    env -i PATH="$PATH" HOME="$EVIDENCE_DIR/mysql-home" TMPDIR="$RC_MARIADB_ROOT/run" \
        LANG=C LC_ALL=C TZ=UTC NO_PROXY='*' no_proxy='*' LD_PRELOAD="$GUARD" \
        "$CLIENT" --protocol=socket --socket="$RC_MARIADB_ROOT/run/mariadb.sock" -uroot "$@"
}
cleanup() {
    local status=$?
    if (( server_started )); then
        # Static, allowlisted teardown for identifiers validated above; never accepts
        # a caller-provided database/table expression.
        mysql_root -e "DROP DATABASE IF EXISTS \`$PROBE_DB\`; DROP DATABASE IF EXISTS \`$ODAR_DB\`; DROP USER IF EXISTS '$PROBE_USER'@'127.0.0.1'; FLUSH PRIVILEGES;" || true
        env -i PATH="$PATH" HOME="$EVIDENCE_DIR/control-home" LANG=C LC_ALL=C TZ=UTC \
            NO_PROXY='*' no_proxy='*' LD_PRELOAD="$GUARD" \
            RC_MARIADB_ROOT="$RC_MARIADB_ROOT" RC_MARIADB_PORT="$RC_MARIADB_PORT" \
            RC_MARIADB_SERVER="${RC_MARIADB_SERVER:-}" RC_MARIADB_CLIENT="${RC_MARIADB_CLIENT:-}" \
            RC_MARIADB_ADMIN="${RC_MARIADB_ADMIN:-}" RC_MARIADB_INSTALL_DB="${RC_MARIADB_INSTALL_DB:-}" \
            RC_MARIADB_LD_PRELOAD="$GUARD" RC_MARIADB_REQUIRE_EGRESS_GUARD=1 \
            bash "$CTL" stop || true
    fi
    exit "$status"
}
trap cleanup EXIT

env -i PATH="$PATH" HOME="$EVIDENCE_DIR/control-home" LANG=C LC_ALL=C TZ=UTC \
    NO_PROXY='*' no_proxy='*' LD_PRELOAD="$GUARD" \
    RC_MARIADB_ROOT="$RC_MARIADB_ROOT" RC_MARIADB_PORT="$RC_MARIADB_PORT" \
    RC_MARIADB_SERVER="${RC_MARIADB_SERVER:-}" RC_MARIADB_CLIENT="${RC_MARIADB_CLIENT:-}" \
    RC_MARIADB_ADMIN="${RC_MARIADB_ADMIN:-}" RC_MARIADB_INSTALL_DB="${RC_MARIADB_INSTALL_DB:-}" \
    RC_MARIADB_LD_PRELOAD="$GUARD" RC_MARIADB_REQUIRE_EGRESS_GUARD=1 \
    bash "$CTL" start
server_started=1
CLIENT="$(env -i PATH="$PATH" HOME="$EVIDENCE_DIR/control-home" LANG=C LC_ALL=C TZ=UTC \
    NO_PROXY='*' no_proxy='*' LD_PRELOAD="$GUARD" \
    RC_MARIADB_ROOT="$RC_MARIADB_ROOT" RC_MARIADB_PORT="$RC_MARIADB_PORT" \
    RC_MARIADB_SERVER="${RC_MARIADB_SERVER:-}" RC_MARIADB_CLIENT="${RC_MARIADB_CLIENT:-}" \
    RC_MARIADB_ADMIN="${RC_MARIADB_ADMIN:-}" RC_MARIADB_INSTALL_DB="${RC_MARIADB_INSTALL_DB:-}" \
    RC_MARIADB_LD_PRELOAD="$GUARD" RC_MARIADB_REQUIRE_EGRESS_GUARD=1 \
    bash "$CTL" env | awk -F= '$1=="RC_MARIADB_CLIENT"{print substr($0,index($0,"=")+1)}')"
[[ -x "$CLIENT" ]] || {
    printf 'rc-mariadb-supplemental: MariaDB client unavailable\n' >&2
    exit 2
}

mysql_root -e "
    CREATE DATABASE IF NOT EXISTS \`$PROBE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE DATABASE IF NOT EXISTS \`$ODAR_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '$PROBE_USER'@'127.0.0.1' IDENTIFIED BY '$PROBE_PASSWORD';
    GRANT ALL PRIVILEGES ON \`$PROBE_DB\`.* TO '$PROBE_USER'@'127.0.0.1';
    GRANT ALL PRIVILEGES ON \`$ODAR_DB\`.* TO '$PROBE_USER'@'127.0.0.1';
    FLUSH PRIVILEGES;
"

env -i PATH="$PATH" HOME="$EVIDENCE_DIR/phpunit-home" LANG=C LC_ALL=C TZ=UTC CI=1 \
    NO_PROXY='*' no_proxy='*' LD_PRELOAD="$GUARD" \
    VIEW_COMPILED_PATH="$EVIDENCE_DIR/compiled-views" \
    TAXNEST_REQUIRE_MARIADB_SCHEMA=1 TAXNEST_REQUIRE_MARIADB_KOT_TIMESTAMP=1 \
    TAXNEST_MARIADB_TEST_HOST=127.0.0.1 TAXNEST_MARIADB_TEST_PORT="$RC_MARIADB_PORT" \
    TAXNEST_MARIADB_TEST_DATABASE="$PROBE_DB" TAXNEST_MARIADB_TEST_USERNAME="$PROBE_USER" \
    TAXNEST_MARIADB_TEST_PASSWORD="$PROBE_PASSWORD" TAXNEST_MARIADB_TEST_SOCKET='' \
    php "$ROOT/vendor/bin/phpunit" --testdox --log-junit "$FBR_JUNIT" \
        "$ROOT/tests/Feature/FbrPosKotReprintPermissionTest.php"

env -i PATH="$PATH" HOME="$EVIDENCE_DIR/phpunit-home" LANG=C LC_ALL=C TZ=UTC CI=1 \
    NO_PROXY='*' no_proxy='*' LD_PRELOAD="$GUARD" \
    VIEW_COMPILED_PATH="$EVIDENCE_DIR/compiled-views" \
    TAXNEST_REQUIRE_MARIADB_SCHEMA=1 TAXNEST_REQUIRE_MARIADB_KOT_TIMESTAMP=1 \
    TAXNEST_MARIADB_TEST_HOST=127.0.0.1 TAXNEST_MARIADB_TEST_PORT="$RC_MARIADB_PORT" \
    TAXNEST_MARIADB_TEST_DATABASE="$ODAR_DB" TAXNEST_MARIADB_TEST_USERNAME="$PROBE_USER" \
    TAXNEST_MARIADB_TEST_PASSWORD="$PROBE_PASSWORD" TAXNEST_MARIADB_TEST_SOCKET='' \
    php "$ROOT/vendor/bin/phpunit" --testdox --log-junit "$OWNER_JUNIT" \
        "$ROOT/tests/Feature/OwnerDeploymentApprovalMigrationTest.php" \
        "$ROOT/tests/Feature/OwnerDeploymentApprovalMariaDbSchemaTest.php"

for junit in "$FBR_JUNIT" "$OWNER_JUNIT"; do
    [[ -s "$junit" ]] || {
        printf 'rc-mariadb-supplemental: PHPUnit did not write JUnit evidence\n' >&2
        exit 1
    }
    if grep -q '<skipped' "$junit"; then
        printf 'rc-mariadb-supplemental: a required native supplemental probe was skipped\n' >&2
        exit 1
    fi
done
[[ "$(grep -o 'classname=\"Tests.Feature.OwnerDeploymentApprovalMariaDbSchemaTest\"' "$OWNER_JUNIT" | wc -l)" == 6 ]] || {
    printf 'rc-mariadb-supplemental: expected six executed owner-schema cases\n' >&2
    exit 1
}
grep -q 'name=\"test_the_claim_token_round_trips_through_a_mysql_timestamp_column\"' "$FBR_JUNIT" || {
    printf 'rc-mariadb-supplemental: required FBR timestamp probe is absent\n' >&2
    exit 1
}
printf 'PASS: native supplemental owner-schema=6 and fbr-kot-timestamp=1; raw evidence=%s\n' "$EVIDENCE_DIR"