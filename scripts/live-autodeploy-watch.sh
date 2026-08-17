#!/bin/bash
# live-autodeploy-watch.sh — the REAL push-to-deploy trigger (Task 1053).
#
# WHY: the GitHub repo has NO webhook and cPanel's .cpanel.yml only runs on a
# manual "Pull or Deploy" click, so a plain `git push origin main` deployed
# NOTHING (Aug 17 2026: live got code via an ad-hoc pull, caches never rebuilt,
# every admin page 500'd). This watcher runs from the taxnestc user crontab
# every 5 minutes ON THE cPANEL BOX:
#
#   */5 * * * * /bin/bash /home/taxnestc/repositories/taxnest/scripts/live-autodeploy-watch.sh >> /home/taxnestc/.taxnest-autodeploy-watch.log 2>&1
#
# Behavior:
#   - fetch origin/main into the cPanel clone (/home/taxnestc/repositories/taxnest)
#   - if live HEAD == origin/main: no-op (single "up to date" line at most)
#   - if live is BEHIND (fast-forwardable): sync the clone to origin/main and
#     run the fail-closed scripts/cpanel-autodeploy.sh from it (maintenance
#     window + full cache rebuild + confirmed web OPcache reset; on failure the
#     site stays on the friendly 200 page — never a broken app)
#   - if live has DIVERGED from origin/main: touch NOTHING, flag loudly
#
# OBSERVABLE FAILURE SIGNAL: any failure writes
#   /home/taxnestc/.taxnest-autodeploy-FAILED   (reason + timestamp inside)
# which scripts/check-live-deploy.sh surfaces loudly from the workspace on
# every post-merge run. A successful deploy removes the marker.
#
# Everything is wrapped in main(){ }; main; exit — bash parses the whole file
# before executing, so the `git reset --hard` that updates THIS script's own
# clone mid-run cannot corrupt the running copy.

main() {
  set -u
  CLONE=/home/taxnestc/repositories/taxnest
  LIVE=/home/taxnestc/public_html
  FAILED_MARKER=/home/taxnestc/.taxnest-autodeploy-FAILED
  WATCHLOCK=/home/taxnestc/.taxnest-autodeploy-watch.lock

  ts() { date -u '+%Y-%m-%d %H:%M:%S UTC'; }
  flag_failed() {
    printf '%s\n%s\n' "$(ts)" "$1" > "$FAILED_MARKER"
    echo "[watch $(ts)] FAILED: $1" >&2
  }

  # One watcher at a time; a deploy in progress (autodeploy holds its own
  # deploy lock too) must not be raced by the next cron tick.
  exec 8>"$WATCHLOCK" || { echo "[watch $(ts)] cannot open watch lock" >&2; return 1; }
  flock -n 8 || return 0   # previous tick still running — silently skip

  cd "$CLONE" || { flag_failed "cannot cd $CLONE"; return 1; }

  if ! git fetch -q origin main 2>&1; then
    flag_failed "git fetch origin main failed in $CLONE (network/token?)"
    return 1
  fi
  REMOTE=$(git rev-parse origin/main 2>/dev/null) || { flag_failed "cannot resolve origin/main"; return 1; }
  LIVE_HEAD=$(git -C "$LIVE" rev-parse HEAD 2>/dev/null) || { flag_failed "cannot read live HEAD"; return 1; }

  if [ "$REMOTE" = "$LIVE_HEAD" ]; then
    # Up to date — stay quiet in the log except a heartbeat once per state.
    rm -f "$FAILED_MARKER" 2>/dev/null
    return 0
  fi

  if ! git merge-base --is-ancestor "$LIVE_HEAD" "$REMOTE" 2>/dev/null; then
    flag_failed "live HEAD $LIVE_HEAD has DIVERGED from origin/main $REMOTE — not deploying; reconcile per .agents/memory/cpanel-deployment.md"
    return 1
  fi

  echo "[watch $(ts)] live $LIVE_HEAD is behind origin/main $REMOTE — deploying"
  # Sync the clone worktree to exactly origin/main, then run the fail-closed
  # autodeploy from it (it copies to public_html inside a 200 maintenance
  # window, rebuilds ALL caches, confirms the web OPcache reset).
  if ! git reset --hard origin/main 2>&1; then
    flag_failed "git reset --hard origin/main failed in $CLONE"
    return 1
  fi
  # cpanel-autodeploy.sh stages/copies from the CLONE — its cwd (and its
  # BASH_SOURCE-derived fallback) must be the clone, never $HOME (cron default).
  cd "$CLONE" || { flag_failed "cannot re-enter $CLONE before deploy"; return 1; }
  if /bin/bash "$CLONE/scripts/cpanel-autodeploy.sh"; then
    echo "[watch $(ts)] deploy of $REMOTE SUCCEEDED"
    rm -f "$FAILED_MARKER" 2>/dev/null
    return 0
  else
    flag_failed "cpanel-autodeploy.sh FAILED deploying $REMOTE — site left on 200 maintenance page; recover: bash scripts/deploy-live.sh then 'php artisan up' on live"
    return 1
  fi
}
main
exit $?
