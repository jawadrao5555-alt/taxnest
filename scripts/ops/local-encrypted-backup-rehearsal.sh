#!/usr/bin/env bash
# Local-only encrypted backup rehearsal. It deliberately has no inputs for an
# application, database, network destination, or credentials.
set -euo pipefail
set +x
umask 077

DRY_RUN=1
LOCAL_SYNTHETIC=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --execute) DRY_RUN=0 ;;
        --local-synthetic) LOCAL_SYNTHETIC=1 ;;
        -h|--help)
            cat <<'USAGE'
Usage: bash scripts/ops/local-encrypted-backup-rehearsal.sh [--dry-run]
       bash scripts/ops/local-encrypted-backup-rehearsal.sh --local-synthetic --execute

The default is a no-write dry run. --execute is accepted only with
--local-synthetic and creates fixed synthetic records under a new temporary
directory. It never reads this repository's .env, storage, database, or network.
USAGE
            exit 0 ;;
        *) echo "ERROR: unsupported argument: $arg" >&2; exit 2 ;;
    esac
done

if [[ "$DRY_RUN" -eq 0 && "$LOCAL_SYNTHETIC" -ne 1 ]]; then
    echo "ERROR: --execute requires --local-synthetic; production data is never accepted here." >&2
    exit 2
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
    cat <<'PLAN'
DRY RUN — no files, database connections, credentials, or network calls.
Would create a new private temporary directory containing only three fixed
synthetic records and a migration marker; archive it with OpenSSL AES-256-CBC,
PBKDF2 and a random ephemeral key; decrypt into another temporary directory;
compare checksums, migration marker, and count.
Run with: bash scripts/ops/local-encrypted-backup-rehearsal.sh --local-synthetic --execute
PLAN
    exit 0
fi

command -v openssl >/dev/null 2>&1 || { echo "ERROR: OpenSSL is required; no rehearsal ran." >&2; exit 2; }
command -v tar >/dev/null 2>&1 || { echo "ERROR: tar is required; no rehearsal ran." >&2; exit 2; }
WORK="$(mktemp -d "${TMPDIR:-/tmp}/taxnest-backup-rehearsal.XXXXXX")"
trap 'rm -rf -- "$WORK"' EXIT HUP INT TERM
SOURCE="$WORK/source"
RESTORE="$WORK/restore"
TRANSFER="$WORK/transfer"
mkdir -p "$SOURCE" "$RESTORE" "$TRANSFER"

# These are intentionally not TaxNest-shaped customer records. Their sole
# purpose is to prove archive encryption and round-trip integrity.
cat >"$SOURCE/migration-state.txt" <<'EOF'
schema_version=synthetic-2026-09-16
environment=local-synthetic-only
EOF
cat >"$SOURCE/records.ndjson" <<'EOF'
{"fixture_id":"alpha","amount_minor":101}
{"fixture_id":"bravo","amount_minor":202}
{"fixture_id":"charlie","amount_minor":303}
EOF
(cd "$SOURCE" && sha256sum migration-state.txt records.ndjson | LC_ALL=C sort > checksums.sha256)
TAR="$WORK/synthetic-backup.tar"
ARCHIVE="$WORK/synthetic-backup.tar.enc"
MASTER_KEY="$WORK/ephemeral-master-key"
ENC_KEY="$WORK/encryption-key"
MAC_KEY="$WORK/authentication-key"
# Independent encryption/authentication keys are HMAC-derived from this
# ephemeral local-only master key and are removed with the temporary directory.
LC_ALL=C head -c 32 </dev/urandom >"$MASTER_KEY"
chmod 0600 "$MASTER_KEY"
printf 'taxnest-local-archive-encryption-v1' | openssl dgst -sha256 -mac HMAC -macopt "key:file:$MASTER_KEY" -binary >"$ENC_KEY"
printf 'taxnest-local-archive-authentication-v1' | openssl dgst -sha256 -mac HMAC -macopt "key:file:$MASTER_KEY" -binary >"$MAC_KEY"
chmod 0600 "$ENC_KEY" "$MAC_KEY"
tar -C "$SOURCE" -cf "$TAR" migration-state.txt records.ndjson checksums.sha256
openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -in "$TAR" -out "$ARCHIVE" -pass "file:$ENC_KEY"
openssl dgst -sha256 -mac HMAC -macopt "key:file:$MAC_KEY" -r "$ARCHIVE" | awk '{print $1}' >"$ARCHIVE.hmac"
verify_hmac() {
    local ciphertext="$1" expected actual
    expected="$(cat "$ciphertext.hmac")"
    [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || return 1
    actual="$(openssl dgst -sha256 -mac HMAC -macopt "key:file:$MAC_KEY" -r "$ciphertext" | awk '{print $1}')"
    [[ "$actual" == "$expected" ]]
}
# Simulate an independent recovery site: move only the encrypted archive, then
# remove every original synthetic record and plaintext tar before restoration.
TRANSFERRED_ARCHIVE="$TRANSFER/synthetic-backup.tar.enc"
mv -- "$ARCHIVE" "$TRANSFERRED_ARCHIVE"
mv -- "$ARCHIVE.hmac" "$TRANSFERRED_ARCHIVE.hmac"
rm -rf -- "$SOURCE" "$TAR"
[[ ! -e "$SOURCE" && ! -e "$TAR" ]] || {
    echo "ERROR: original synthetic backup material was not removed before restore." >&2
    exit 1
}
cp -- "$TRANSFERRED_ARCHIVE" "$WORK/tampered.tar.enc"
cp -- "$TRANSFERRED_ARCHIVE.hmac" "$WORK/tampered.tar.enc.hmac"
printf '\0' >>"$WORK/tampered.tar.enc"
if verify_hmac "$WORK/tampered.tar.enc"; then
    echo "ERROR: tampered ciphertext passed authentication." >&2
    exit 1
fi
verify_hmac "$TRANSFERRED_ARCHIVE" || { echo "ERROR: transferred ciphertext failed authentication." >&2; exit 1; }
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -salt -in "$TRANSFERRED_ARCHIVE" -out "$RESTORE/restored.tar" -pass "file:$ENC_KEY"
expected_members=(checksums.sha256 migration-state.txt records.ndjson)
mapfile -t members < <(tar -tf "$RESTORE/restored.tar" | LC_ALL=C sort)
[[ "$(printf '%s\n' "${members[@]}")" == "$(printf '%s\n' "${expected_members[@]}")" ]] || {
    echo "ERROR: decrypted archive has an unexpected, nested, or traversal member." >&2
    exit 1
}
tar -tvf "$RESTORE/restored.tar" | awk 'substr($1, 1, 1) != "-" { exit 1 }'
RESTORED="$RESTORE/payload"
mkdir -p "$RESTORED"
tar -C "$RESTORED" -xf "$RESTORE/restored.tar"
if find -P "$RESTORED" -xdev -type l -print -quit | grep -q .; then
    echo "ERROR: restored synthetic payload contains a symlink." >&2
    exit 1
fi
[[ "$(find -P "$RESTORED" -xdev -mindepth 1 -maxdepth 1 -type f -printf '%f\n' | LC_ALL=C sort)" == "$(printf '%s\n' "${expected_members[@]}")" ]] || {
    echo "ERROR: restored synthetic archive has files outside its exact expected locations." >&2
    exit 1
}
[[ -f "$RESTORED/checksums.sha256" ]] || {
    echo "ERROR: restored synthetic payload is incomplete." >&2
    exit 1
}
(cd "$RESTORED" && sha256sum -c checksums.sha256 >/dev/null)
[[ "$(grep -c '^schema_version=synthetic-2026-09-16$' "$RESTORED/migration-state.txt")" -eq 1 ]]
[[ "$(wc -l <"$RESTORED/records.ndjson")" -eq 3 ]]
[[ "$(cut -d'"' -f4 "$RESTORED/records.ndjson" | LC_ALL=C sort | tr '\n' ' ')" == "alpha bravo charlie " ]]

printf 'PASS: authenticated local synthetic AES-256-CBC/PBKDF2 backup/restore completed after transfer and source removal; archive_sha256=%s records=3 migration=synthetic-2026-09-16 tamper=detected\n' \
    "$(sha256sum "$TRANSFERRED_ARCHIVE" | awk '{print $1}')"