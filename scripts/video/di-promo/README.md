# DI Promo Video Pipeline

Source scripts for the ~58 s Urdu promo video for the **Digital Invoicing (DI)** panel.

**Output** (never committed — gitignored large binaries):
```
.local/video-studio/di-promo/di-promo.mp4
```

---

## Pipeline steps

### 1. TTS (one-time, already done)

Generates 12 Urdu MP3 segments from `narration.txt` via ElevenLabs.

```bash
ELEVENLABS_API_KEY=<key> bash scripts/video/di-promo/di-tts.sh
# → .local/video-studio/di-promo/tts/seg01.mp3 … seg12.mp3
```

The key is never stored in the repo — pass it via env only.
Voice: `v1PxIIJZSZzAub07hKLn` (custom Urdu announcer), model `eleven_v3`.

### 2. Title cards (re-render after design changes)

```bash
node scripts/video/di-promo/di-cards.js
# → .local/video-studio/di-promo/cards/*.png  (6 PNGs, 1280×720)
```

Requires network access to fetch **Noto Nastaliq Urdu** from Google Fonts.
Chromium path auto-detected from `CHROMIUM` env or the Nix-store default.

**Font note:** Nastaliq glyphs must occupy a *pure Arabic-script* bidi run in the
heading — a lone Urdu word (`ہر`) placed next to Latin text renders tiny because
the Nastaliq em-box dedicates most vertical space to calligraphic descenders.
The compliance card uses `ہر بل` (both words Urdu) to avoid this.

### 3. B-roll recording (re-record if demo content changes)

App must be running at `http://127.0.0.1:5000` (or set `DI_BASE_URL`).
Demo company: **Al-Farooq Traders** (`didemo@nestpos.pk`, dev-only, `company_id=10706`).

```bash
node scripts/video/di-promo/di-record.js
# → .local/video-studio/di-promo/register-take.webm + register-timeline.json
#    .local/video-studio/di-promo/take.webm         + timeline.json
```

Two recordings in one run:
- **Part 1** — unauthenticated `/register` form (no cookies needed)
- **Part 2** — authenticated dashboard → invoice create → modal → completed invoice → list

**Key invariants:**
- Login tab = `?tab=completed` for hero invoice (default tab is `draft` — InvoiceController:38)
- CSRF fix: GET `/login` `Set-Cookie` forwarded into POST `/login` `Cookie` header
- SW suppressed: `navigator.serviceWorker.register = () => Promise.resolve({})`

### 4. Assemble

```bash
python3 scripts/video/di-promo/di-assemble.py
# → .local/video-studio/di-promo/di-promo.mp4
```

Also needs `promo/music72.mp3` (shared 72 s music bed) under `.local/video-studio/`.

**Duration arithmetic:**
- 12 TTS raw segments ≈ 49.5 s total
- GAP = 0.22 s per segment → +2.64 s
- End-card hold: `durs[11] = raw[11] + 6.2 s` → ≈ 58.1 s total ✓
- Target range: 58–80 s (assembler warns if outside)

---

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `DI_OUT_DIR` | `.local/video-studio` | Output base dir |
| `CHROMIUM` | Nix-store path | Chromium executable |
| `DI_BASE_URL` | `http://127.0.0.1:5000` | App URL for recording |
| `DI_EMAIL` | `didemo@nestpos.pk` | Demo account login |
| `DI_PASS` | (from `.local/qa-creds.env` → `VIDEO_DEMO_PASS`) | Demo account password — never hardcoded (public repo) |
| `ELEVENLABS_API_KEY` | *(required for TTS)* | ElevenLabs API key |

Demo credentials are a dev-only shop (`product_type=di`, subscription=Enterprise).
Override with env vars for other environments.

---

## What stays out of git

```
.local/video-studio/di-promo/di-promo.mp4   # final output
.local/video-studio/di-promo/take.webm       # ~4 MB B-roll
.local/video-studio/di-promo/register-take.webm
.local/video-studio/di-promo/tts/            # ~5 MB MP3s
.local/video-studio/di-promo/cards/          # PNGs
.local/video-studio/di-promo/pieces/         # intermediate mp4 pieces
```

All of `.local/` is gitignored (`.gitignore` entry).
