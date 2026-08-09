#!/bin/bash
# Batch-record the 11 new tutorial videos (Aug 2026).
# Run as a Replit workflow (console) — NEVER as a backgrounded ShellExec.
# Order matters: resto table videos before kds-kitchen (kds leaves T-3 occupied).
cd "$(dirname "$0")"
export CHROMIUM_BIN=/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium
SLUGS=(
  dashboard-tour
  hold-recall
  reports-tax-guide
  tables-shift
  recipes-ingredients
  qr-menu
  rider-live-tracking
  kds-kitchen
)
OLD_SLUGS=(
  dashboard-tour
  hold-recall
  keyboard-shortcuts
  language-badalna
  suggestion-box
  reports-tax-guide
  tables-shift
  recipes-ingredients
  qr-menu
  rider-live-tracking
  kds-kitchen
)
for slug in "${SLUGS[@]}"; do
  echo "=== RECORDING $slug ($(date +%H:%M:%S)) ==="
  node record.cjs "scenarios/$slug.json" 2>&1 | tail -30
  if [ -f "out/$slug/capture.webm" ] && [ -f "out/$slug/timeline.json" ]; then
    echo "=== OK $slug ==="
  else
    echo "=== FAILED $slug (missing capture.webm/timeline.json) ==="
  fi
done
echo "=== BATCH DONE ==="
sleep 3600
