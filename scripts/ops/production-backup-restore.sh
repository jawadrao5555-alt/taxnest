#!/usr/bin/env bash
# OWNER-RUN ONLY: encrypted database backup and scratch-only restore rehearsal.
# Default is dry-run. No Cloud Agent should run this against a production host.
set -euo pipefail
set +x
umask 077

MODE=""
DRY_RUN=1
APP_ROOT=""
DB_DEFAULTS=""
DB_NAME=""
ARCHIVE=""
SCRATCH_DB=""
OFFSITE_TARGET=""
APPROVED=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/production-backup-restore.sh backup --app-root /absolute/taxnest \
    --db-name taxnest --db-defaults-file /absolute/private.cnf [--offsite-target remote:relative/path] [--dry-run]
  bash scripts/ops/production-backup-restore.sh restore --app-root /absolute/taxnest \
    --db-defaults-file /absolute/private.cnf --archive /absolute/backup.7z \
    --scratch-db taxnest_restore_rehearsal_YYYYMMDD [--dry-run]

Execution additionally requires both --execute and
--owner-approved-production-execution. The archive password is requested by
7-Zip from a terminal; it is never an argument, environment variable, log, or
file. Restore refuses the live database name and never drops a database.
USAGE
}

[[ $# -gt 0 ]] || { usage >&2; exit 2; }
MODE="$1"; shift
[[ "$MODE" == backup || "$MODE" == restore ]] || { echo "ERROR: mode must be backup or restore." >&2; exit 2; }
while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-root) APP_ROOT="${2:-}"; shift 2 ;;
        --db-defaults-file) DB_DEFAULTS="${2:-}"; shift 2 ;;
        --db-name) DB_NAME="${2:-}"; shift 2 ;;
        --archive) ARCHIVE="${2:-}"; shift 2 ;;
        --scratch-db) SCRATCH_DB="${2:-}"; shift 2 ;;
        --offsite-target) OFFSITE_TARGET="${2:-}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        --execute) DRY_RUN=0; shift ;;
        --owner-approved-production-execution) APPROVED=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "ERROR: unknown argument: $1" >&2; exit 2 ;;
    esac
done

[[ "$APP_ROOT" = /* && -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || { echo "ERROR: --app-root must be an existing absolute non-symlink path." >&2; exit 2; }
ROOT="$(cd "$APP_ROOT" && pwd -P)"
[[ -f "$ROOT/artisan" && -f "$ROOT/bootstrap/app.php" ]] || { echo "ERROR: app root is not Laravel." >&2; exit 2; }
[[ "$DB_DEFAULTS" = /* && -f "$DB_DEFAULTS" && ! -L "$DB_DEFAULTS" ]] || { echo "ERROR: --db-defaults-file must be a private absolute regular file." >&2; exit 2; }
[[ "$(stat -c %a "$DB_DEFAULTS")" =~ ^[0-6][0-0][0-0]$ ]] || { echo "ERROR: DB defaults file must not be group/world accessible." >&2; exit 2; }
[[ "$DRY_RUN" -eq 1 || "$APPROVED" -eq 1 ]] || { echo "ERROR: owner approval flag required for --execute." >&2; exit 2; }

find_7zip() {
    local tool
    for tool in 7zz 7z; do
        command -v "$tool" >/dev/null 2>&1 && "$tool" i 2>/dev/null | grep -q 7zAES && { command -v "$tool"; return; }
    done
    echo "ERROR: 7-Zip with AES-256 is required." >&2
    return 1
}
safe_name() { [[ "$1" =~ ^[A-Za-z0-9_]+$ ]]; }
capture_counts() {
    local database="$1" output="$2" table count
    : >"$output"
    while IFS= read -r table; do
        [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || { echo "ERROR: unexpected table identifier in source database." >&2; return 1; }
        count="$(mysql --defaults-file="$DB_DEFAULTS" -N -B "$database" -e "SELECT COUNT(*) FROM \`${table}\`")"
        [[ "$count" =~ ^[0-9]+$ ]] || { echo "ERROR: could not obtain exact row count for $table." >&2; return 1; }
        printf '%s\t%s\n' "$table" "$count" >>"$output"
    done < <(mysql --defaults-file="$DB_DEFAULTS" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='${database}' AND table_type='BASE TABLE' ORDER BY table_name")
}
backup_dir="$ROOT/storage/backups"
[[ -d "$backup_dir" && ! -L "$backup_dir" ]] || { echo "ERROR: exact backup directory is absent or a symlink: $backup_dir" >&2; exit 2; }

if [[ "$MODE" == backup ]]; then
    safe_name "$DB_NAME" || { echo "ERROR: --db-name must contain only letters, digits, underscores." >&2; exit 2; }
    [[ -z "$OFFSITE_TARGET" || "$OFFSITE_TARGET" =~ ^[A-Za-z0-9_-]+:[A-Za-z0-9._/-]+$ ]] || { echo "ERROR: offsite target must be a named rclone remote plus relative path." >&2; exit 2; }
    echo "Backup plan: database=$DB_NAME archive-dir=$backup_dir encrypted=7zAES headers=encrypted offsite=${OFFSITE_TARGET:-not-configured}"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "DRY RUN — no database, archive, password prompt, rclone, or network action. PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED"
        exit 0
    fi
    command -v mysqldump >/dev/null && command -v mysql >/dev/null || { echo "ERROR: mysql and mysqldump are required." >&2; exit 2; }
    sevenzip="$(find_7zip)"
    work="$(mktemp -d "$backup_dir/.backup-work.XXXXXX")"; trap 'rm -rf -- "$work"' EXIT
    dump="$work/database.sql.gz"; manifest="$work/backup-manifest.tsv"
    capture_counts "$DB_NAME" "$work/counts-before.tsv"
    mysqldump --defaults-file="$DB_DEFAULTS" --single-transaction --quick --routines --triggers --events "$DB_NAME" | gzip -c >"$dump"
    capture_counts "$DB_NAME" "$work/counts-after.tsv"
    cmp -s "$work/counts-before.tsv" "$work/counts-after.tsv" || {
        echo "ERROR: source row counts changed while the dump ran; refusing an unrehearsable archive." >&2
        exit 1
    }
    cp -- "$work/counts-after.tsv" "$manifest"
    printf '%s  database.sql.gz\n' "$(sha256sum "$dump" | awk '{print $1}')" >>"$manifest"
    archive="$backup_dir/taxnest-${DB_NAME}-$(date -u +%Y%m%dT%H%M%SZ).7z"
    [[ ! -e "$archive" ]] || { echo "ERROR: refusing to overwrite archive." >&2; exit 2; }
    [[ -t 0 ]] || { echo "ERROR: encryption password must be entered interactively." >&2; exit 2; }
    # Store only fixed archive-relative names. Absolute paths would make the
    # manifest checksum unusable after moving the archive to recovery storage.
    (cd "$work" && "$sevenzip" a -t7z -mhe=on -p -bb0 -- "$archive.part" database.sql.gz backup-manifest.tsv)
    "$sevenzip" t -bb0 -- "$archive.part"
    mv -n -- "$archive.part" "$archive"
    sha256sum "$archive" >"$archive.sha256"
    if [[ -n "$OFFSITE_TARGET" ]]; then
        command -v rclone >/dev/null || { echo "ERROR: archive remains local; rclone missing, no offsite copy attempted." >&2; exit 2; }
        rclone copyto -- "$archive" "$OFFSITE_TARGET/$(basename "$archive")"
        rclone copyto -- "$archive.sha256" "$OFFSITE_TARGET/$(basename "$archive.sha256")"
    fi
    echo "DONE: encrypted archive and checksum created. Record offsite retention evidence separately; this script never deletes local or remote backups."
    exit 0
fi

[[ "$ARCHIVE" = /* && -f "$ARCHIVE" && ! -L "$ARCHIVE" ]] || { echo "ERROR: --archive must be an existing absolute non-symlink file." >&2; exit 2; }
safe_name "$SCRATCH_DB" && [[ "$SCRATCH_DB" == taxnest_restore_rehearsal_* ]] || { echo "ERROR: scratch DB must start taxnest_restore_rehearsal_." >&2; exit 2; }
echo "Restore rehearsal plan: archive=$ARCHIVE scratch-db=$SCRATCH_DB (live DB is never named or dropped)"
if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "DRY RUN — no password prompt, database connection, extraction, database creation, or network action. PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED"
    exit 0
fi
command -v mysql >/dev/null || { echo "ERROR: mysql is required." >&2; exit 2; }
sevenzip="$(find_7zip)"
[[ -t 0 ]] || { echo "ERROR: archive password must be entered interactively." >&2; exit 2; }
work="$(mktemp -d "$backup_dir/.restore-work.XXXXXX")"; trap 'rm -rf -- "$work"' EXIT
"$sevenzip" t -bb0 -- "$ARCHIVE"
mapfile -t members < <("$sevenzip" l -slt -- "$ARCHIVE" | awk -F ' = ' '/^Path = / { print $2 }' | tail -n +2 | LC_ALL=C sort)
expected_members=(backup-manifest.tsv database.sql.gz)
[[ "$(printf '%s\n' "${members[@]}")" == "$(printf '%s\n' "${expected_members[@]}")" ]] || {
    echo "ERROR: archive must contain exactly the two fixed relative backup members." >&2
    exit 1
}
"$sevenzip" x -bb0 -o"$work" -- "$ARCHIVE"
if find -P "$work" -xdev -type l -print -quit | grep -q .; then
    echo "ERROR: extracted archive contains a symlink." >&2
    exit 1
fi
dump="$work/database.sql.gz"
manifest="$work/backup-manifest.tsv"
[[ -f "$dump" && ! -L "$dump" && -f "$manifest" && ! -L "$manifest" ]] || {
    echo "ERROR: archive lacks required regular backup members at fixed relative paths." >&2
    exit 1
}
[[ "$(find -P "$work" -xdev -mindepth 1 -maxdepth 1 -type f -printf '%f\n' | LC_ALL=C sort)" == "$(printf '%s\n' "${expected_members[@]}")" ]] || {
    echo "ERROR: extracted archive has unexpected files or member placement." >&2
    exit 1
}
(cd "$work" && grep -E '^[0-9a-f]{64}  database\.sql\.gz$' "$manifest" | sha256sum -c - >/dev/null)
exists="$(mysql --defaults-file="$DB_DEFAULTS" -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${SCRATCH_DB}'")"
[[ "$exists" == 0 ]] || { echo "ERROR: scratch database already exists; refusing reuse or deletion." >&2; exit 2; }
mysql --defaults-file="$DB_DEFAULTS" -e "CREATE DATABASE \`${SCRATCH_DB}\`"
gzip -dc -- "$dump" | mysql --defaults-file="$DB_DEFAULTS" "$SCRATCH_DB"
mysql --defaults-file="$DB_DEFAULTS" -N -B "$SCRATCH_DB" -e 'SELECT COUNT(*) FROM migrations' >/dev/null
while IFS=$'\t' read -r table expected; do
    [[ "$table" =~ ^[A-Za-z0-9_]+$ && "$expected" =~ ^[0-9]+$ ]] || continue
    actual="$(mysql --defaults-file="$DB_DEFAULTS" -N -B "$SCRATCH_DB" -e "SELECT COUNT(*) FROM \`${table}\`")"
    [[ "$actual" == "$expected" ]] || { echo "ERROR: row-count mismatch for $table (expected $expected, got $actual)." >&2; exit 1; }
done <"$manifest"
echo "DONE: scratch restore loaded; migration state and exact per-table row counts match. This script deliberately leaves the scratch database intact."