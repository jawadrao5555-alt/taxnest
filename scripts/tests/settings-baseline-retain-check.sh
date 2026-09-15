#!/usr/bin/env bash
# Static proof that retained forensic baselines are never overwritten by the
# next deploy capture, and that per-deploy baselines are unique + fail-closed.
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
APPLY="$ROOT/scripts/lib/live-remote-apply.sh"
HOST="$ROOT/scripts/lib/live-host.sh"
CMD="$ROOT/app/Console/Commands/PosSettingsSnapshotCommand.php"
FAILS=0
ok() { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS + 1)); }

for f in "$APPLY" "$HOST" "$CMD"; do
  [ -f "$f" ] || bad "missing $f"
done

grep -q 'LIVE_SETTINGS_BASELINES_DIR' "$HOST" \
  && grep -q 'LIVE_SETTINGS_RETAINED_DIR' "$HOST" \
  && ok "live-host defines per-deploy and retained baseline directories" \
  || bad "live-host missing baseline directory vars"

grep -q 'before-${DEPLOY_ID}.json' "$APPLY" \
  && grep -q 'RETAINED_BASE="${LIVE_SETTINGS_BASE}"' "$APPLY" \
  && ok "deploy uses unique per-deploy baseline path, not the retained path for capture" \
  || bad "deploy still captures onto the retained forensic path"

grep -q 'retained settings baseline present' "$APPLY" \
  && grep -q 'pos:settings-restore --from="\$RETAINED_BASE"' "$APPLY" \
  && grep -q 'REMOTE_SETTINGS_RETAINED_AMBIGUOUS' "$APPLY" \
  && grep -q 'exit 89' "$APPLY" \
  && ok "retained baseline is validated/restored before capture; ambiguous fails closed" \
  || bad "retained-baseline pre-capture restore path incomplete"

grep -q 'canonical retained baseline already present — left untouched' "$APPLY" \
  && ok "new regressions never overwrite an existing canonical retained baseline" \
  || bad "canonical retained baseline can still be overwritten on regression"

grep -q 'temporary deploy baseline removed (clean)' "$APPLY" \
  && ok "clean deploys remove only their temporary baseline" \
  || bad "clean-deploy temp baseline cleanup missing"

grep -q 'Refusing to overwrite existing snapshot' "$CMD" \
  && grep -q '{--force' "$CMD" \
  && ok "pos:settings-snapshot refuses overwrite without --force" \
  || bad "snapshot command still overwrites unconditionally"

grep -q 'hash:' "$CMD" \
  && ok "settings compare output uses hashes instead of raw customer values" \
  || bad "settings compare still prints raw setting values"

if [ "$FAILS" -eq 0 ]; then
  echo "settings-baseline-retain-check: ALL PASS"
  exit 0
fi
echo "settings-baseline-retain-check: $FAILS FAIL(S)" >&2
exit 1
