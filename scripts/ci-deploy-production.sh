#!/bin/bash
# CI-safe production deploy for TaxNest (GitHub Actions → Nayatel VPS).
#
# Deploys ONE already-merged GitHub main commit over SSH. This script:
#   - NEVER pushes to main (or any branch)
#   - NEVER uses the Replit key at .local/ssh/nayatel_vps_key
#   - NEVER reads or prints production .env / DB / mail / FBR secrets
#   - Skips Replit-local preflights (MySQL staging, Chromium, .local QA)
#   - TARGET_SHA must be the current origin/main TIP (stale SHA fail-closed
#     before any SSH). Ancestor-only is not enough. Rollback is ROLLBACK.md.
#
# Required:
#   TARGET_SHA                 — exact commit to place on live (usually github.sha)
#   PRODUCTION_SSH_PRIVATE_KEY — private key for taxnest-production-deploy
#                                (GitHub Environment secret; never commit it)
#   OR LIVE_SSH_KEY            — path to an already-written key file (tests)
#
# Optional:
#   NO_ELAAN=1 / --no-elaan
#   ALLOW_SETTINGS / --allow-settings=a,b
#   deploy/elaan.yml — committed What's New spec; inserted on live after SSH
#                      (idempotent by title; never re-dates) then the freshness
#                      gate runs. NEW SHA needs a time-fresh row; SAME-SHA
#                      rerun may pass when marker commit == TARGET_SHA and the
#                      committed title still exists as published pos/all.
#
# Usage (Actions):
#   TARGET_SHA="$GITHUB_SHA" bash scripts/ci-deploy-production.sh
#
set -uo pipefail

NO_ELAAN=0
# Preserve env ALLOW_SETTINGS from the workflow, then allow CLI override.
ALLOW_SETTINGS="${ALLOW_SETTINGS:-}"
TARGET_SHA="${TARGET_SHA:-}"

for _arg in "$@"; do
  case "$_arg" in
    --no-elaan) NO_ELAAN=1 ;;
    --allow-settings=*) ALLOW_SETTINGS="${_arg#--allow-settings=}" ;;
    --target-sha=*) TARGET_SHA="${_arg#--target-sha=}" ;;
  esac
done

if [ "${SKIP_ELAAN:-}" = "true" ] || [ "${SKIP_ELAAN:-}" = "1" ]; then
  NO_ELAAN=1
fi

case "$ALLOW_SETTINGS" in
  *[!a-zA-Z0-9_,]*) echo "Invalid --allow-settings (letters, digits, _ and , only)" >&2; exit 1 ;;
esac

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

fail() { echo ""; echo "CI DEPLOY FAILED: $*" >&2; exit 1; }
step() { echo ""; echo "==> $*"; }

# --------------------------------------------------------------------------- key material
# Prefer the GitHub Environment secret. Never fall back to the Replit key path.
KEY_TMPDIR=""
cleanup_key() {
  if [ -n "$KEY_TMPDIR" ] && [ -d "$KEY_TMPDIR" ]; then
    rm -rf "$KEY_TMPDIR"
  fi
}
trap cleanup_key EXIT

if [ -n "${PRODUCTION_SSH_PRIVATE_KEY:-}" ]; then
  KEY_TMPDIR=$(mktemp -d)
  chmod 700 "$KEY_TMPDIR"
  umask 077
  # Write key without echoing. Ensure a trailing newline (OpenSSH requires it).
  printf '%s\n' "$PRODUCTION_SSH_PRIVATE_KEY" > "$KEY_TMPDIR/taxnest-production-deploy"
  chmod 600 "$KEY_TMPDIR/taxnest-production-deploy"
  export LIVE_SSH_KEY="$KEY_TMPDIR/taxnest-production-deploy"
elif [ -n "${LIVE_SSH_KEY:-}" ] && [ -f "${LIVE_SSH_KEY}" ]; then
  case "$LIVE_SSH_KEY" in
    */.local/ssh/nayatel_vps_key|*/nayatel_vps_key)
      fail "refusing Replit key path ($LIVE_SSH_KEY) — CI must use taxnest-production-deploy via PRODUCTION_SSH_PRIVATE_KEY"
      ;;
  esac
else
  fail "PRODUCTION_SSH_PRIVATE_KEY is not set (GitHub Environment secret 'production') and no safe LIVE_SSH_KEY file was provided"
fi

# Always use the pinned known-hosts from this checkout — never an empty known-hosts file.
export LIVE_KNOWN_HOSTS="$ROOT/scripts/lib/live-known-hosts"

# shellcheck source=scripts/lib/live-host.sh
source "$ROOT/scripts/lib/live-host.sh"
live_host_assert_not_retired || exit 1
require_live_key || exit 1

# Refuse if StrictHostKeyChecking was somehow weakened by an override.
case " ${LIVE_SSH_OPTS[*]} " in
  *" StrictHostKeyChecking=yes "*) ;;
  *) fail "LIVE_SSH_OPTS must include StrictHostKeyChecking=yes (got: ${LIVE_SSH_OPTS[*]})" ;;
esac
case " ${LIVE_SSH_OPTS[*]} " in
  *" UserKnownHostsFile=$LIVE_KNOWN_HOSTS "*) ;;
  *) fail "LIVE_SSH_OPTS must pin UserKnownHostsFile to $LIVE_KNOWN_HOSTS" ;;
esac

KEY="$LIVE_SSH_KEY"
HOST="$LIVE_SSH_HOST"
SSH_OPTS=("${LIVE_SSH_OPTS[@]}")
DEPLOY_LOCK="$LIVE_DEPLOY_LOCK"

run_ssh() { timeout 120 ssh "${SSH_OPTS[@]}" "$HOST" "$@"; }

# shellcheck source=scripts/lib/live-remote-apply.sh
source "$ROOT/scripts/lib/live-remote-apply.sh"
# shellcheck source=scripts/lib/elaan-freshness-check.sh
source "$ROOT/scripts/lib/elaan-freshness-check.sh"
# shellcheck source=scripts/lib/deploy-main-tip-guard.sh
source "$ROOT/scripts/lib/deploy-main-tip-guard.sh"

# --------------------------------------------------------------------------- target SHA
step "Resolve target commit"
if [ -z "$TARGET_SHA" ]; then
  TARGET_SHA="${GITHUB_SHA:-}"
fi
case "$TARGET_SHA" in
  ""|*[!0-9a-fA-F]*) fail "TARGET_SHA missing or not hex" ;;
esac
if [ "${#TARGET_SHA}" -ne 40 ]; then
  # Expand short SHAs via local git when possible.
  TARGET_SHA=$(git rev-parse --verify "${TARGET_SHA}^{commit}" 2>/dev/null) \
    || fail "TARGET_SHA could not be resolved to a full commit"
fi
echo "TARGET_SHA=$TARGET_SHA"

# Must be the checked-out commit in Actions (deploy this run's main commit, not workspace drift).
LOCAL_HEAD=$(git rev-parse HEAD 2>/dev/null) || fail "cannot read checkout HEAD"
if [ "$LOCAL_HEAD" != "$TARGET_SHA" ]; then
  fail "checkout HEAD ($LOCAL_HEAD) != TARGET_SHA ($TARGET_SHA) — refuse to deploy a different commit than the workflow trigger"
fi

step "Confirm TARGET_SHA is on origin/main history AND is the current tip"
deploy_fetch_origin_main || exit 1
deploy_require_on_main_history "$TARGET_SHA" || exit 1
deploy_require_origin_main_tip "$TARGET_SHA" || exit 1

# --------------------------------------------------------------------------- live preflight (SSH only)
step "Preflight: SSH connectivity + live HEAD"
LIVE_HEAD_BEFORE=$(run_ssh "cd $LIVE_DIR && git rev-parse HEAD" 2>/dev/null) \
  || fail "cannot reach live server over SSH (or live git repo broken)"
echo "live HEAD (before): $LIVE_HEAD_BEFORE"

# After Environment approval + proven SSH, insert the committed spec (if any)
# using the existing elaan-insert.sh path. Then the unchanged freshness gate
# still has to pass. skip_elaan skips BOTH insert and the gate (emergency).
insert_committed_elaan_spec() {
  step "Committed Elaan spec (deploy/elaan.yml) — insert on live if present"
  if [ "$NO_ELAAN" = "1" ] || [ "${SKIP_ELAAN:-}" = "1" ] || [ "${SKIP_ELAAN:-}" = "true" ]; then
    echo "skip_elaan set — not inserting a committed spec (emergency path)."
    return 0
  fi
  local SPEC=""
  if [ -f "$ROOT/deploy/elaan.yml" ]; then
    SPEC="$ROOT/deploy/elaan.yml"
  elif [ -f "$ROOT/deploy/elaan.yaml" ]; then
    SPEC="$ROOT/deploy/elaan.yaml"
  fi
  if [ -z "$SPEC" ]; then
    echo "No deploy/elaan.yml in this commit — skipping CI insert."
    echo "Freshness check still requires a published pos/all AppUpdate after the last deploy marker."
    return 0
  fi
  case "$TARGET_SHA" in
    ""|*[!0-9a-fA-F]*) fail "TARGET_SHA missing — refusing Elaan insert without a deploy SHA" ;;
  esac
  if [ "${#TARGET_SHA}" -ne 40 ]; then
    fail "TARGET_SHA must be 40 hex chars to qualify the Elaan title"
  fi
  echo "Inserting $SPEC via scripts/elaan-insert.sh --deploy-sha=$TARGET_SHA"
  echo "(published title is spec title + [deploy SHA]; existing exact published title is a no-op, never re-dated)."
  bash "$ROOT/scripts/elaan-insert.sh" --from-file "$SPEC" --deploy-sha="$TARGET_SHA" \
    || fail "committed Elaan spec insert failed — fix deploy/elaan.yml or use skip_elaan for emergencies"
}

# Elaan gate: NEW SHA keeps time-fresh requirement; SAME-SHA rerun may pass
# only when marker commit == TARGET_SHA and the committed deploy/elaan.yml
# title still exists as published pos/all (see scripts/lib/elaan-freshness-*).
check_elaan_freshness() {
  elaan_freshness_check "$LIVE_HEAD_BEFORE" "$TARGET_SHA"
}
insert_committed_elaan_spec
check_elaan_freshness

if [ "$LIVE_HEAD_BEFORE" = "$TARGET_SHA" ]; then
  step "Live already at TARGET_SHA — refresh migrate/caches/OPcache under lock (no code checkout)"
  APPLY_OUT=$(remote_apply 0 0 1); APPLY_RC=$?
  echo "$APPLY_OUT"
  [ $APPLY_RC -eq 0 ] || fail "$(apply_fail_reason $APPLY_RC)"
  echo "$APPLY_OUT" | grep -q "OPCACHE_RESET_OK" || fail "web OPcache reset did not confirm"
  if echo "$APPLY_OUT" | grep -q "REMOTE_SETTINGS_REGRESSION"; then
    fail "refresh CHANGED existing shops' saved settings — investigate before shipping anything else"
  fi
else
  if ! git cat-file -e "${LIVE_HEAD_BEFORE}^{commit}" 2>/dev/null; then
    git fetch origin "$LIVE_HEAD_BEFORE" 2>/dev/null \
      || fail "live HEAD $LIVE_HEAD_BEFORE is not in this checkout — cannot compute deploy gap safely"
  fi
  if ! git merge-base --is-ancestor "$LIVE_HEAD_BEFORE" "$TARGET_SHA" 2>/dev/null; then
    fail "live HEAD ($LIVE_HEAD_BEFORE) is not an ancestor of TARGET_SHA ($TARGET_SHA) — refusing divergent CI deploy (reconcile manually)"
  fi

  # Live worktree must be clean for a safe checkout.
  timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" \
    "cd '$LIVE_DIR' && git config core.fileMode false" >/dev/null 2>&1 || true
  DIRTY=$(timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" "LIVE_DIR='$LIVE_DIR' bash -s" <<'DIRTYCHECK' 2>/dev/null || true
cd "$LIVE_DIR" || exit 0
git status --porcelain | grep -v '^??' | head -20
DIRTYCHECK
)
  if [ -n "$DIRTY" ]; then
    echo "Live worktree has MODIFIED tracked files:" >&2
    echo "$DIRTY" >&2
    fail "live tree dirty — reconcile first. Not auto-stashing."
  fi

  GAP_FILES=$(git diff --name-only "$LIVE_HEAD_BEFORE".."$TARGET_SHA" 2>/dev/null || true)
  NEED_MIGRATE=0; NEED_COMPOSER=0
  echo "$GAP_FILES" | grep -q '^database/migrations/' && NEED_MIGRATE=1
  echo "$GAP_FILES" | grep -qE '^composer\.(json|lock)$' && NEED_COMPOSER=1
  echo "gap: $(echo "$GAP_FILES" | grep -c .) file(s); migrations=$NEED_MIGRATE composer=$NEED_COMPOSER"

  step "Live: checkout $TARGET_SHA + composer($NEED_COMPOSER) + migrate($NEED_MIGRATE) + caches + OPcache + queue"
  APPLY_OUT=$(remote_apply 1 "$NEED_COMPOSER" "$NEED_MIGRATE" "$TARGET_SHA"); APPLY_RC=$?
  echo "$APPLY_OUT"
  [ $APPLY_RC -eq 0 ] || fail "$(apply_fail_reason $APPLY_RC)"
  echo "$APPLY_OUT" | grep -q "OPCACHE_RESET_OK" \
    || fail "web OPcache reset did not confirm — live may serve stale compiled code"
  if echo "$APPLY_OUT" | grep -q "REMOTE_SETTINGS_REGRESSION"; then
    fail "this deploy CHANGED existing shops' saved settings. Repair, or re-run with allow_settings if intended."
  fi
  if echo "$APPLY_OUT" | grep -q "REMOTE_SETTINGS_BASELINE_FAILED"; then
    fail "settings baseline could not be captured — refusing to treat this release as clean"
  fi
fi

LIVE_HEAD_AFTER=$(run_ssh "cd $LIVE_DIR && git rev-parse HEAD" 2>/dev/null)
[ "$LIVE_HEAD_AFTER" = "$TARGET_SHA" ] \
  || fail "apply ran but live HEAD ($LIVE_HEAD_AFTER) != TARGET_SHA ($TARGET_SHA)"
echo "live HEAD (after): $LIVE_HEAD_AFTER — matches TARGET_SHA."

step "Verify: homepage returns 200"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$LIVE_URL/")
echo "GET $LIVE_URL/ -> $HTTP_CODE"
[ "$HTTP_CODE" = "200" ] || fail "homepage returned $HTTP_CODE after deploy — investigate immediately"

step "Verify: live caches fresh"
OUT=$(run_ssh "LIVE_DIR='$LIVE_DIR' bash -s" <<'FRESHPROBE' 2>/dev/null
cd "$LIVE_DIR" || { echo PROBE_CD_FAIL; exit 0; }
RC=$(ls -t bootstrap/cache/routes-*.php 2>/dev/null | head -1)
[ -z "$RC" ] && { echo PROBE_NO_ROUTE_CACHE; exit 0; }
NEWER=$(find routes app config resources/views bootstrap/app.php \
          -name '*.php' -newer "$RC" -print 2>/dev/null | head -5)
[ composer.lock -nt "$RC" ] && NEWER="composer.lock
$NEWER"
if [ -n "$NEWER" ]; then
  echo PROBE_STALE
  echo "$NEWER"
else
  echo PROBE_FRESH
fi
FRESHPROBE
)
case "$OUT" in
  PROBE_FRESH*) echo "Live caches fresh." ;;
  PROBE_STALE*) fail "live caches STALE after deploy — $OUT" ;;
  PROBE_NO_ROUTE_CACHE*) fail "no route cache found on live after deploy" ;;
  *) fail "cache-freshness probe could not run (output: ${OUT:-empty})" ;;
esac

step "Recording deploy marker"
NOW_TS=$(date +%s)
run_ssh "printf '%s\n' '${NOW_TS}|${TARGET_SHA}' > '$LIVE_DEPLOY_MARKER' && echo MARKER_WRITTEN" 2>/dev/null \
  | grep -q "MARKER_WRITTEN" \
  || fail "deploy marker write FAILED on live — fix manually then re-verify"

echo ""
echo "---------------------------------------------------------------"
echo "CI DEPLOY OK: live HEAD == TARGET_SHA ($TARGET_SHA)"
echo "              checkout + caches + OPcache + queue; homepage 200."
echo "              Rollback remains: deployment/ROLLBACK.md"
echo "---------------------------------------------------------------"
exit 0
