#!/usr/bin/env bash
# Copy the database from the old server to the new one.
#
# --single-transaction gives a consistent snapshot of all 180 InnoDB tables
# without locking anything, so this can run while shops are billing. The dump
# is streamed straight into the new server's mysql — it is never written to
# disk on the old (quota-bound) host.
#
#   bash scripts/migration/04-sync-db.sh              # dump + restore
#   bash scripts/migration/04-sync-db.sh --dump-only  # keep a verified .sql.gz
#
# The old database is only ever read.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DUMP_ONLY=0
[ "${1:-}" = "--dump-only" ] && DUMP_ONLY=1
[ $DUMP_ONLY -eq 1 ] || need_dst

SRC_DB="$(src_env DB_DATABASE)"
[ -n "$SRC_DB" ] || die "could not read DB_DATABASE from the live .env"
say "Source database: $SRC_DB (MariaDB 10.11, 180 InnoDB tables)"

say "Staging the dump helper on the live server"
stage_tools src || die "could not stage tools on the live server"
ok "~/migration-tools ready (outside the app root)"

# One layer of quoting, in a real file on the far side. Building this inline
# through two shells is what mangled the password on the first attempt.
REMOTE_DUMP="bash ~/migration-tools/dumpwrap.sh '$SRC_APP' '$SRC_PHP'"

# ------------------------------------------------------------- backup only
if [ $DUMP_ONLY -eq 1 ]; then
  OUT="$WORK/${SRC_DB}-$STAMP.sql.gz"
  say "Dumping to $OUT"
  set -o pipefail
  src_ssh "$REMOTE_DUMP | gzip -6" > "$OUT" || die "dump failed (see the error above)"

  SZ=$(stat -c %s "$OUT" 2>/dev/null || stat -f %z "$OUT")
  [ "${SZ:-0}" -gt 1000000 ] || die "dump is only ${SZ:-0} bytes — that cannot be right"
  gzip -t "$OUT" || die "dump is a corrupt gzip stream"
  gunzip -c "$OUT" | tail -3 | grep -q 'Dump completed' \
    || die "dump has no 'Dump completed' trailer — it was truncated"

  TBL=$(gunzip -c "$OUT" | grep -c '^CREATE TABLE')
  ok "verified: $(human "$SZ") compressed, $TBL CREATE TABLE statements"
  sha256sum "$OUT" > "$OUT.sha256"
  printf '  %s\n' "$(cat "$OUT.sha256")"
  printf '\n  Keep a copy somewhere neither server controls.\n'
  exit 0
fi

# ---------------------------------------------------------------- restore
# The import runs into a freshly created schema, never on top of an existing
# one. A stream that dies half way then leaves an obviously partial database
# rather than a silent mixture of old and new tables — and the pre-restore
# dump beside it is the way back.
BACKUP_PATH="~/pre-restore-$STAMP.sql.gz"

say "Backing up whatever is already on the new server, then recreating the schema"
dst_ssh "set -e
  if mysql -N -e \"SHOW DATABASES LIKE '$DST_DB_NAME'\" | grep -q .; then
    n=\$(mysql -N -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DST_DB_NAME'\")
    if [ \"\$n\" -gt 0 ]; then
      mysqldump --single-transaction --no-tablespaces '$DST_DB_NAME' | gzip -6 > $BACKUP_PATH
      echo \"  existing \$n tables backed up to $BACKUP_PATH\"
    else
      echo '  destination database is empty'
    fi
    mysql -e \"DROP DATABASE \\\`$DST_DB_NAME\\\`\"
  fi
  mysql -e \"CREATE DATABASE \\\`$DST_DB_NAME\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"
  echo '  destination schema recreated, empty'" \
  || die "could not prepare the destination database"

say "Streaming the dump old -> new (91 MB; roughly a minute)"
# The NEW server pulls, so the bytes go server-to-server and never touch this
# workspace. FOREIGN_KEY_CHECKS off makes table order irrelevant.
if ! dst_ssh "set -o pipefail
  ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 -p '$SRC_PORT' \
      '$SRC_USER@$SRC_HOST' \"$REMOTE_DUMP | gzip -6\" \
  | gunzip \
  | mysql --default-character-set=utf8mb4 \
      --init-command='SET SESSION FOREIGN_KEY_CHECKS=0, SESSION UNIQUE_CHECKS=0' \
      '$DST_DB_NAME'"; then
  bad "restore failed — the destination schema is now INCOMPLETE"
  printf '  The old server is untouched; nothing is lost. Either:\n'
  printf '    re-run this script (it recreates the schema from scratch), or\n'
  printf '    put back what was there before:\n'
  printf '      gunzip -c %s | mysql %s\n' "$BACKUP_PATH" "$DST_DB_NAME"
  exit 1
fi
ok "restore finished"

say "Comparing table counts"
S=$(src_ssh "$SRC_PHP -d display_errors=1 ~/migration-tools/dbstat.php '$SRC_APP' --fast" | grep -c -v '^#')
D=$(dst_ssh "mysql -N -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DST_DB_NAME' AND table_type='BASE TABLE'\"")
printf '  source: %s tables\n  dest  : %s tables\n' "$S" "$D"
[ "$S" = "$D" ] || die "table count mismatch — do not proceed"
ok "table counts match"

printf '\n  Row-level proof comes from 05-verify.sh (CHECKSUM TABLE per table).\n'
