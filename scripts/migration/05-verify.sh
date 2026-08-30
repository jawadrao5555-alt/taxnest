#!/usr/bin/env bash
# Prove the new server holds exactly what the old one holds.
#
# This is the whole point of the exercise. It does not trust that rsync and
# mysqldump did their jobs — it re-reads both sides and compares:
#
#   1. every payload file, by sha256 and byte size
#   2. every database table, by row count and CHECKSUM TABLE
#
# Read-only on both hosts. Exits non-zero on any mismatch, and prints exactly
# which files or tables disagree.
#
#   bash scripts/migration/05-verify.sh            # while the old site is live
#   STRICT=1 bash scripts/migration/05-verify.sh   # during cutover: zero tolerance
#
# Without STRICT, differences confined to the churning tables (sessions, cache,
# jobs) are reported as warnings, because the old server is still serving and
# those tables move every second. At cutover the site is down and the workers
# are stopped, so STRICT=1 treats any difference at all as a failure.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
need_dst

STRICT="${STRICT:-0}"
TAB="$(printf '\t')"

# Tables that legitimately move while the old site is still serving.
VOLATILE_RE='^(sessions|cache|cache_locks|jobs|job_batches|failed_jobs)$'

SRC_MAN="$WORK/manifest-source-$STAMP.txt"
DST_MAN="$WORK/manifest-dest-$STAMP.txt"
SRC_DBF="$WORK/dbstat-source-$STAMP.txt"
DST_DBF="$WORK/dbstat-dest-$STAMP.txt"
PROBLEMS=0

say "Staging the measuring tools on both hosts"
stage_tools src || die "could not stage tools on the live server"
stage_tools dst || die "could not stage tools on the destination"

say "Hashing the source (it has kept running, so it may have moved on)"
src_ssh "bash ~/migration-tools/manifest.sh '$SRC_APP' ${PAYLOAD_DIRS[*]}" > "$SRC_MAN" \
  || die "source manifest failed"
[ -s "$SRC_MAN" ] || die "source manifest is empty — refusing to call that a match"
ok "$(wc -l < "$SRC_MAN") files on source"

say "Hashing the destination"
dst_ssh "bash ~/migration-tools/manifest.sh '$DST_APP' ${PAYLOAD_DIRS[*]}" > "$DST_MAN" \
  || die "destination manifest failed"
ok "$(wc -l < "$DST_MAN") files on destination"

say "FILES — comparing sha256 of every payload file"
MISSING="$WORK/diff-missing-$STAMP.txt"
DIFFER="$WORK/diff-content-$STAMP.txt"

# Manifests are TAB separated as <sha256>\t<size>\t<path>. Every step below
# stays tab-aware so a filename containing spaces is compared whole.
src_paths="$WORK/.src-paths-$STAMP"; dst_paths="$WORK/.dst-paths-$STAMP"
cut -f3 "$SRC_MAN" | LC_ALL=C sort > "$src_paths"
cut -f3 "$DST_MAN" | LC_ALL=C sort > "$dst_paths"

LC_ALL=C comm -23 "$src_paths" "$dst_paths" > "$MISSING"
EXTRA=$(LC_ALL=C comm -13 "$src_paths" "$dst_paths" | wc -l)

# Re-key both manifests as <path>\t<sha256> so join can match on the path.
LC_ALL=C join -t "$TAB" -j 1 -o 0,1.2,2.2 \
  <(awk -F'\t' -v OFS='\t' '{print $3, $1}' "$SRC_MAN" | LC_ALL=C sort -t "$TAB" -k1,1) \
  <(awk -F'\t' -v OFS='\t' '{print $3, $1}' "$DST_MAN" | LC_ALL=C sort -t "$TAB" -k1,1) \
  2>/dev/null | awk -F'\t' '$2 != $3 {print $1}' > "$DIFFER"

NM=$(wc -l < "$MISSING"); NC=$(wc -l < "$DIFFER")

if [ "$NM" -eq 0 ]; then ok "no missing files"; else
  bad "$NM file(s) missing on the destination — see $MISSING"
  head -10 "$MISSING" | sed 's/^/       /'
  PROBLEMS=$((PROBLEMS + 1)); fi

if [ "$NC" -eq 0 ]; then ok "no content mismatches"; else
  bad "$NC file(s) differ in content — see $DIFFER"
  head -10 "$DIFFER" | sed 's/^/       /'
  PROBLEMS=$((PROBLEMS + 1)); fi

# Extra files are not a failure: the old server keeps serving and generating
# PDFs while we work, so the destination can briefly lag, never lead.
[ "$EXTRA" -eq 0 ] && ok "no unexpected extra files" \
  || warn "$EXTRA extra file(s) on the destination (leftovers from an earlier pass)"

rm -f "$src_paths" "$dst_paths"

say "DATABASE — row counts and CHECKSUM TABLE per table"
src_ssh "$SRC_PHP -d display_errors=1 ~/migration-tools/dbstat.php '$SRC_APP'" > "$SRC_DBF" \
  || die "source db fingerprint failed"
dst_ssh "$DST_PHP -d display_errors=1 ~/migration-tools/dbstat.php '$DST_APP'" > "$DST_DBF" \
  || die "destination db fingerprint failed — is .env in place on the new server?"
[ -s "$SRC_DBF" ] || die "source db fingerprint is empty"

DBDIFF="$WORK/diff-db-$STAMP.txt"
LC_ALL=C join -t "$TAB" -a1 -a2 -e MISSING -o 0,1.2,1.3,2.2,2.3 \
  <(grep -v '^#' "$SRC_DBF" | LC_ALL=C sort -t "$TAB" -k1,1) \
  <(grep -v '^#' "$DST_DBF" | LC_ALL=C sort -t "$TAB" -k1,1) \
  | awk -F'\t' '$2 != $4 || $3 != $5 {printf "%s\t%s\t%s\t%s\t%s\n",$1,$2,$3,$4,$5}' \
  > "$DBDIFF"

TOTAL=$(grep -c -v '^#' "$SRC_DBF")
REAL=$(awk -F'\t' -v re="$VOLATILE_RE" '$1 !~ re' "$DBDIFF" | wc -l)
VOL=$(awk -F'\t' -v re="$VOLATILE_RE" '$1 ~ re' "$DBDIFF" | wc -l)

fmt_diff() {
  awk -F'\t' '{printf "       %-36s src rows=%-8s sum=%-12s | dst rows=%-8s sum=%s\n",$1,$2,$3,$4,$5}'
}

if [ "$REAL" -eq 0 ] && [ "$VOL" -eq 0 ]; then
  ok "all $TOTAL tables identical (rows + checksum)"
else
  if [ "$REAL" -gt 0 ]; then
    bad "$REAL of $TOTAL table(s) differ — see $DBDIFF"
    awk -F'\t' -v re="$VOLATILE_RE" '$1 !~ re' "$DBDIFF" | head -15 | fmt_diff
    PROBLEMS=$((PROBLEMS + 1))
  fi
  if [ "$VOL" -gt 0 ]; then
    if [ "$STRICT" = "1" ]; then
      bad "$VOL churning table(s) differ, and STRICT=1 allows none"
      awk -F'\t' -v re="$VOLATILE_RE" '$1 ~ re' "$DBDIFF" | head -10 | fmt_diff
      PROBLEMS=$((PROBLEMS + 1))
    else
      warn "$VOL churning table(s) differ (sessions/cache/jobs) — expected while the old site is live"
      awk -F'\t' -v re="$VOLATILE_RE" '$1 ~ re' "$DBDIFF" | head -6 | fmt_diff
    fi
  fi
fi

echo
if [ $PROBLEMS -eq 0 ]; then
  _c "1;32"; printf '  VERIFY PASSED — the new server is byte-for-byte complete.\n'; _c 0
  [ "$STRICT" = "1" ] || printf '  (Live keeps changing. Re-run with STRICT=1 at cutover.)\n'
  exit 0
fi
_c "1;31"; printf '  VERIFY FAILED — %s problem area(s). DO NOT CUT OVER.\n' "$PROBLEMS"; _c 0
printf '  Re-run 03-sync-files.sh / 04-sync-db.sh, then verify again.\n'
exit 1
