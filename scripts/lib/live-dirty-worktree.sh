#!/bin/bash
# Classify live tracked dirtiness before exact-SHA checkout.
#
# Sourced by:
#   - scripts/ci-deploy-production.sh
#   - scripts/deploy-live.sh
#
# Does NOT stash, reset, checkout, or clean. Caller must define fail().
# Required globals: HOST, SSH_OPTS[], LIVE_DIR
#
# Expected leftover from PR #28 / live-remote-apply.sh: working-tree-only
# public/sw.js CACHE_VERSION stamp. That is allowed. Any other tracked
# modification still fail-closes.

_LIVE_DIRTY_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
_LIVE_DIRTY_CLASSIFY="$_LIVE_DIRTY_ROOT/scripts/lib/live-dirty-worktree-classify.py"

# SSH to live, classify, return 0 to continue or 2 to fail closed.
# Prints human-readable status. Does not mutate the live tree.
live_dirty_worktree_preflight() {
  local PORCELAIN SW_DIFF PFILE DFILE RC VERDICT TRACKED

  if [ ! -f "$_LIVE_DIRTY_CLASSIFY" ]; then
    echo "live-dirty-worktree-classify.py missing" >&2
    return 2
  fi

  timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" \
    "cd '$LIVE_DIR' && git config core.fileMode false" >/dev/null 2>&1 || true

  PORCELAIN=$(timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" "LIVE_DIR='$LIVE_DIR' bash -s" <<'DIRTYCHECK' 2>/dev/null
set -u
cd "$LIVE_DIR" || exit 1
git status --porcelain
DIRTYCHECK
)
  RC=$?
  if [ "$RC" -ne 0 ]; then
    echo "cannot read live git status (ssh/timeout exit $RC)" >&2
    return 2
  fi

  PFILE=$(mktemp)
  DFILE=$(mktemp)
  printf '%s\n' "$PORCELAIN" > "$PFILE"

  RC=0
  VERDICT=$(python3 "$_LIVE_DIRTY_CLASSIFY" --porcelain-file "$PFILE" 2>/dev/null) || RC=$?

  if [ "$RC" -eq 3 ] || [ "$VERDICT" = "NEED_SW_DIFF" ]; then
    SW_DIFF=$(timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" "LIVE_DIR='$LIVE_DIR' bash -s" <<'SWDIFF' 2>/dev/null
set -u
cd "$LIVE_DIR" || exit 1
git diff HEAD -- public/sw.js
SWDIFF
)
    RC=$?
    if [ "$RC" -ne 0 ]; then
      rm -f "$PFILE" "$DFILE"
      echo "cannot read live git diff HEAD -- public/sw.js (ssh/timeout exit $RC)" >&2
      return 2
    fi
    printf '%s\n' "$SW_DIFF" > "$DFILE"
    RC=0
    VERDICT=$(python3 "$_LIVE_DIRTY_CLASSIFY" --porcelain-file "$PFILE" --sw-diff-file "$DFILE") || RC=$?
  fi

  TRACKED=$(printf '%s\n' "$PORCELAIN" | grep -v '^??' | grep -v '^$' || true)
  rm -f "$PFILE" "$DFILE"

  case "$VERDICT" in
    CLEAN)
      echo "Live worktree: no modified tracked files."
      return 0
      ;;
    EXPECTED_SW_STAMP)
      echo "Live worktree: public/sw.js has the expected PWA CACHE_VERSION stamp only (working-tree, not committed). Allowing; remote_apply restores then restamps. Not auto-stashing."
      return 0
      ;;
  esac

  echo "Live worktree has MODIFIED tracked files:" >&2
  printf '%s\n' "$TRACKED" | head -20 >&2
  if [ "$VERDICT" = "UNEXPECTED_SW_JS" ]; then
    echo "public/sw.js diff is NOT solely the live-remote-apply CACHE_VERSION stamp." >&2
    echo "Owner/ops must inspect live: git diff HEAD -- public/sw.js" >&2
    echo "Do not reset, stash, or checkout the live tree until that diff is understood." >&2
  fi
  return 2
}
