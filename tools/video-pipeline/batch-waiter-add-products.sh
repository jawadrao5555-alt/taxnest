#!/bin/bash
# Record the follow-up tutorial: append products to a sent waiter order, then
# complete one final PRA POS bill at the cashier.
set -o pipefail
cd "$(dirname "$0")/../.."

if [ -f .local/qa-creds.env ]; then set -a; . .local/qa-creds.env; set +a; fi
export VIDEO_DEMO_PASS="${VIDEO_DEMO_PASS:-$DEV_POS_PASS}"
export CHROMIUM_BIN=/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium

SLUG=waiter-add-products
OUT="tools/video-pipeline/out/$SLUG"
mkdir -p "$OUT"

seed() {
  env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
    VIDEO_PIPELINE_ALLOW=1 php artisan db:seed --class=VideoRestoShopSeeder --force
}

echo "=== SEEDING demo restaurant ($(date +%H:%M:%S)) ==="
seed || exit 1

if [ ! -f "$OUT/tts/durations.cjson" ]; then
  echo "!! missing $OUT/tts/durations.cjson — generate narration first"
  exit 1
fi

echo "=== PREFLIGHT selectors ($(date +%H:%M:%S)) ==="
node tools/video-pipeline/dry-run.cjs "tools/video-pipeline/scenarios/$SLUG.json" 2>&1 \
  | tee "$OUT/preflight.log" | tail -30
PRE=${PIPESTATUS[0]}
if [ "$PRE" -ne 0 ]; then
  echo "=== PREFLIGHT FAILED — not recording ==="
  exit 1
fi

echo "=== RESET demo restaurant ($(date +%H:%M:%S)) ==="
seed || exit 1

echo "=== RECORDING $SLUG ($(date +%H:%M:%S)) ==="
rm -f "$OUT/capture.webm" "$OUT/timeline.json"
node tools/video-pipeline/record.cjs "tools/video-pipeline/scenarios/$SLUG.json" 2>&1 \
  | tee "$OUT/record.log" | tail -40
REC=${PIPESTATUS[0]}
[ "$REC" -ne 0 ] && echo "(recorder exited $REC — checking whether the take still landed)"

if [ -f "$OUT/capture.webm" ] && [ -f "$OUT/timeline.json" ]; then
  echo "=== CAPTURE OK $SLUG ($(date +%H:%M:%S)) ==="
  ls -la "$OUT/capture.webm" "$OUT/timeline.json"
else
  echo "=== CAPTURE FAILED $SLUG ==="
  ls -la "$OUT" 2>/dev/null
  exit 1
fi

echo "=== DONE ($(date +%H:%M:%S)) ==="