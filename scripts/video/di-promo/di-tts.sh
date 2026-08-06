#!/usr/bin/env bash
# DI Promo — generate TTS MP3s from narration.txt using ElevenLabs API.
#
# Usage (from repo root):
#   ELEVENLABS_API_KEY=<key> bash scripts/video/di-promo/di-tts.sh
#
# Output: .local/video-studio/di-promo/tts/seg01.mp3 … seg12.mp3
#         (gitignored; large binary outputs never enter the repo)
#
# Requires: curl, jq
# ElevenLabs voice / model config:
#   Voice ID : v1PxIIJZSZzAub07hKLn  (custom Urdu announcer)
#   Model    : eleven_v3
set -euo pipefail

API_KEY="${ELEVENLABS_API_KEY:?Set ELEVENLABS_API_KEY env var}"
VOICE_ID="v1PxIIJZSZzAub07hKLn"
MODEL="eleven_v3"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_BASE="${DI_OUT_DIR:-.local/video-studio}"
OUT_DIR="${OUT_BASE}/di-promo/tts"

mkdir -p "$OUT_DIR"

# Parse narration.txt: skip comment lines (#) and blank lines; collect segments.
mapfile -t LINES < <(grep -v '^\s*#' "${SCRIPT_DIR}/narration.txt" | grep -v '^\s*$')

if [[ ${#LINES[@]} -ne 12 ]]; then
  echo "ERROR: expected 12 narration segments, got ${#LINES[@]}" >&2
  exit 1
fi

for i in "${!LINES[@]}"; do
  seg=$(( i + 1 ))
  printf -v SEGNUM "%02d" "$seg"
  OUT_FILE="${OUT_DIR}/seg${SEGNUM}.mp3"

  echo "Generating seg${SEGNUM}: ${LINES[$i]:0:60}…"

  PAYLOAD=$(jq -n \
    --arg text  "${LINES[$i]}" \
    --arg model "$MODEL" \
    '{text: $text, model_id: $model, voice_settings: {stability: 0.5, similarity_boost: 0.75}}')

  HTTP_STATUS=$(curl -s -o "$OUT_FILE" -w "%{http_code}" \
    -X POST "https://api.elevenlabs.io/v1/text-to-speech/${VOICE_ID}" \
    -H "xi-api-key: ${API_KEY}" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD")

  if [[ "$HTTP_STATUS" != "200" ]]; then
    echo "ERROR: ElevenLabs returned HTTP ${HTTP_STATUS} for seg${SEGNUM}" >&2
    rm -f "$OUT_FILE"
    exit 1
  fi

  SIZE=$(stat -c%s "$OUT_FILE" 2>/dev/null || stat -f%z "$OUT_FILE")
  echo "  → ${OUT_FILE} (${SIZE} bytes)"
done

echo "TTS generation complete — ${#LINES[@]} segments in ${OUT_DIR}/"
