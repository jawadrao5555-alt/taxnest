#!/usr/bin/env bash
# The cutover window. Run at 02:00-05:00 PKT, when no shop is billing.
#
# Refuses to do anything without --go. With --go it:
#   1. re-runs preflight
#   2. pauses the live queue workers (their cron), so nothing mutates mid-copy
#   3. puts the live site into maintenance mode
#   4. syncs the file delta and reloads the database
#   5. verifies both, and ABORTS if anything disagrees
#   6. prints the DNS/SPF checklist and the rollback command
#
# It deliberately does NOT touch DNS. That stays a human decision, and it is
# also the rollback lever — see the end of this script.
#
#   bash scripts/migration/06-cutover.sh            # rehearsal, changes nothing
#   bash scripts/migration/06-cutover.sh --go       # the real thing

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
need_dst

GO=0
[ "${1:-}" = "--go" ] && GO=1

BACKUP_CRON="\$HOME/crontab.pre-cutover-$STAMP"

if [ $GO -eq 0 ]; then
  say "REHEARSAL — nothing will change. Add --go to run for real."
  cat <<'PLAN'

  The window, step by step:

    1. preflight both hosts                             ~30s
    2. back up the live crontab, then pause all 21
       queue-worker / scheduler lines                   ~5s
    3. wait 60s for in-flight jobs to drain             60s
    4. php artisan down on the live site                ~5s
    5. final file delta (rsync, only what changed)      ~1-3m
    6. final database dump + restore (91 MB)            ~1-2m
    7. full verification: sha256 per file,
       CHECKSUM TABLE per table                         ~3-5m
    8. STOP. Report PASS/FAIL.

  Total with the site down: roughly 10-15 minutes.

  Then, by hand:
    - flip the Cloudflare origin A record to the new IP (proxied = instant)
    - update the mail / cpanel / webmail A records
    - update the SPF TXT record: ip4:66.29.138.229 -> the new IP
    - start the systemd queue workers on the new server
    - install the schedule:run cron on the new server
    - smoke test: POS login, one bill, receipt print, invoice PDF, day-close
    - php artisan up

  Rollback at any point: point the Cloudflare A record back at
  66.29.138.229 and restore the live crontab. Under a minute.

PLAN
  exit 0
fi

# ------------------------------------------------------------------ real run
say "Cutover starting — $(date -u '+%Y-%m-%d %H:%M UTC')"

say "Step 1/7 — preflight"
bash "$MIG_DIR/01-preflight.sh" >/dev/null 2>&1 || die "preflight failed; run it directly to see why"
ok "both hosts ready"

# Everything from here on can leave the old site down or its workers paused.
# The trap is the safety net: whatever ends this script — a failed step, Ctrl-C,
# a dropped terminal, a kill — the old server is put back the way it was, unless
# we reached the end successfully and deliberately disarmed it.
CUTOVER_OK=0
bring_back_up() {
  [ "$CUTOVER_OK" = "1" ] && return 0
  echo
  warn "restoring the OLD server (maintenance off, crontab back)"
  src_ssh "cd $SRC_APP && $SRC_PHP artisan up" >/dev/null 2>&1 || true
  src_ssh "[ -f $BACKUP_CRON ] && crontab $BACKUP_CRON" >/dev/null 2>&1 || true
  warn "old server is serving again. Nothing was lost."
}
trap bring_back_up EXIT
# A signal handler that only restores would let the script carry straight on to
# the next step — and eventually announce success — after Ctrl-C had already
# brought the old site back up. Restore, then actually stop.
trap 'echo; warn "interrupted"; bring_back_up; CUTOVER_OK=1; exit 130' INT TERM HUP

say "Step 2/7 — pausing live queue workers and the scheduler"
src_ssh "crontab -l > $BACKUP_CRON 2>/dev/null && crontab -l | sed 's/^/#CUTOVER /' | crontab - && echo paused" \
  || die "could not pause the crontab"
ok "crontab saved to $BACKUP_CRON and paused"

say "Step 3/7 — draining in-flight jobs"
# Actually wait for the workers to exit, rather than sleeping a fixed minute and
# hoping. A worker still alive here can commit a row after the final dump is
# taken, and that row would never reach the new server.
# Fail closed: an ssh probe that errors returns no output, and treating that as
# "zero workers" would wave through exactly the case we are guarding against.
worker_count() {
  local out
  out="$(src_ssh "pgrep -fc 'artisan queue:work' 2>/dev/null || echo 0")" \
    || die "cannot reach the old server to check its queue workers — refusing to continue"
  out="$(printf '%s' "$out" | tr -dc '0-9')"
  [ -n "$out" ] || die "unreadable worker count from the old server — refusing to continue"
  printf '%s' "$out"
}

DRAINED=0
for i in $(seq 1 36); do            # up to 3 minutes
  N="$(worker_count)"
  if [ "$N" -eq 0 ]; then DRAINED=1; ok "all queue workers have exited"; break; fi
  printf '\r  waiting for %s worker(s) to finish... %ss ' "$N" "$((i * 5))"
  sleep 5
done
echo
if [ $DRAINED -eq 0 ]; then
  warn "workers did not exit on their own — stopping them"
  src_ssh "pkill -f 'artisan queue:work'" >/dev/null 2>&1 || true
  sleep 5
  [ "$(worker_count)" -eq 0 ] || die "queue workers are still running — refusing to snapshot a moving database"
  ok "workers stopped"
fi

say "Step 4/7 — maintenance mode on the live site"
src_ssh "cd $SRC_APP && $SRC_PHP artisan down --retry=60" || die "artisan down failed"
ok "live site is in maintenance mode — the clock is running"

say "Step 5/7 — final file delta"
bash "$MIG_DIR/03-sync-files.sh" >/dev/null 2>&1 || die "file sync failed to start"
# Wait on the status the run itself writes. Polling for a live rsync process is
# wrong three ways: it is false before the first one spawns, false in the gap
# between per-directory runs, and false when the ssh probe merely fails.
bash "$MIG_DIR/03-sync-files.sh" --wait || die "file sync did not complete cleanly"
ok "file delta complete"

say "Step 6/7 — final database reload"
bash "$MIG_DIR/04-sync-db.sh" || die "database reload failed"
ok "database reloaded"

say "Step 7/7 — verification (STRICT: the site is down, so nothing may differ)"
STRICT=1 bash "$MIG_DIR/05-verify.sh" || die "VERIFICATION FAILED — investigate before retrying"

# Verified. From here the operator drives DNS by hand, so the old server stays
# exactly as it is — down and paused — until they say otherwise.
CUTOVER_OK=1
trap - EXIT INT TERM HUP

echo
_c "1;32"
cat <<EOF
  ============================================================
   CUTOVER READY — data is verified identical on the new server
  ============================================================
EOF
_c 0
cat <<EOF

  The OLD site is still in maintenance mode and its crontab is paused.
  Nothing is live on the new server yet. Now, by hand:

  1. Cloudflare -> DNS: change the A record for taxnest.com.pk
                        66.29.138.229  ->  $DST_HOST
     (proxied, so it applies instantly — no TTL wait)

  2. Cloudflare -> DNS: same for mail / cpanel / webmail / ftp
                        (these are DNS-only, so they DO wait for TTL)

  3. Cloudflare -> DNS: edit the SPF TXT record
                        remove  ip4:66.29.138.229
                        add     ip4:$DST_HOST

  4. On the new server: start the queue workers and the scheduler
       systemctl enable --now taxnest-queue@{default,zip,bulk}
       crontab: * * * * * $DST_PHP $DST_APP/artisan schedule:run >/dev/null 2>&1

  5. Smoke test on the new server before letting shops in:
       POS login, one test bill, receipt print, invoice PDF, day-close

  6. $DST_PHP $DST_APP/artisan up

  ROLLBACK (any time in the next month):
     - point the Cloudflare A record back at 66.29.138.229
     - ssh $SRC_USER@$SRC_HOST "crontab $BACKUP_CRON && cd $SRC_APP && $SRC_PHP artisan up"
     The old server is untouched and still holds every byte.

EOF
