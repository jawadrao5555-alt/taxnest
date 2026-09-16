#!/usr/bin/env bash
# Local-only regression proof for Phase B/J/K operational assets.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

for script in \
    scripts/ops/local-encrypted-backup-rehearsal.sh \
    scripts/ops/production-permissions-hardening.sh \
    scripts/ops/production-backup-restore.sh \
    scripts/ops/repository-artifact-guard.sh \
    scripts/ops/branch-equivalence-inventory.sh \
    scripts/ops/monitoring-readiness-guard.sh \
    scripts/ops/local-mariadb-recovery-rehearsal.sh; do
    bash -n "$script"
done
python3 -m json.tool docs/release-manifests/artifact-inventory.json >/dev/null
backup_script="$(cat scripts/ops/production-backup-restore.sh)"
grep -Fq '(cd "$work" && "$sevenzip" a -t7z -mhe=on -p -bb0 -- "$archive.part" database.sql.gz backup-manifest.tsv)' <<<"$backup_script"
grep -Fq 'expected_members=(backup-manifest.tsv database.sql.gz)' <<<"$backup_script"
grep -Fq 'extracted archive contains a symlink' <<<"$backup_script"
if grep -Fq '"$archive.part" "$dump" "$manifest"' <<<"$backup_script"; then
    echo "FAIL: production backup must not archive absolute dump/manifest paths" >&2
    exit 1
fi

dry="$(bash scripts/ops/local-encrypted-backup-rehearsal.sh)"
grep -q '^DRY RUN' <<<"$dry"
roundtrip="$(bash scripts/ops/local-encrypted-backup-rehearsal.sh --local-synthetic --execute)"
grep -q '^PASS: authenticated local synthetic AES-256-CBC/PBKDF2' <<<"$roundtrip"
grep -q 'tamper=detected' <<<"$roundtrip"

if bash scripts/ops/production-permissions-hardening.sh --app-root / >/dev/null 2>&1; then
    echo "FAIL: permission hardener accepted filesystem root" >&2
    exit 1
fi
if bash scripts/ops/production-backup-restore.sh backup >/dev/null 2>&1; then
    echo "FAIL: production backup accepted missing fail-closed inputs" >&2
    exit 1
fi
monitor="$(bash scripts/ops/monitoring-readiness-guard.sh)"
grep -q '^DRY RUN' <<<"$monitor"
echo "release-candidate-operations-check: PASS (local synthetic only; no production/network calls)"