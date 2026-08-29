#!/bin/bash
# Record the "LAN Mode + Offline" tutorial.
# Run as a Replit workflow (console) — NEVER as a backgrounded ShellExec:
# background processes are cgroup-killed when the shell session ends.
cd "$(dirname "$0")/../.."

if [ -f .local/qa-creds.env ]; then set -a; . .local/qa-creds.env; set +a; fi
export VIDEO_DEMO_PASS="${VIDEO_DEMO_PASS:-$DEV_POS_PASS}"
export CHROMIUM_BIN=/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium

SLUG=lan-mode
OUT="tools/video-pipeline/out/$SLUG"
DEMO_PID=""

# The harness wipes its device store at boot, so "restart it" is the only way
# to get back to a shop where nothing has ever paired.
start_demo() {
  node tools/video-pipeline/lan-demo-server.cjs 8600 8531 > "$OUT/lan-demo.log" 2>&1 &
  DEMO_PID=$!
  sleep 2
  if ! curl -sf http://127.0.0.1:8600/demo/lan/status > /dev/null; then
    echo "!! LAN demo harness did not come up"; cat "$OUT/lan-demo.log"; exit 1
  fi
}
stop_demo() { [ -n "$DEMO_PID" ] && kill "$DEMO_PID" 2>/dev/null; DEMO_PID=""; sleep 1; }
trap stop_demo EXIT

mkdir -p "$OUT"

seed() {
  env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
    VIDEO_PIPELINE_ALLOW=1 php artisan db:seed --class=VideoDemoShopSeeder --force || exit 1
}

echo "=== SEEDING demo shop ($(date +%H:%M:%S)) ==="
seed

if [ ! -f "$OUT/tts/durations.cjson" ]; then
  echo "!! missing $OUT/tts/durations.cjson — generate narration first"; exit 1
fi

# Preflight: a bad selector otherwise costs a full ~6 minute capture to find.
# It leaves the shop AND the pairing store dirty, so both are reset after it.
echo "=== PREFLIGHT selectors ($(date +%H:%M:%S)) ==="
start_demo
node tools/video-pipeline/dry-run.cjs "tools/video-pipeline/scenarios/$SLUG.json" 2>&1 | tail -20
PRE=${PIPESTATUS[0]}
stop_demo
if [ "$PRE" -ne 0 ]; then
  echo "=== PREFLIGHT FAILED — not recording ==="; exit 1
fi

echo "=== RESET demo shop + pairing store ($(date +%H:%M:%S)) ==="
seed
start_demo

echo "=== RECORDING $SLUG ($(date +%H:%M:%S)) ==="
# Clear the previous take first, or the salvage check below can pass on stale
# artifacts and report a take that never happened as a success.
rm -f "$OUT/capture.webm" "$OUT/timeline.json"
node tools/video-pipeline/record.cjs "tools/video-pipeline/scenarios/$SLUG.json" 2>&1 \
  | tee "$OUT/record.log" | tail -40
REC=${PIPESTATUS[0]}
[ "$REC" -ne 0 ] && echo "(recorder exited $REC — checking whether the take still landed)"

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
