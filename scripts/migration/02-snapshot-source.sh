#!/usr/bin/env bash
# Snapshot the live server: a sha256 manifest of every payload file plus a
# per-table database fingerprint. This snapshot is the yardstick every later
# verification is measured against.
#
# Completely read-only. Safe while shops are billing.
#
#   bash scripts/migration/02-snapshot-source.sh

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

MAN="$WORK/manifest-source-$STAMP.txt"
DBF="$WORK/dbstat-source-$STAMP.txt"

say "Copying the measuring tools to the live server (outside the app root)"
stage_tools src || die "could not stage tools on the live server"
ok "tools staged in ~/migration-tools (delete any time; nothing in the app root is touched)"

say "Hashing payload files — a few minutes for ~6,300 files"
src_ssh "bash ~/migration-tools/manifest.sh $SRC_APP ${PAYLOAD_DIRS[*]}" > "$MAN" \
  || die "manifest generation failed"

FILES=$(wc -l < "$MAN")
BYTES=$(awk '{s+=$2} END {print s+0}' "$MAN")
[ "$FILES" -gt 0 ] || die "manifest is empty — refusing to treat that as a valid snapshot"
ok "$FILES files, $(human "$BYTES")"

say "Fingerprinting the database (row counts + CHECKSUM TABLE per table)"
src_ssh "$SRC_PHP -d display_errors=1 ~/migration-tools/dbstat.php $SRC_APP" > "$DBF" \
  || die "database fingerprint failed"

TBLS=$(grep -c -v '^#' "$DBF")
ROWS=$(grep -v '^#' "$DBF" | awk -F'\t' '{s+=$2} END {print s+0}')
[ "$TBLS" -gt 0 ] || die "database fingerprint is empty"
ok "$TBLS tables, $ROWS rows total"

cp "$MAN" "$WORK/manifest-source-latest.txt"
cp "$DBF" "$WORK/dbstat-source-latest.txt"

say "Snapshot saved"
printf '  files : %s\n  db    : %s\n  latest: %s\n' \
  "$MAN" "$DBF" "$WORK/{manifest,dbstat}-source-latest.txt"
printf '\n  Keep these. 05-verify.sh compares the new server against them, and a\n'
printf '  re-run before cutover shows exactly what changed in the meantime.\n'
