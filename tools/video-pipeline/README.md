# NestPOS Urdu Tutorial Video Pipeline (Task 231)

Repeatable pipeline to produce Urdu-voiceover tutorial videos from **real**
NestPOS screen recordings. Outputs (MP4/MP3) are git-ignored — only the
pipeline + scenarios are committed.

## Flow

1. **Demo shop** — `php artisan db:seed --class=VideoDemoShopSeeder --force`
   (fictional "Al-Noor General Store", login `videodemo@nestpos.pk` /
   `NestPOS@Demo1`, dev DB only). Never record a real customer company.
2. **Scenario** — one JSON per video in `scenarios/` (format below).
3. **TTS** — per-scene female Urdu narration via ElevenLabs
   (`externalApi__elevenlabs` in the agent's CodeExecution sandbox; the API is
   NOT callable from plain node). Model `eleven_multilingual_v2`, voice
   "Sarah" `EXAVITQu4vr4xnSDxMaL` (owner-approved pilot voice). Save each scene
   as `out/<slug>/tts/<sceneId>.mp3`, then run
   `node tools/video-pipeline/durations.cjs <slug>` to write
   `out/<slug>/tts/durations.cjson`. Keep each scene's text short (proxy caps
   responses ~1 MB ≈ <1 min audio per call).
   Fallback: OpenAI TTS with `OPENAI_API_KEY` from `.env`.
4. **Record** — `node tools/video-pipeline/record.cjs scenarios/<slug>.json`
   Drives system Chromium (no download) at 1920×1080, injects a synthetic
   cursor + click ripples + element highlights, holds each scene for its
   narration duration (+pad). Writes `out/<slug>/capture.webm` +
   `out/<slug>/timeline.json` (scene start offsets).
5. **Assemble** — `node tools/video-pipeline/assemble.cjs <slug>`
   Muxes narration onto the capture at the recorded offsets →
   `out/<slug>/<slug>-16x9.mp4`, then builds the framed 9:16 version →
   `out/<slug>/<slug>-9x16.mp4` (branded teal frame, video centered, big
   captions — WhatsApp-status readable).

## Scenario format (`scenarios/*.json`)

```jsonc
{
  "slug": "sale-screen",
  "baseUrl": "http://127.0.0.1:5000",
  "title": "Sale Screen — Bill, Payment, Receipt", // used on cards
  "scenes": [
    {
      "id": "s01_intro",
      "narration": "…Urdu text used for TTS…",
      "card": { "heading": "NestPOS", "sub": "Sale Screen Tutorial" }, // title card scene
      "minMs": 4000            // optional floor; actual hold = max(minMs, audio+pad)
    },
    {
      "id": "s02_login",
      "narration": "…",
      "actions": [              // UI scenes: actions run, then hold till narration ends
        { "do": "goto", "url": "/pos/login" },
        { "do": "type", "selector": "#login", "text": "videodemo@nestpos.pk" },
        { "do": "click", "selector": "button[type=submit]" },
        { "do": "waitFor", "selector": ".prod-card" },
        { "do": "wait", "ms": 800 },
        { "do": "highlight", "selector": "..." },
        { "do": "press", "selector": "...", "key": "Enter" }
      ]
    }
  ]
}
```

Notes:
- Title cards render in Roman Urdu/English (workspace Chromium has no Urdu
  fonts); the voiceover carries the Urdu.
- Privacy: only the seeded demo shop on the dev server; password fields are
  masked; never show tokens/settings pages unless the scenario is about them.
- Style contract for the series (pilot-approved): female voice "Sarah",
  natural bol-chaal Urdu with English tech words as-is, teal `#0A4D5C` /
  gold `#E7BF3B` cards, synthetic amber cursor with purple click ripple.
