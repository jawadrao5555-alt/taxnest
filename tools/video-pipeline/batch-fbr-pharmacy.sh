#!/bin/bash
# Record the FBR POS Pharmacy Mode walkthrough.
# Run as a Replit workflow (console) — NEVER as a backgrounded ShellExec:
# background processes are cgroup-killed when the shell session ends.
cd "$(dirname "$0")/../.."

if [ -f .local/qa-creds.env ]; then set -a; . .local/qa-creds.env; set +a; fi
export VIDEO_DEMO_PASS="${VIDEO_DEMO_PASS:-$DEV_POS_PASS}"
export CHROMIUM_BIN=/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium

SLUG=fbr-pharmacy
OUT="tools/video-pipeline/out/$SLUG"
DEMO_EMAIL=pharmacydemo@nestpos.pk
ARTISAN_ENV="env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE VIDEO_PIPELINE_ALLOW=1"

seed() {
  $ARTISAN_ENV php artisan db:seed --class=FbrPharmacyVideoDemoSeeder --force
}

echo "=== SEEDING pharmacy demo shop ($(date +%H:%M:%S)) ==="
seed || exit 1

if [ ! -f "$OUT/tts/durations.cjson" ]; then
  echo "!! missing $OUT/tts/durations.cjson"; exit 1
fi

# The demo shop reports to FBR through a Desktop Agent that does not exist
# here; this stand-in marks queued bills submitted so the payment popup flips
# to "FBR Verified" on camera instead of pulsing "Failed 1" in the header.
$ARTISAN_ENV php tools/video-pipeline/fake-agent-loop.php &
AGENT_PID=$!
trap 'kill $AGENT_PID 2>/dev/null' EXIT

# Preflight: a bad selector otherwise costs a full ~6 minute capture to find.
if [ "${SKIP_PREFLIGHT:-0}" != "1" ]; then
  echo "=== PREFLIGHT selectors ($(date +%H:%M:%S)) ==="
  node tools/video-pipeline/dry-run.cjs "tools/video-pipeline/scenarios/$SLUG.json" 2>&1 | tail -25
  if [ "${PIPESTATUS[0]}" -ne 0 ]; then
    echo "=== PREFLIGHT FAILED — not recording ==="; exit 1
  fi
  # The dry run left its own bill/claim/product behind — start clean again.
  seed || exit 1
fi

echo "=== RECORDING $SLUG ($(date +%H:%M:%S)) ==="
node tools/video-pipeline/record.cjs "tools/video-pipeline/scenarios/$SLUG.json" 2>&1 \
  | tee "$OUT/record.log" | tail -40

# Salvage rule: capture.webm + timeline.json are written together at the very
# end, so if both exist the take succeeded regardless of the exit code.
if [ -f "$OUT/capture.webm" ] && [ -f "$OUT/timeline.json" ]; then
  echo "=== CAPTURE OK $SLUG ($(date +%H:%M:%S)) ==="
  ls -la "$OUT"/capture.webm "$OUT"/timeline.json
else
  echo "=== CAPTURE FAILED $SLUG ==="
  ls -la "$OUT" 2>/dev/null
  exit 1
fi

echo "=== DONE ($(date +%H:%M:%S)) ==="
