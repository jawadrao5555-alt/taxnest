#!/bin/bash
# Regression test for scripts/lib/deploy-reconcile.sh (Task 703).
# Builds isolated git fixture repos in a temp dir and verifies:
#   1. STALE TRACKING REF: reconcile_fetch_origin_main force-updates
#      refs/remotes/origin/main even on a non-fast-forward change
#      (plain `git fetch origin main` would leave it stale).
#   2. TREE-IDENTICAL DIVERGENCE => RECONCILABLE + merge -s ours keeps
#      workspace content verbatim and makes origin/main an ancestor.
#   3. ORIGIN UNIQUE CONTENT => ORIGIN_UNIQUE (never auto-overwritten).
#   4. LIVE REAL DRIFT (live HEAD neither origin/main nor its ancestor)
#      => LIVE_DRIFT.
# Usage: bash scripts/tests/deploy-reconcile-check.sh   (exit 0 = all pass)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
LIB="$ROOT/scripts/lib/deploy-reconcile.sh"
[ -f "$LIB" ] || { echo "FAIL: $LIB missing"; exit 1; }

TMP=$(mktemp -d /tmp/deploy-reconcile-test.XXXXXX)
trap 'rm -rf "$TMP"' EXIT
FAILS=0
ok()   { echo "PASS: $*"; }
bad()  { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

gitq() { git -c user.email=t@t -c user.name=t "$@"; }

setup_repos() { # $1 = case dir; creates bare origin + ws clone with c1 pushed
  local D="$TMP/$1"
  mkdir -p "$D"
  gitq init -q --bare "$D/origin-repo"
  gitq init -q "$D/ws"
  ( cd "$D/ws" \
    && git config user.email t@t && git config user.name t \
    && gitq remote add origin "$D/origin-repo" \
    && echo v1 > f && gitq add f && gitq commit -qm c1 \
    && gitq push -q origin HEAD:main \
    && gitq fetch -q origin "+main:refs/remotes/origin/main" )
  echo "$D"
}

# ---------------------------------------------------------------- Case 1: stale tracking ref
D=$(setup_repos case1)
(
  cd "$D/ws"
  # Advance origin main from a SECOND clone (amend => non-fast-forward vs c1? no —
  # simplest non-FF: force-push an amended root from the other clone).
  gitq clone -q -b main "$D/origin-repo" "$D/other" 2>/dev/null
  ( cd "$D/other" && gitq commit -q --amend -m c1-rewritten && gitq push -qf origin HEAD:main )
  STALE=$(git rev-parse refs/remotes/origin/main)
  . "$LIB"
  reconcile_fetch_origin_main >/dev/null 2>&1 || { echo "FETCHFAIL"; exit 1; }
  FRESH=$(git rev-parse refs/remotes/origin/main)
  REMOTE=$(git ls-remote origin main | awk '{print $1}')
  [ "$FRESH" = "$REMOTE" ] && [ "$FRESH" != "$STALE" ] && echo "CASE1_OK"
) | grep -q CASE1_OK && ok "stale tracking ref force-updated to remote main (non-FF)" \
  || bad "stale tracking ref NOT updated — classification would use obsolete origin/main"

# ------------------------------------------- Case 2: tree-identical divergence => reconcile
D=$(setup_repos case2)
(
  cd "$D/ws"
  # Platform-style re-merge: workspace history rewritten (new SHA, same content) + new work.
  gitq commit -q --amend -m c1-remerged
  echo v2 > f && gitq commit -qam c2
  . "$LIB"
  reconcile_fetch_origin_main >/dev/null 2>&1 || exit 1
  LIVE_HEAD=$(git rev-parse refs/remotes/origin/main)   # live sits at origin/main
  CL=$(reconcile_classify "$(git rev-parse HEAD)" "$LIVE_HEAD")
  case "$CL" in RECONCILABLE\ *) : ;; *) echo "BADCLASS:$CL"; exit 1 ;; esac
  reconcile_merge_ours "${CL#RECONCILABLE }" >/dev/null 2>&1 || { echo MERGEFAIL; exit 1; }
  git merge-base --is-ancestor refs/remotes/origin/main HEAD || { echo NOTANCESTOR; exit 1; }
  [ "$(cat f)" = v2 ] || { echo CONTENTLOST; exit 1; }
  echo "CASE2_OK"
) | grep -q CASE2_OK && ok "tree-identical divergence => RECONCILABLE; merge -s ours keeps content, origin becomes ancestor" \
  || bad "tree-identical divergence did not reconcile correctly"

# --------------------------------------------- Case 3: origin unique content => refuse
D=$(setup_repos case3)
(
  cd "$D/ws"
  gitq commit -q --amend -m c1-remerged
  # origin gains a commit whose CONTENT the workspace never had
  gitq clone -q -b main "$D/origin-repo" "$D/other" 2>/dev/null
  ( cd "$D/other" && echo unique > g && gitq add g && gitq commit -qm unique && gitq push -q origin HEAD:main )
  . "$LIB"
  reconcile_fetch_origin_main >/dev/null 2>&1 || exit 1
  CL=$(reconcile_classify "$(git rev-parse HEAD)" "$(git rev-parse refs/remotes/origin/main)")
  [ "$CL" = "ORIGIN_UNIQUE" ] && echo "CASE3_OK" || echo "BADCLASS:$CL"
) | grep -q CASE3_OK && ok "origin unique content => ORIGIN_UNIQUE (refuse, never overwrite)" \
  || bad "origin unique content was NOT classified ORIGIN_UNIQUE"

# --------------------------------------------- Case 4: live real drift => refuse
D=$(setup_repos case4)
(
  cd "$D/ws"
  gitq commit -q --amend -m c1-remerged
  # Live HEAD = commit that exists nowhere origin/main explains (unknown SHA).
  DRIFT=$(gitq commit-tree "HEAD^{tree}" -m drift < /dev/null)
  . "$LIB"
  reconcile_fetch_origin_main >/dev/null 2>&1 || exit 1
  CL=$(reconcile_classify "$(git rev-parse HEAD)" "$DRIFT")
  [ "$CL" = "LIVE_DRIFT" ] && echo "CASE4_OK" || echo "BADCLASS:$CL"
) | grep -q CASE4_OK && ok "live real drift => LIVE_DRIFT (refuse)" \
  || bad "live real drift was NOT classified LIVE_DRIFT"

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "deploy-reconcile-check: ALL 4 CASES PASS"
  exit 0
else
  echo "deploy-reconcile-check: $FAILS case(s) FAILED" >&2
  exit 1
fi
