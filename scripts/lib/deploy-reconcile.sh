# Shared lineage-divergence reconcile logic (Task 703).
# Sourced by scripts/deploy-live.sh, scripts/check-live-deploy.sh and
# scripts/tests/deploy-reconcile-check.sh — single source of truth.
#
# Background: platform task-merges commit to the workspace with NEW SHAs while
# content-identical commits already sit on origin/main. Lineage diverges,
# content does not. Manual reconcile pattern (done by hand 3x, last 14 Aug 2026):
#   1. verify origin/main has NO unique content — its TREE must be byte-identical
#      to the tree of SOME commit in workspace lineage
#      (git rev-parse origin/main^{tree} vs git log --format='%H %T')
#   2. git merge -s ours origin/main  (keeps workspace content verbatim, absorbs
#      origin lineage so origin/main becomes an ancestor)
#   3. deploy as usual
# These functions automate exactly that — and ONLY that. Any divergence where
# origin carries unique content, or live has drifted beyond origin, must stay
# a LOUD failure (never auto-overwrite).

# Fetch remote main into the EXACT tracking ref the classification reads.
# Plain `git fetch origin main` only updates FETCH_HEAD — refs/remotes/origin/main
# can stay STALE, poisoning every comparison below. The +refspec force-updates
# the tracking ref even when the update is not a fast-forward (the normal case
# during lineage divergence). Returns non-zero if the fetch or ref fails.
reconcile_fetch_origin_main() {
  git fetch origin "+main:refs/remotes/origin/main" || return 1
  git rev-parse --verify --quiet refs/remotes/origin/main >/dev/null || return 1
}

# reconcile_classify LOCAL_HEAD LIVE_HEAD
# Requires reconcile_fetch_origin_main to have run first.
# Prints ONE line on stdout:
#   ANCESTOR                — live HEAD is an ancestor of workspace HEAD (no divergence)
#   RECONCILABLE <commit>   — benign lineage divergence: live is at/behind origin/main
#                             AND origin/main's tree is byte-identical to workspace
#                             lineage commit <commit>; safe for `merge -s ours`
#   ORIGIN_UNIQUE           — origin/main carries content NOT in workspace lineage;
#                             NEVER auto-overwrite — manual reconcile required
#   LIVE_DRIFT              — live HEAD is neither origin/main nor an ancestor of it;
#                             live itself has drifted — investigate manually
reconcile_classify() {
  local LOCAL_HEAD=$1 LIVE_HEAD=$2 ORIGIN_MAIN ORIGIN_TREE MATCH_COMMIT
  ORIGIN_MAIN=$(git rev-parse refs/remotes/origin/main 2>/dev/null) || { echo "ERROR"; return 1; }
  if git merge-base --is-ancestor "$LIVE_HEAD" "$LOCAL_HEAD" 2>/dev/null; then
    echo "ANCESTOR"; return 0
  fi
  if [ "$LIVE_HEAD" != "$ORIGIN_MAIN" ] \
     && ! git merge-base --is-ancestor "$LIVE_HEAD" "$ORIGIN_MAIN" 2>/dev/null; then
    echo "LIVE_DRIFT"; return 0
  fi
  ORIGIN_TREE=$(git rev-parse "refs/remotes/origin/main^{tree}" 2>/dev/null) || { echo "ERROR"; return 1; }
  MATCH_COMMIT=$(git log --format='%H %T' "$LOCAL_HEAD" 2>/dev/null \
    | awk -v t="$ORIGIN_TREE" '$2==t {print $1; exit}')
  if [ -n "$MATCH_COMMIT" ]; then
    echo "RECONCILABLE $MATCH_COMMIT"
  else
    echo "ORIGIN_UNIQUE"
  fi
}

# reconcile_merge_ours MATCH_COMMIT
# Performs the `-s ours` merge. --allow-unrelated-histories: a platform
# restore/amend can orphan lineage entirely; safe here because -s ours keeps
# OUR tree verbatim and the caller already proved origin carries nothing unique.
reconcile_merge_ours() {
  local MATCH_COMMIT=$1
  git merge -s ours --allow-unrelated-histories refs/remotes/origin/main \
    -m "deploy: auto-reconcile diverged origin/main lineage (tree-identical to $MATCH_COMMIT; -s ours; deploy-live.sh Task 703)"
}
