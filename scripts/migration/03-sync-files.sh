#!/usr/bin/env bash
# Copy the payload files from the old server to the new one.
#
# The NEW server pulls, over its own ssh connection to the old one, running
# under nohup. That means: no gigabyte routed through this workspace, no tool
# timeout can kill it, and re-running only moves what changed.
#
#   bash scripts/migration/03-sync-files.sh            # start (or resume)
#   bash scripts/migration/03-sync-files.sh --status   # one-shot progress
#   bash scripts/migration/03-sync-files.sh --wait     # block until done, exit rc
#   bash scripts/migration/03-sync-files.sh --dry-run  # list what would move
#
# Completion is read from a status file the remote run writes, never from
# "is an rsync process alive" — that answer is wrong before the first process
# starts, between per-directory runs, and whenever ssh itself hiccups.
#
# Nothing on the old server is ever written or deleted.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
need_dst

MODE="${1:-run}"
STATUS_FILE="\$HOME/taxnest-sync.status"
LOG_FILE="\$HOME/taxnest-sync.log"

read_status() { dst_ssh "cat $STATUS_FILE 2>/dev/null || echo 'NONE'"; }

show_tail() { dst_ssh "tail -n ${1:-20} $LOG_FILE 2>/dev/null || echo '  (no log yet)'"; }

case "$MODE" in
  --status)
    say "Sync status on $DST_HOST"
    S="$(read_status)"
    printf '  %s\n\n' "$S"
    show_tail 20
    case "$S" in
      DONE*0*) exit 0 ;;
      NONE)    exit 3 ;;
      *)       exit 1 ;;
    esac
    ;;

  --wait)
    say "Waiting for the file sync to finish on $DST_HOST"
    for i in $(seq 1 360); do          # up to 60 minutes
      S="$(read_status)"
      case "$S" in
        DONE*)
          RC="$(printf '%s' "$S" | cut -f2)"
          if [ "$RC" = "0" ]; then ok "file sync completed cleanly"; exit 0; fi
          bad "file sync finished with rc=$RC"; show_tail 30; exit 1
          ;;
        NONE)
          # No status file at all after a grace period means it never started.
          [ "$i" -gt 6 ] && { bad "no sync has been started on $DST_HOST"; exit 3; }
          ;;
      esac
      sleep 10
    done
    bad "file sync still running after 60 minutes"; show_tail 30; exit 1
    ;;
esac

DRY=0
[ "$MODE" = "--dry-run" ] && DRY=1

say "Staging the sync helper on the new server"
stage_tools dst || die "could not stage tools on the destination"

say "Preparing the destination tree"
dst_ssh "mkdir -p $DST_APP && cd $DST_APP && mkdir -p ${PAYLOAD_DIRS[*]}" \
  || die "cannot prepare $DST_APP"

if [ $DRY -eq 1 ]; then
  say "DRY RUN — nothing will be written"
  for d in "${PAYLOAD_DIRS[@]}"; do
    printf '\n  --- %s\n' "$d"
    dst_ssh "rsync -azn --info=stats2 --human-readable \
      --exclude=.ftpquota --exclude=error_log --exclude='*.log' \
      --rsync-path='$SRC_RSYNC' \
      -e 'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -p $SRC_PORT' \
      '$SRC_USER@$SRC_HOST:$SRC_APP/$d/' '$DST_APP/$d/' | tail -12"
  done
  exit 0
fi

S="$(read_status)"
case "$S" in
  RUNNING*) die "a sync is already running on $DST_HOST — watch it with --status, or --wait" ;;
esac

say "Starting the pull in the background on $DST_HOST"
# Claim the status file synchronously, BEFORE spawning. Otherwise a DONE left
# by the previous run stays readable for the first moments of this one, and a
# waiter — including the cutover — reads that stale success and moves on to the
# database while this sync has barely started.
dst_ssh "printf 'RUNNING\tlauncher\t%s\n' \"\$(date -u +%Y-%m-%dT%H:%M:%SZ)\" > $STATUS_FILE" \
  || die "could not claim the status file on $DST_HOST"

dst_ssh "nohup bash ~/migration-tools/syncrun.sh \
    '$SRC_USER@$SRC_HOST' '$SRC_PORT' '$SRC_APP' '$DST_APP' '$SRC_RSYNC' \
    ${PAYLOAD_DIRS[*]} \
  >/dev/null 2>&1 < /dev/null & echo \"  started pid \$!\"" \
  || die "could not start the sync"

# Confirm it really came up, rather than reporting success for a run that died
# immediately. Any status now can only have been written by this invocation.
sleep 5
S="$(read_status)"
case "$S" in
  RUNNING*) ok "sync is under way" ;;
  DONE*)
    RC="$(printf '%s' "$S" | cut -f2)"
    [ "$RC" = "0" ] && ok "sync finished immediately (nothing left to copy)" \
                    || die "sync failed at once with rc=$RC — see $LOG_FILE on $DST_HOST"
    ;;
  *) die "sync did not start (status: $S)" ;;
esac

printf '\n  watch:  bash scripts/migration/03-sync-files.sh --status\n'
printf '  block:  bash scripts/migration/03-sync-files.sh --wait\n'
printf '  log:    %s on %s\n' "$LOG_FILE" "$DST_HOST"
printf '\n  Re-run this script any time; rsync moves only what changed.\n'
