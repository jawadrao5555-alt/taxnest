#!/usr/bin/env bash
# Static contract test for the isolated MariaDB CI bootstrap.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/pr-checks.yml"
provision="$root/scripts/ci-mariadb-provision.sh"
browser_fixture_check="$root/scripts/tests/rc-browser-fixture-state-check.sh"

fail() {
    printf 'ci-mariadb-provision-check: %s\n' "$*" >&2
    exit 1
}

[[ -r "$workflow" ]] || fail 'active PR workflow is missing'
[[ -x "$provision" ]] || fail 'MariaDB provisioning helper is not executable'
[[ -x "$browser_fixture_check" ]] || fail 'browser fixture state regression check is not executable'

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
grep -Eq '^[[:space:]]+libargon2-1[[:space:]]' "$provision" ||
    fail 'PHP 8.4 runtime requires libargon2.so.1 in the minimal MariaDB container'
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

job_block() {
    # Bound evidence to the actual job, not an arbitrary line budget or the
    # following job's uploader. New acceptance steps can legitimately make a
    # lane longer without changing its always-uploaded evidence contract.
    awk -v job="$1" '
        $0 == "  " job ":" { inside = 1; next }
        inside && /^  [A-Za-z0-9_-]+:/ { exit }
        inside { print }
    ' "$2"
}

for lane in workflow-safety dependency-audit-build fullphpunit native-mariadb-di \
    browserdesktopmobile jsagentrealtime manifest-provenance; do
    # Consume the full pipe: grep -q can close early and SIGPIPE the producer.
    job_block "$lane" "$workflow" |
        grep -F 'actions/upload-artifact@v4' >/dev/null ||
        fail "$lane lacks its always-uploaded evidence artifact"
done

# The real extractor must accept an uploader beyond line 120, while refusing
# a missing uploader even when the next job has one. No false cross-job green.
{
    printf '  long-lane:\n'
    for ((i=0;i<130;i++)); do printf '      # synthetic acceptance line\n'; done
    printf '      uses: actions/upload-artifact@v4\n  missing-lane:\n      steps: []\n  following-lane:\n      uses: actions/upload-artifact@v4\n'
} >"$tmp/evidence-workflow.yml"
job_block long-lane "$tmp/evidence-workflow.yml" | grep -F 'actions/upload-artifact@v4' >/dev/null || fail 'long-job evidence extraction regressed'
if job_block missing-lane "$tmp/evidence-workflow.yml" | grep -F 'actions/upload-artifact@v4' >/dev/null; then
    fail 'evidence from another job was accepted'
fi
printf 'PASS: evidence extraction accepts long jobs and rejects next-job uploaders.\n'

python3 "$(dirname "${BASH_SOURCE[0]}")/rc-mariadb-discovery-check.py"
python3 "$(dirname "${BASH_SOURCE[0]}")/rc-mariadb-startup-check.py"
bash "$browser_fixture_check"
printf 'PASS: MariaDB image, ownership, version-order, and evidence contracts are present.\n'
