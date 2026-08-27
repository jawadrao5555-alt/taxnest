#!/bin/bash
# Record the Physical Stock Check tutorial.
# Run as a Replit workflow (console) — NEVER as a backgrounded ShellExec:
# background processes are cgroup-killed when the shell session ends.
cd "$(dirname "$0")/../.."

if [ -f .local/qa-creds.env ]; then set -a; . .local/qa-creds.env; set +a; fi
export VIDEO_DEMO_PASS="${VIDEO_DEMO_PASS:-$DEV_POS_PASS}"
export CHROMIUM_BIN=/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium

SLUG=stock-check
OUT="tools/video-pipeline/out/$SLUG"

echo "=== SEEDING demo shelf ($(date +%H:%M:%S)) ==="
env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
  php artisan db:seed --class=VideoStockCheckSeeder --force || exit 1

# Real narration overwrites these later; the capture only needs scene lengths.
if [ ! -f "$OUT/tts/durations.cjson" ]; then
  echo "!! missing $OUT/tts/durations.cjson"; exit 1
fi

# Preflight: a bad selector otherwise costs a full ~5 minute capture to find.
# This replays the same actions in ~40s and fails the run before the camera
# rolls. It also leaves the shop in the post-run state, so re-seed after it.
echo "=== PREFLIGHT selectors ($(date +%H:%M:%S)) ==="
node tools/video-pipeline/dry-run.cjs "tools/video-pipeline/scenarios/$SLUG.json" 2>&1 | tail -20
if [ "${PIPESTATUS[0]}" -ne 0 ]; then
  echo "=== PREFLIGHT FAILED — not recording ==="; exit 1
fi

env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
  VIDEO_PIPELINE_ALLOW=1 php artisan db:seed --class=VideoStockCheckSeeder --force || exit 1

echo "=== RECORDING $SLUG ($(date +%H:%M:%S)) ==="
# Also tee to a file we own — the workflow console log has been serving a stale
# snapshot, and a blind re-run tells us nothing about where the take died.
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
