#!/usr/bin/env bash
# Provision the disposable MariaDB CI container without touching a runner
# installation.  The workflow supplies the MariaDB 10.6.23 image; this helper
# only installs the compiler/runtime prerequisites used by the checked-out
# application and verifies the image's binaries before any lab starts.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_MARIADB_VERSION="${RC_MARIADB_VERSION:-10.6.23}"
MODE="${1:-install}"

fail() {
    printf 'ci-mariadb-provision: %s\n' "$*" >&2
    exit 2
}

[[ "$EXPECTED_MARIADB_VERSION" =~ ^10\.6\.[0-9]+$ ]] ||
    fail 'RC_MARIADB_VERSION must be a MariaDB 10.6 patch version'

version_output() {
    local binary="$1" output status
    set +e
    output="$("$binary" --version 2>&1)"
    status=$?
    set -e
    ((status == 0)) || fail "$binary --version failed with exit $status: $output"
    printf '%s\n' "$output"
}

require_mariadb_106() {
    local server client output
    server="$(command -v mariadbd 2>/dev/null || true)"
    client="$(command -v mariadb 2>/dev/null || true)"
    [[ -n "$server" && -x "$server" ]] ||
        fail 'mariadbd is not available in the pinned job container'
    [[ -n "$client" && -x "$client" ]] ||
        fail 'mariadb client is not available in the pinned job container'
    [[ -x "$(command -v mariadb-admin 2>/dev/null || true)" ]] ||
        fail 'mariadb-admin is not available in the pinned job container'
    [[ -x "$(command -v mariadb-install-db 2>/dev/null || true)" ]] ||
        fail 'mariadb-install-db is not available in the pinned job container'

    # MariaDB prints the numeric version before the vendor name on Ubuntu
    # (10.6.23-MariaDB).  Match both tokens independently instead of relying
    # on the old, order-sensitive "MariaDB.*10.6" grep.
    output="$(version_output "$server")"
    [[ "$output" == *MariaDB* ]] ||
        fail "server is not MariaDB: $output"
    [[ "$output" =~ (^|[^0-9])${EXPECTED_MARIADB_VERSION//./\\.}([^0-9]|$) ]] ||
        fail "server version is not ${EXPECTED_MARIADB_VERSION}: $output"

    output="$(version_output "$client")"
    [[ "$output" == *MariaDB* ]] ||
        fail "client is not MariaDB: $output"
    [[ "$output" =~ (^|[^0-9])${EXPECTED_MARIADB_VERSION//./\\.}([^0-9]|$) ]] ||
        fail "client version is not ${EXPECTED_MARIADB_VERSION}: $output"

    printf 'MariaDB server: %s\nMariaDB client: %s\n' \
        "$(command -v mariadbd)" "$(command -v mariadb)"
    printf 'MariaDB version verified: %s (numeric token may precede MariaDB)\n' \
        "$EXPECTED_MARIADB_VERSION"
}

install_build_tools() {
    [[ "$(id -u)" == 0 ]] ||
        fail 'container provisioning must run as root'
    command -v apt-get >/dev/null 2>&1 ||
        fail 'apt-get is required in the pinned Ubuntu MariaDB container'

    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    # Deliberately do not install mariadb-server/mysql-server here.  The
    # official MariaDB image is the isolated server distribution, and every
    # RC script starts its own datadir and socket beneath /tmp.
    apt-get install -y --no-install-recommends \
        bash \
        build-essential \
        ca-certificates \
        curl \
        git \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libssl-dev \
        libxml2-dev \
        libzip-dev \
        pkg-config \
        python3 \
        sudo \
        unzip \
        xz-utils \
        zlib1g-dev
}

require_tool() {
    local tool="$1"
    command -v "$tool" >/dev/null 2>&1 ||
        fail "$tool is unavailable after container bootstrap"
}

verify_application_tools() {
    require_tool php
    require_tool composer
    if [[ "${RC_REQUIRE_NODE:-0}" == 1 ]]; then
        require_tool node
        require_tool npm
        [[ "$(node --version)" =~ ^v22\. ]] ||
            fail "Node 22 is required in this lane, got $(node --version)"
    fi
    printf 'PHP: %s\nComposer: %s\n' \
        "$(php --version | head -n 1)" "$(composer --version | head -n 1)"
    if [[ "${RC_REQUIRE_NODE:-0}" == 1 ]]; then
        printf 'Node: %s\nnpm: %s\n' "$(node --version)" "$(npm --version)"
    fi
}

case "$MODE" in
    install)
        install_build_tools
        require_mariadb_106
        ;;
    verify)
        require_mariadb_106
        verify_application_tools
        ;;
    *)
        fail "usage: $0 install|verify"
        ;;
esac