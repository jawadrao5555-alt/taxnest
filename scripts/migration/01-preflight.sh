#!/usr/bin/env bash
# Preflight: can we migrate at all? Read-only on both hosts — safe to run any
# number of times, including while the site is live.
#
#   bash scripts/migration/01-preflight.sh            # both hosts
#   bash scripts/migration/01-preflight.sh --src-only # before the VPS exists

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

SRC_ONLY=0
[ "${1:-}" = "--src-only" ] && SRC_ONLY=1

FAILED=0
check() { if [ "$1" = 0 ]; then ok "$2"; else bad "$2"; FAILED=$((FAILED + 1)); fi; }

say "SOURCE — $SRC_USER@$SRC_HOST:$SRC_APP"

src_ssh true 2>/dev/null
check $? "ssh reachable"
[ $FAILED -eq 0 ] || die "cannot reach the live server; nothing else can be checked"

src_ssh "[ -d $SRC_APP/.git ]"; check $? "app root is a git checkout"
src_ssh "[ -r $SRC_APP/.env ]"; check $? ".env readable"
src_ssh "[ -x $SRC_RSYNC ]"
if [ $? -ne 0 ]; then
  warn "rsync missing at $SRC_RSYNC — build it once with:"
  printf '       ssh %s@%s "cd ~/tmp && curl -fsSLO https://download.samba.org/pub/rsync/src/rsync-3.2.7.tar.gz && tar xzf rsync-3.2.7.tar.gz && cd rsync-3.2.7 && ./configure --prefix=\$HOME --with-included-popt --with-included-zlib --disable-xxhash --disable-zstd --disable-lz4 --disable-openssl --disable-md2man && make -j8 && mkdir -p ~/bin && cp rsync ~/bin/"\n' "$SRC_USER" "$SRC_HOST"
  FAILED=$((FAILED + 1))
else
  ok "rsync present ($(src_ssh "$SRC_RSYNC --version 2>/dev/null | head -1"))"
fi
src_ssh "command -v mysqldump >/dev/null"; check $? "mysqldump present"
src_ssh "command -v sha256sum >/dev/null"; check $? "sha256sum present"
src_ssh "$SRC_PHP -v >/dev/null 2>&1";     check $? "php cli usable ($SRC_PHP)"

# Outbound SSH is what lets the new server pull directly instead of routing a
# gigabyte through this workspace.
src_ssh 'timeout 8 bash -c "cat < /dev/null > /dev/tcp/github.com/22"' 2>/dev/null
check $? "outbound port 22 open (direct server-to-server copy possible)"

say "SOURCE — payload inventory"
src_ssh "cd $SRC_APP && for d in ${PAYLOAD_DIRS[*]}; do
  if [ -d \"\$d\" ]; then printf '  %-32s %7s files  %8s\n' \"\$d\" \"\$(find \"\$d\" -type f | wc -l)\" \"\$(du -sh \"\$d\" | cut -f1)\";
  else printf '  %-32s MISSING\n' \"\$d\"; fi; done"

DBNAME="$(src_env DB_DATABASE)"
ok "database: $DBNAME"

if [ $SRC_ONLY -eq 1 ]; then
  say "Source-only preflight done ($FAILED problem(s))."
  exit $([ $FAILED -eq 0 ] && echo 0 || echo 1)
fi

say "DESTINATION — $DST_USER@$DST_HOST:$DST_APP"
need_dst

dst_ssh true 2>/dev/null; check $? "ssh reachable"
[ $FAILED -eq 0 ] || die "cannot reach the new server"

dst_ssh "command -v rsync >/dev/null";            check $? "rsync present"
dst_ssh "command -v mysql >/dev/null";            check $? "mysql client present"
dst_ssh "command -v sha256sum >/dev/null";        check $? "sha256sum present"
dst_ssh "$DST_PHP -v >/dev/null 2>&1";            check $? "php cli usable ($DST_PHP)"

# The extensions this app actually needs. zip and gd matter especially: on the
# old shared host they exist only under the web SAPI, and that trap has cost
# real hours — prove they work in the CLI here.
for ext in pdo_mysql mbstring bcmath intl curl xml fileinfo gd zip exif openssl; do
  dst_ssh "$DST_PHP -m | grep -qix $ext"
  check $? "php ext: $ext"
done

PHPV="$(dst_ssh "$DST_PHP -r 'echo PHP_VERSION;'" 2>/dev/null)"
case "$PHPV" in 8.2*|8.3*|8.4*) ok "php version $PHPV (composer.json wants ^8.2)";;
  *) bad "php version $PHPV — needs 8.2+"; FAILED=$((FAILED + 1));; esac

# The new box must be able to reach the old one, because it does the pulling.
dst_ssh "timeout 15 ssh -o BatchMode=yes -o StrictHostKeyChecking=no -p $SRC_PORT $SRC_USER@$SRC_HOST true" 2>/dev/null
if [ $? -eq 0 ]; then
  ok "new server can ssh to the old server (pull path ready)"
else
  warn "new server cannot ssh to the old server yet"
  printf '       On the NEW server:  ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519\n'
  printf '       Then append its ~/.ssh/id_ed25519.pub to %s@%s:~/.ssh/authorized_keys\n' "$SRC_USER" "$SRC_HOST"
  FAILED=$((FAILED + 1))
fi

# Payload is ~1 GB today; insist on real headroom for it plus the database and
# a dump sitting alongside.
AVAIL_KB="$(dst_ssh "df -Pk $(dirname "$DST_APP") | tail -1 | awk '{print \$4}'" 2>/dev/null)"
if [ -n "$AVAIL_KB" ] && [ "$AVAIL_KB" -gt 5000000 ]; then
  ok "free disk on destination: $((AVAIL_KB / 1024 / 1024)) GB"
else
  bad "free disk on destination too low (${AVAIL_KB:-?} KB); want 5 GB+"
  FAILED=$((FAILED + 1))
fi

MARIA="$(dst_ssh "mysql --version" 2>/dev/null)"
ok "db server: ${MARIA:-unknown}"
case "$MARIA" in
  *MariaDB*) ok "flavour matches the source (MariaDB 10.11)" ;;
  *)
    # Not a preference — proven by restoring a real dump into MySQL 8.0, where
    # 25 of 180 tables failed to load. push_subscriptions carries a UNIQUE key
    # over a TEXT column, which MariaDB implements as a "long unique index"
    # (USING HASH). MySQL has no such feature and rejects the CREATE TABLE with
    # error 1170, then everything after it in the dump is lost. Under MariaDB
    # the same dump restores all 180 tables with identical row counts.
    bad "destination is NOT MariaDB — the dump will not restore intact"
    printf '       %s\n' \
      "The source dump contains a MariaDB long unique index (USING HASH) on" \
      "push_subscriptions.endpoint. MySQL rejects it (error 1170) and abandons" \
      "the rest of the dump. Install MariaDB 10.11 on the new server."
    FAILED=$((FAILED + 1))
    ;;
esac

say "$([ $FAILED -eq 0 ] && echo 'Preflight PASSED — ready to snapshot.' || echo "Preflight found $FAILED problem(s).")"
exit $([ $FAILED -eq 0 ] && echo 0 || echo 1)
