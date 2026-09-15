#!/usr/bin/env bash
# Static proof that retained forensic baselines are never overwritten by the
# next deploy capture, and that per-deploy baselines are unique + fail-closed.
# Companion executable proof: scripts/tests/settings-baseline-retain-harness.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
APPLY="$ROOT/scripts/lib/live-remote-apply.sh"
LIB="$ROOT/scripts/lib/settings-baseline.sh"
HOST="$ROOT/scripts/lib/live-host.sh"
CMD="$ROOT/app/Console/Commands/PosSettingsSnapshotCommand.php"
HARNESS="$ROOT/scripts/tests/settings-baseline-retain-harness.sh"
FAILS=0
ok() { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS + 1)); }

for f in "$APPLY" "$LIB" "$HOST" "$CMD" "$HARNESS"; do
  [ -f "$f" ] || bad "missing $f"
done

grep -q 'LIVE_SETTINGS_BASELINES_DIR' "$HOST" \
  && grep -q 'LIVE_SETTINGS_RETAINED_DIR' "$HOST" \
  && ok "live-host defines per-deploy and retained baseline directories" \
  || bad "live-host missing baseline directory vars"

grep -q 'settings-baseline.sh' "$APPLY" \
  && grep -q 'settings_baseline_handle_retained' "$APPLY" \
  && grep -q 'settings_baseline_capture' "$APPLY" \
  && grep -q 'settings_baseline_post_check' "$APPLY" \
  && ok "deploy sources shared baseline helpers (handle/capture/post_check)" \
  || bad "deploy does not use shared settings-baseline helpers"

grep -q 'settings_baseline_this_path' "$LIB" \
  && grep -q 'before-%s.json' "$LIB" \
  && grep -q 'LIVE_SETTINGS_BASE' "$LIB" \
  && ok "library uses unique per-deploy baseline path, not retained path for capture" \
  || bad "library still captures onto the retained forensic path"

grep -q 'REMOTE_SETTINGS_RETAINED_AMBIGUOUS' "$LIB" \
  && grep -q 'return 89' "$LIB" \
  && grep -q 'pos:settings-restore --from="$retained"' "$LIB" \
  && ok "retained baseline is validated/restored before capture; ambiguous fails closed" \
  || bad "retained-baseline pre-capture restore path incomplete"

grep -q 'canonical retained baseline already present — left untouched' "$LIB" \
  && ok "new regressions never overwrite an existing canonical retained baseline" \
  || bad "canonical retained baseline can still be overwritten on regression"

grep -q 'temporary deploy baseline removed (clean)' "$LIB" \
  && ok "clean deploys remove only their temporary baseline" \
  || bad "clean-deploy temp baseline cleanup missing"

grep -q 'Refusing to overwrite existing snapshot' "$CMD" \
  && grep -q '{--force' "$CMD" \
  && ok "pos:settings-snapshot refuses overwrite without --force" \
  || bad "snapshot command still overwrites unconditionally"

grep -q 'hash:' "$CMD" \
  && ok "settings compare output uses hashes instead of raw customer values" \
  || bad "settings compare still prints raw setting values"

grep -q 'settings_baseline_handle_retained\|settings_baseline_post_check' "$HARNESS" \
  && ok "executable retain harness exercises shared helper logic" \
  || bad "executable retain harness missing"

if [ "$FAILS" -eq 0 ]; then
  echo "settings-baseline-retain-check: ALL PASS"
  exit 0
fi
echo "settings-baseline-retain-check: $FAILS FAIL(S)" >&2
exit 1
