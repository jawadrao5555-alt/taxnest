#!/bin/bash
# Shared production-deploy SHA guards. Sourced by Actions and static tests.
# NEVER SSH. NEVER print secrets.
#
# Layers (keep all of them):
#   1) checkout HEAD == requested SHA
#   2) requested SHA is in origin/main history (ancestor / non-rollback)
#   3) requested SHA == current origin/main TIP (stale-SHA fail-closed)
#
# Rollback of live is NOT this workflow. See deployment/ROLLBACK.md.

deploy_fail() {
  echo "" >&2
  echo "CI DEPLOY FAILED: $*" >&2
  return 1
}

deploy_require_full_sha() {
  local sha="${1:-}"
  case "$sha" in
    ""|*[!0-9a-fA-F]*)
      deploy_fail "deploy SHA missing or not hex"
      return 1
      ;;
  esac
  if [ "${#sha}" -ne 40 ]; then
    deploy_fail "deploy SHA must be a full 40-char commit ($sha has ${#sha} chars)"
    return 1
  fi
  return 0
}

deploy_require_checkout_matches() {
  local sha="${1:-}"
  local head
  head=$(git rev-parse HEAD 2>/dev/null) || {
    deploy_fail "cannot read checkout HEAD"
    return 1
  }
  if [ "$head" != "$sha" ]; then
    deploy_fail "checkout HEAD ($head) != requested SHA ($sha) — refuse to continue"
    return 1
  fi
  return 0
}

deploy_require_ref_is_main() {
  local event="${1:-}"
  local ref="${2:-}"
  if [ "$ref" != "refs/heads/main" ]; then
    deploy_fail "$event must run on refs/heads/main (got ${ref:-empty})"
    return 1
  fi
  return 0
}

deploy_fetch_origin_main() {
  git fetch origin main --prune \
    || { deploy_fail "git fetch origin main failed"; return 1; }
  git rev-parse --verify refs/remotes/origin/main >/dev/null 2>&1 \
    || { deploy_fail "cannot resolve refs/remotes/origin/main"; return 1; }
  return 0
}

deploy_origin_main_sha() {
  git rev-parse refs/remotes/origin/main
}

# Existing safety layer: SHA must be ON main history. Does not require tip.
deploy_require_on_main_history() {
  local sha="${1:-}"
  local origin_main
  origin_main=$(deploy_origin_main_sha) || return 1
  if ! git merge-base --is-ancestor "$sha" "$origin_main" 2>/dev/null; then
    deploy_fail "$sha is not in origin/main history — CI never deploys non-main commits"
    return 1
  fi
  echo "History guard OK — $sha is on origin/main"
  return 0
}

# New safety layer: SHA must be THE current origin/main tip.
# Ancestor-but-not-tip is rejected. This is not a substitute for (2).
deploy_require_origin_main_tip() {
  local sha="${1:-}"
  local origin_main
  origin_main=$(deploy_origin_main_sha) || return 1
  if [ "$sha" != "$origin_main" ]; then
    cat >&2 <<EOF

CI DEPLOY FAILED: requested SHA is not the current origin/main tip.
  requested:       $sha
  origin/main tip: $origin_main

A newer main commit exists. This run will NOT SSH, insert Elaan, or mutate
production. Dispatch Deploy Production for the current tip (Owner Merge &
Deploy handoff or workflow_dispatch target_sha=$origin_main).

Historical / rollback deploys are not done by this workflow.
Use deployment/ROLLBACK.md.

EOF
    return 1
  fi
  echo "Tip guard OK — $sha is the current origin/main tip"
  return 0
}

# Full local/Actions guard after checkout + fetch.
deploy_guard_main_tip() {
  local sha="${1:-}"
  local event="${2:-}"
  local ref="${3:-}"
  deploy_require_full_sha "$sha" || return 1
  deploy_require_checkout_matches "$sha" || return 1
  if [ -n "$event" ] || [ -n "$ref" ]; then
    deploy_require_ref_is_main "${event:-unknown}" "$ref" || return 1
  fi
  deploy_fetch_origin_main || return 1
  deploy_require_on_main_history "$sha" || return 1
  deploy_require_origin_main_tip "$sha" || return 1
  return 0
}
