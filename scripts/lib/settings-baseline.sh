#!/usr/bin/env bash
# Pure settings-baseline helpers for live deploys and local harnesses.
#
# Sourced by scripts/lib/live-remote-apply.sh (on the live host after checkout)
# and by scripts/tests/settings-baseline-retain-harness.sh (disposable local).
#
# Expects:
#   SETTINGS_PHP / SETTINGS_ARTISAN  — command prefix (default: php artisan)
#   LIVE_SETTINGS_BASE               — canonical retained forensic path
#   LIVE_SETTINGS_BASELINES_DIR      — per-deploy unique baselines
#   LIVE_SETTINGS_RETAINED_DIR       — archived forensic copies
#
# Never prints raw customer setting values.

settings_baseline_init_dirs() {
  mkdir -p "$LIVE_SETTINGS_BASELINES_DIR" "$LIVE_SETTINGS_RETAINED_DIR"
}

settings_baseline_deploy_id() {
  local sha="${1:-refresh}"
  printf '%s-%s-%s' "$sha" "$$" "$(date -u +%Y%m%d%H%M%S%N 2>/dev/null || date -u +%Y%m%d%H%M%S)"
}

settings_baseline_this_path() {
  local deploy_id="$1"
  printf '%s/before-%s.json' "$LIVE_SETTINGS_BASELINES_DIR" "$deploy_id"
}

# Returns 0 when retained baseline is absent OR successfully restored+archived.
# Returns 89 when retained exists but plan/restore is ambiguous (file untouched).
settings_baseline_handle_retained() {
  local retained="${LIVE_SETTINGS_BASE}"
  local deploy_id="$1"
  local artisan="${SETTINGS_ARTISAN:-php artisan}"
  local out rc forensic

  if [ ! -f "$retained" ]; then
    echo "SETTINGS_BASELINE: no retained forensic baseline"
    return 0
  fi

  echo "SETTINGS_BASELINE: retained present — dry-run restore plan"
  # shellcheck disable=SC2086
  if ! $artisan pos:settings-restore --from="$retained" >/tmp/settings-baseline-dry.$$.log 2>&1; then
    echo "REMOTE_SETTINGS_RETAINED_AMBIGUOUS"
    echo "SETTINGS_BASELINE: refusing to overwrite retained baseline"
    rm -f /tmp/settings-baseline-dry.$$.log
    return 89
  fi
  rm -f /tmp/settings-baseline-dry.$$.log

  echo "SETTINGS_BASELINE: applying hash-checked restore"
  # shellcheck disable=SC2086
  out=$($artisan pos:settings-restore --from="$retained" --write 2>&1) || true
  # Redact anything that looks like a JSON array/object fragment in output.
  printf '%s\n' "$out" | sed -E 's/\[[^][]{0,200}\]/(redacted)/g; s/\{[^}]{0,200}\}/(redacted)/g'
  if ! printf '%s\n' "$out" | grep -qE 'Verified: service_jobs-only rows match the baseline again|Already clean against baseline'; then
    echo "REMOTE_SETTINGS_RETAINED_RESTORE_FAILED"
    echo "SETTINGS_BASELINE: retained baseline left untouched"
    return 89
  fi

  forensic="${LIVE_SETTINGS_RETAINED_DIR}/archived-after-restore-${deploy_id}.json"
  cp -a "$retained" "$forensic" || return 89
  rm -f "$retained" || return 89
  echo "REMOTE_SETTINGS_RETAINED_RESTORED"
  echo "SETTINGS_BASELINE: forensic copy kept at $forensic"
  return 0
}

# Capture a unique per-deploy baseline. Never writes to LIVE_SETTINGS_BASE.
# Sets SETTINGS_BASE and SETTINGS_BASE_OK (0|1).
settings_baseline_capture() {
  local deploy_id="$1"
  local artisan="${SETTINGS_ARTISAN:-php artisan}"
  SETTINGS_BASE="$(settings_baseline_this_path "$deploy_id")"
  SETTINGS_BASE_OK=0

  if [ -f "$LIVE_SETTINGS_BASE" ]; then
    echo "SETTINGS_BASELINE: fatal — retained path still occupied before capture"
    return 89
  fi
  if [ -f "$SETTINGS_BASE" ]; then
    echo "SETTINGS_BASELINE: fatal — deploy baseline path already exists (collision)"
    return 88
  fi

  # shellcheck disable=SC2086
  if $artisan pos:settings-snapshot --out="$SETTINGS_BASE" >/dev/null 2>&1; then
    SETTINGS_BASE_OK=1
    echo "SETTINGS_BASELINE: captured at $SETTINGS_BASE"
    return 0
  fi
  echo "REMOTE_SETTINGS_BASELINE_FAILED"
  echo "SETTINGS_BASELINE: capture failed — regression guard disarmed"
  return 0
}

# Post-migrate compare. On regression: restore, keep forensic, install canonical
# retain only if absent. On clean: delete only THIS deploy baseline.
# Prints markers; returns 0 always (fail-closed is signaled by markers for the
# parent deploy script). Sets SETTINGS_REGRESSION=0|1.
settings_baseline_post_check() {
  local artisan="${SETTINGS_ARTISAN:-php artisan}"
  local deploy_id="$1"
  local allow="${2:-}"
  local set_out set_rc restore_out forensic
  SETTINGS_REGRESSION=0

  if [ "${SETTINGS_BASE_OK:-0}" != 1 ]; then
    return 0
  fi

  echo "SETTINGS_BASELINE: regression check against $SETTINGS_BASE"
  # Compare exits non-zero on regression; tolerate that under set -e so the
  # caller can still restore + retain forensic copies.
  set +e
  if [ -n "$allow" ]; then
    # shellcheck disable=SC2086
    set_out=$($artisan pos:settings-snapshot --compare="$SETTINGS_BASE" --allow="$allow" 2>&1)
  else
    # shellcheck disable=SC2086
    set_out=$($artisan pos:settings-snapshot --compare="$SETTINGS_BASE" 2>&1)
  fi
  set_rc=$?
  set -e
  printf '%s\n' "$set_out" | sed -E 's/\[[^][]{0,200}\]/(redacted)/g; s/\{[^}]{0,200}\}/(redacted)/g'

  if [ "$set_rc" != 0 ]; then
    SETTINGS_REGRESSION=1
    echo "REMOTE_SETTINGS_REGRESSION"
    echo "SETTINGS_BASELINE: attempting automatic restore from this deploy baseline"
    # shellcheck disable=SC2086
    restore_out=$($artisan pos:settings-restore --from="$SETTINGS_BASE" --write 2>&1) || true
    printf '%s\n' "$restore_out" | sed -E 's/\[[^][]{0,200}\]/(redacted)/g; s/\{[^}]{0,200}\}/(redacted)/g'
    if printf '%s\n' "$restore_out" | grep -q 'Verified: service_jobs-only rows match the baseline again'; then
      echo "REMOTE_SETTINGS_RESTORED"
    else
      echo "REMOTE_SETTINGS_RESTORE_FAILED"
    fi
    forensic="${LIVE_SETTINGS_RETAINED_DIR}/retained-after-regression-${deploy_id}.json"
    cp -a "$SETTINGS_BASE" "$forensic" 2>/dev/null || true
    echo "SETTINGS_BASELINE: forensic copy at $forensic"
    if [ ! -f "$LIVE_SETTINGS_BASE" ]; then
      cp -a "$SETTINGS_BASE" "$LIVE_SETTINGS_BASE" 2>/dev/null || true
      echo "SETTINGS_BASELINE: canonical retained baseline installed"
    else
      echo "SETTINGS_BASELINE: canonical retained baseline already present — left untouched"
    fi
    echo "SETTINGS_BASELINE: deploy baseline retained at $SETTINGS_BASE"
  else
    rm -f "$SETTINGS_BASE"
    echo "SETTINGS_BASELINE: temporary deploy baseline removed (clean)"
  fi
  return 0
}
