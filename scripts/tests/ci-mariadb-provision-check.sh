#!/usr/bin/env bash
# Static contract test for the isolated MariaDB CI bootstrap.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/pr-checks.yml"
provision="$root/scripts/ci-mariadb-provision.sh"

fail() {
    printf 'ci-mariadb-provision-check: %s\n' "$*" >&2
    exit 1
}

[[ -r "$workflow" ]] || fail 'active PR workflow is missing'
[[ -x "$provision" ]] || fail 'MariaDB provisioning helper is not executable'

grep -Fq 'mariadb:10.6.23-jammy@sha256:' "$workflow" ||
    fail 'native/browser jobs must use the pinned MariaDB 10.6.23 jammy image'
[[ "$(grep -Fc 'mariadb:10.6.23-jammy@sha256:' "$workflow")" == 2 ]] ||
    fail 'both MariaDB lanes must pin the image independently'
grep -Fq 'bash scripts/ci-mariadb-provision.sh install' "$workflow" ||
    fail 'workflow does not invoke the container provisioning helper'
grep -Fq 'bash scripts/ci-mariadb-provision.sh verify' "$workflow" ||
    fail 'workflow does not verify the container provisioning helper'
grep -Fq 'chown -R "$(id -u):$(id -g)" "$GITHUB_WORKSPACE"' "$workflow" ||
    fail 'container bootstrap must chown only GITHUB_WORKSPACE before checkout'
! grep -Eq 'safe\.directory[[:space:]]*[*]' "$workflow" ||
    fail 'workflow must not bypass Git ownership with safe.directory=*'
! grep -Eq 'apt(-get)? install[^|;&]*mariadb-(server|client)|apt(-get)? install[^|;&]*mysql-(server|client)' "$workflow" ||
    fail 'workflow must not replace a host MySQL installation with apt MariaDB'
! grep -Fq 'MariaDB.*10\\.6' "$workflow" ||
    fail 'workflow still contains the order-sensitive MariaDB version grep'
grep -Fq '10.6.23' "$provision" ||
    fail 'provisioning helper must verify the exact MariaDB 10.6.23 patch'
grep -Fq 'numeric token may precede MariaDB' "$provision" ||
    fail 'provisioning helper must document numeric-before-vendor output'

tmp="$(mktemp -d "${TMPDIR:-/tmp}/ci-mariadb-version-check.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT
for binary in mariadb mariadb-admin mariadb-install-db; do
    cat >"$tmp/$binary" <<'EOF'
#!/usr/bin/env bash
printf '10.6.23-MariaDB disposable version probe\n'
EOF
    chmod +x "$tmp/$binary"
done
cat >"$tmp/mariadbd" <<'EOF'
#!/usr/bin/env bash
printf '10.6.23-MariaDB disposable version probe\n'
exit 1
EOF
chmod +x "$tmp/mariadbd"
if RC_MARIADB_VERSION=10.6.23 PATH="$tmp:/usr/bin:/bin" \
    bash "$provision" verify >/dev/null 2>&1; then
    fail 'MariaDB version check accepted a matching --version output with exit 1'
fi

for lane in workflow-safety dependency-audit-build fullphpunit native-mariadb-di \
    browserdesktopmobile jsagentrealtime manifest-provenance; do
    grep -A120 "^  ${lane}:" "$workflow" |
        grep -Fq 'actions/upload-artifact@v4' ||
        fail "$lane lacks its always-uploaded evidence artifact"
done

python3 "$(dirname "${BASH_SOURCE[0]}")/rc-mariadb-discovery-check.py"
python3 "$(dirname "${BASH_SOURCE[0]}")/rc-mariadb-startup-check.py"
printf 'PASS: MariaDB image, ownership, version-order, and evidence contracts are present.\n'