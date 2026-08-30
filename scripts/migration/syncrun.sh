#!/usr/bin/env bash
# Runs ON the destination server. Pulls the payload directories from the old
# server with rsync and records a durable, honest completion status.
#
#   syncrun.sh <user@host> <port> <src-app> <dst-app> <src-rsync> <dir>...
#
# A real script rather than a nohup'd command string built through two layers
# of ssh quoting, and — more importantly — it reports a non-zero result when
# any directory fails. The earlier version logged failures and still exited 0,
# so the cutover could reload the database while files were missing.
#
# The status and log paths are decided here, not passed in: a path containing
# $HOME cannot survive being quoted through two shells, and it silently became
# a literal directory name rather than the home directory.

set -uo pipefail

SRC_TARGET="${1:?}"; SRC_PORT="${2:?}"; SRC_APP="${3:?}"
DST_APP="${4:?}";    SRC_RSYNC="${5:?}"
shift 5
[ $# -gt 0 ] || { echo "syncrun: no directories given" >&2; exit 2; }

STATUS="$HOME/taxnest-sync.status"
LOG="$HOME/taxnest-sync.log"

printf 'RUNNING\t%s\t%s\n' "$$" "$(date -u +%FT%TZ)" > "$STATUS"

# Any abnormal end still leaves a terminal status, so a watcher can never hang
# forever believing the run is still going.
trap 'printf "DONE\t99\t%s\tinterrupted\n" "$(date -u +%FT%TZ)" > "$STATUS"' INT TERM

rc=0
{
  echo "=== sync started $(date -u) ==="
  for d in "$@"; do
    echo "--- $d"
    mkdir -p "$DST_APP/$d" || { echo "!!! cannot create $DST_APP/$d"; rc=90; continue; }
    # No --delete: this never removes anything on the destination.
    rsync -az --partial --info=progress2,stats2 --human-readable \
      --exclude=.ftpquota --exclude=error_log --exclude='*.log' \
      --rsync-path="$SRC_RSYNC" \
      -e "ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 -p $SRC_PORT" \
      "$SRC_TARGET:$SRC_APP/$d/" "$DST_APP/$d/"
    r=$?
    if [ $r -ne 0 ]; then
      echo "!!! FAILED rc=$r : $d"
      rc=$r
    fi
  done
  echo "=== sync finished rc=$rc $(date -u) ==="
} >> "$LOG" 2>&1

printf 'DONE\t%s\t%s\n' "$rc" "$(date -u +%FT%TZ)" > "$STATUS"
exit "$rc"
