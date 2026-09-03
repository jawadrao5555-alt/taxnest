# TaxNest PRA Sync Agent — Changelog

## Unreleased — Local Core foundation

- Added a default-off, pure-Node Local TaxNest Core foundation behind both the existing Offline Mode switch and an authenticated per-company/device heartbeat rollout gate: authenticated encrypted append-only journals, durable ordered cloud outbox, scoped/idempotent events, fail-closed key handling, torn-write recovery, authenticated same-install backups, health telemetry, and clean shutdown.
- Immutable events can enter only through the owning Electron POS window's authenticated IPC bridge; no sensitive Core HTTP/LAN route was added. Cloud draining uses the heartbeat-authenticated agent credentials and stops when the rollout/device gate is removed.
- This is storage and lifecycle groundwork only. It does **not** claim full offline POS, expose sensitive LAN APIs, or change established waiter/Caller ID behavior when Offline Mode is off.

## v1.11.0 (2026-08-29)
**LAN Mode: juday huay devices ab naam ke sath dikhte hain, har ek alag hataya ja sakta hai**

- Pehle agent window sirf ginti batata tha ("Juday huay devices: 3"). Kaunsi device hai, ye pata nahi chalta tha — aur ek ghalat device hatane ka wahid tareeqa "sab bhool jayen" tha, jis ke baad baqi tamam devices dobara pair karni parti thin.
- Ab har device apni line par hai: device ka naam, uska kaam (waiter tablet / Caller ID phone / counter), aur uske samne "Hatayen". "Sab bhool jayen" pehle ki tarah mojood hai.
- Device ka shanakhti number uske pairing token ka one-way hash hai — token khud na window mein dikhta hai, na kisi jawab mein jata hai.
- Ye fehrist sirf agent ki apni window mein hai. LAN par iska koi address banaya hi nahi gaya, is liye shop ke WiFi par lagi hui koi cheez shop ki devices ginn nahi sakti (ek test isi baat ki pehradari karta hai).
- Device hatane se pairing code nahi badalta — code waise hi ek dafa ka hai, aur badal dene se wo code kharab ho jata jo owner ne abhi kisi ko likhwaya ho.
- Sath hi: naya `build-win.bat` — Windows PC par sirf double-click karne se installer ban jata hai, command type karne ki zaroorat nahi.

## v1.10.0 (2026-08-29)
**LAN Mode: ab tablet/phone par asal pairing screen khulti hai (Task 1533)**

- **Nayi pairing screen**: LAN Mode chalu hone par agent jo address batata hai (masalan `http://192.168.1.17:8531`) — usay tablet ya phone ke browser mein kholne par ab NestPOS ka apna, phone-size pairing page khulta hai. Pehle wahan sirf `{"ok":false,"error":"pair_required"}` jaisa raw JSON aata tha, is liye koi device pair ho hi nahi sakti thi.
- Page par 6 hindson ka code, device ka naam aur device ka kaam (waiter tablet / Caller ID phone / counter) poochha jata hai. Sahi code daaltay hi device pair ho jati hai, agent window mein "Juday huay devices" barh jata hai aur agle device ke liye naya code ban jata hai.
- **Ek dafa pair, hamesha pair**: wohi device dobara yeh address kholay to seedha uska status page khulta hai — code dobara nahi maanga jata.
- **Status page**: agent version, is device ka naam, server theek chal raha hai ya nahi, aur is PC par aayi hui recent calls (khud ba khud refresh hoti hain). Sath hi "Is device ko hata dein" — device khud ko unpair kar ke naye sire se pair kar sakti hai.
- **Har masla saada jumlay mein**: ghalat code, bar bar ghalat koshishein (10 minute ka lock), LAN Mode band, server band, aur shop ke network se bahar se aaya hua request — sab ka jawab ab ek chhota sa Roman Urdu jumla hai, JSON blob nahi.
- **Security waisi hi**: pair honay se pehle page shop ka naam, device count ya code — kuch nahi batata; page ka poora CSS/JS isi server se aata hai (bahar se kuch load nahi hota); JSON API, uske CORS rules aur counter ka caller-popup lane bilkul pehle jaisa hai.
- Agent window ka LAN card ab saaf batata hai ke device par kya hoga: address kholein → page code maangega → wohi code daal dein. Address aur code dono bara kar ke dikhaye jate hain.

## v1.9.1 (2026-08-19)
**Setup-form Save hardening — unchanged re-save never re-activates silent printing**

- The setup form now remembers which printer was already saved when it opened. Clicking **Save without changing the dropdown** posts `explicit=false` — so a shop whose owner deliberately turned silent printing OFF can never be flipped back ON by a routine config re-save. Only a genuinely NEW/changed real-printer pick activates printing.
- Server side enforces the same rule independently (defense in depth for v1.9.0 agents already in the field): an explicit save that posts the exact printer the device already has saved, while the shop is silent-OFF, is treated as a no-op.
- **Self-update CWD-lock fix**: the updater script used to relaunch the new agent from *inside* its temp update folder, so the running agent locked that folder and every LATER update failed with EPERM until the PC rebooted (this is why some shops silently stayed on old versions for weeks). Fixed three ways: unique per-attempt work folder (never needs to delete a locked one), the updater script now `cd`s to the install dir before relaunching, and the agent moves itself to its install dir on startup. Already-stuck old agents heal on their next PC/agent restart.
- No other changes — v1.9.0 features (per-counter routing, PC Name, printer picker) unchanged.

## v1.9.0 (2026-08-18)
**Per-counter printer routing + PC Name + setup-form printer picker — har cashier ka apna printer (Task 1166 + 1182 + 1187)**

- **Persistent device identity**: every install now generates a one-time random device UID (stored with the config) and reports it + the PC hostname on heartbeat, printer reports, print-job polling and job results. Multi-counter shops (same company key on several PCs) appear as separate named "Counters" on the Printer Settings page.
- **Own-counter claiming**: a device-aware agent claims ONLY jobs stamped for its own counter plus unstamped company-wide jobs — two agents no longer race for each other's bills. Old agents/servers keep exactly the old behavior (UID simply ignored).
- **New: PC Name field** — the agent setup form now has an optional "PC Name" field (e.g. "Counter 1", "Manager PC"). The shopkeeper fills it in once during setup; the Printer Settings page then shows each counter's card under that friendly name instead of the cryptic Windows hostname. The admin can also rename any device directly from the Printer Settings page (useful for shops whose agents predate this field).
- **New: Receipt Printer picker in setup form** — below the PC Name field, the setup form now lists all printers installed on this PC (populated on load + manual ↻ refresh). Virtual/software printers (PDF, XPS, OneNote, Fax) are separated and clearly labelled so they are never accidentally picked. Choosing a real thermal printer and saving activates silent receipt printing immediately — no visit to the panel Printer Settings page required. The choice is stored on this device's card so the admin/owner sees "Counter 1 → XP-80C" on the Printer Settings page without doing anything extra. Precedence is preserved: blank/unchanged dropdown = no-op (never wipes a printer the admin already set); an admin panel edit survives agent restarts; a shop that deliberately turned silent printing OFF is only re-enabled by a fresh explicit printer pick, never by an unchanged re-save.
- Purely additive: single-PC shops and old agents see zero change and need zero reconfiguration. Leaving PC Name and the printer dropdown blank keeps today's behavior.

## v1.6.2 (2026-08-08)
**Instant silent printing (long-poll)**

- Print-job polling switched from a fixed 2s interval to a **long-poll loop**: the server holds each poll open (up to 8s) and answers the moment a receipt/KOT job is enqueued, so printing starts about a quarter-second after the cashier hits Print (ZFC request — "thora instant karwa dein").
- Fully backward/forward compatible: old agents on the new server behave exactly as before; the new agent on an old server falls back to a 1.5s poll (never tight-loops).


## v1.6.0 (2026-07-29)
**FBR POS window + print-bridge hardening (GA prep)**

- **New: "Open FBR POS Screen" in the tray** — FBR POS shops get their own desktop window for the /fbr-pos/ panel, with its own saved login (separate from the PRA POS window), keep-alive hide-on-close and the same offline fallback page. Purely additive — PRA POS window and agent untouched.
- **Print-window mutex**: the silent-print queue and the in-app print bridge share one hidden window; concurrent calls are now serialized so two jobs can never race and print the wrong content.
- Server-side: agent key auto-generation made race-safe (two simultaneous first-logins can no longer generate conflicting keys).


## v1.5.3 (2026-07-26)
**Offline Mode telemetry — admins can monitor it remotely**

- **Heartbeat now reports Offline Mode status**: every 30s beat tells the server whether NestPOS Desktop Offline Mode is ON and when the sale-screen snapshot was last captured. The /pos/agent page and the SaaS admin panel show it — a shop running on a stale snapshot is now visible without visiting the PC.
- Purely additive telemetry: old servers ignore the extra fields, and a telemetry failure can never break the heartbeat itself.
- Internal hardening: POS window force-close flag now resets in the `closed` handler (future-proofing, no behavior change).

## v1.5.2 (2026-07-26)
**New: NestPOS window stays loaded — instant open, no repeated loading**

- **Closing the POS window now HIDES it instead of killing it**: the sale screen stays fully loaded in the background (login, screen, data sab qaim). The next "Open POS" / NestPOS icon click brings it back INSTANTLY — no reload, no waiting, works like a real offline desktop app.
- **Auto-refresh on updates**: every time the window comes back on screen it silently verifies freshness with the server — if we deployed an update (new features, product/price changes) while it was hidden, the screen reloads ONCE and picks everything up. It NEVER refreshes in the middle of a sale (existing busy-guard).
- Background timers are no longer throttled while hidden — incoming waiter orders + the offline bill queue keep syncing at full speed.
- A one-time notification explains that NestPOS is still ready in the background. Quitting the agent from the tray still closes everything for real.

## v1.5.1 (2026-07-25)
**Fix: blank silent prints on some Windows PCs**

- Hardware acceleration is now OFF for the hidden print window — on PCs where the GPU/driver produced BLANK receipts/KOTs from silent printing, prints now render correctly.

## v1.5.0 / build 20260725-1 (2026-07-25) — BETA 4
**New: agent follows the POS login (auto company switch)**

- **The agent now follows whoever logs into the POS window**: when a DIFFERENT company logs in, the agent automatically fetches that company's Server URL + API key and reconfigures itself — no manual copy-paste, ever. Logging back in as the previous company switches it back the same way.
- Same company logging in again = no change (zero writes). If the company's API key was regenerated on the server, the agent now self-heals to the new key on the next login.
- **Windows notification on switch**: when the agent moves to a different company, a desktop notification announces it ("Agent ab ... ke liye chal raha hai") so staff on fiscal-device shops notice that PRA sync + printing ownership changed.
- Implementation: seeing the POS login page re-arms the auto-config check (race-safe via a generation counter); the config fetch is a read-only GET with the session cookie (server pins PRA routing exactly as before).

## v1.5.0 / build 20260724-4 (2026-07-24) — BETA 3
**New: NestPOS installs as its OWN app (Desktop icon + own taskbar identity)**

- **First "Open POS" click installs NestPOS as a separate app**: a **NestPOS** icon is added to the Desktop and the Start Menu automatically (one-time; also re-creatable anytime via tray menu → "Add NestPOS Icon to Desktop"). Double-clicking that icon opens the POS screen directly — the agent stays quietly in the tray.
- **Own taskbar identity**: the POS window now shows at the bottom (taskbar) as **NestPOS** with its own icon — grouped separately from the agent, and it can be pinned to the taskbar; the pin relaunches straight into the POS.
- **Single-instance guard**: launching the NestPOS icon (or the agent again) while the agent is already running no longer starts a second copy — it opens/focuses the POS window in the running app (prevents double heartbeats / double prints).
- New NestPOS icon (`nestpos.ico`, from the NestPOS PWA logo) shipped next to the app for shortcuts + window icon.
- Exe name/path unchanged — self-update keeps the new shortcuts valid.

## v1.5.0 / build 20260724-3 (2026-07-24) — BETA 2
**New: zero-setup agent + smarter offline sync**

- **Agent auto-config**: the POS window now opens even on a fresh, unconfigured install (default server) — and right after the cashier logs in, the agent automatically fetches this company's Server URL + API key from the server and configures itself. Silent printing + sync start working with ZERO manual setup. Existing configured agents are never overwritten.
- **Remember me pre-ticked**: the POS login page detects NestPOS Desktop (user-agent tag) and pre-ticks "Remember me" so the login survives restarts by default.
- **Offline bills keep their real date & cashier**: bills queued offline now carry the original sale time and the cashier who rang them up; the server books them under that time (clamped to the last 3 days) and credits the right cashier after sync.
- **Poison-bill cap**: an offline bill rejected by the server 50 times stops auto-retrying (stays safely on the device for support) so it can never block or spam the queue.
- **Product images offline**: the snapshot now also saves product images (from the embedded catalog), so the sale screen shows pictures while offline (size caps still apply).

## v1.5.0 / build 20260724-2 (2026-07-24) — BETA
**New: Offline Mode (Beta) — billing without internet (NestPOS Desktop)**

- New **Offline Mode (Beta)** toggle on the NestPOS Desktop card (default OFF). When ON:
  - Every successful online sale-screen load saves a local snapshot of the screen (with the logged-in cashier's full product catalog, prices and settings) plus its static assets to this PC.
  - If the internet goes away — even across a PC restart — the POS screen still opens from the snapshot and billing keeps working; bills are stored in the existing in-page offline queue and sync automatically (duplicate-proof) as soon as the internet returns.
  - An amber "Offline mode" pill shows on the screen with the snapshot's last-update time and a retry button; the window auto-reloads when connectivity returns.
- Implementation: passthrough-first HTTPS interception on the POS window's session (network first, snapshot ONLY on network failure, same-origin always) — with the toggle OFF, behavior is completely unchanged.
- Limits: first login needs internet; PRA fiscal numbers attach after sync (offline receipts show the local number); prices/settings changed while offline appear after reconnection; restaurant kitchen/waiter multi-device flows are not offline yet.
- Sync agent + silent printing untouched.

## v1.4.0 / build 20260724-1 (2026-07-24)
**New: NestPOS Desktop — full POS screen inside the agent ("Rasta B")**

- New **Open POS Screen** button (agent window + tray menu): opens the live TaxNest POS sale screen in its own desktop window — billing, printing and PRA sync on one PC, no browser needed. Login persists across restarts (persistent session; tick "Remember me" on the login page).
- **Kiosk mode** (optional, off by default): full-screen POS for shop counters. Toggle from the agent window, the tray menu, or **Ctrl+Alt+K** inside the POS window.
- **Open on startup** (optional): the POS screen opens automatically when the shop PC boots; the agent settings window stays in the tray.
- Offline fallback page (Roman Urdu) with auto-retry every 15s when the server is unreachable on load; once the sale screen is open, the existing in-page offline bill queue keeps billing through outages.
- Same-origin popups open in-app (same session); external links (e.g. WhatsApp) open in the system browser.
- New `window.nestposDesktop` bridge in the POS window (desktop detection + silent `printHtml` hook) — groundwork for dialog-free receipt printing from the web app in a future server-side deploy.
- The sync agent + silent printer routing are completely untouched — the POS shell is additive and opt-in; existing shops upgrade in place via self-update with zero behavior change until they use the new buttons.

## v1.3.0 / build 20260723-2 (2026-07-23)
**New: zip-based self-update**

- Agents update themselves automatically from GitHub Releases via the server heartbeat (`agent_update`) — download, verify size, extract, robocopy swap, relaunch. One attempt per version per run.

## v1.2.0 / build 20260717-1 (2026-07-17)
**New: FBR IMS one-stop setup (Fiscal Device mode)**

- New "FBR IMS Fiscal Service" card on the agent window: live badge shows whether FBR's IMS service is running on this PC (checks `localhost:8524` every 60s, plus a manual Re-check button).
- One-click **Install FBR IMS**: the agent downloads `FBRIMS.zip` from FBR's official server (`download.fbr.gov.pk`), extracts it, and launches the FBR installer automatically — no separate manual download needed.
- On-screen activation guide (POS Registration No + IRIS Access Code + Production) shown whenever the service is missing.
- Note: FBRIMS remains FBR's own software running as a separate Windows service — the agent installs and monitors it, it does not replace it.

## v20260418-5 (2026-04-18)
**Fix: Offline status issue + reliable callback retry + self-healing sync**

- Eliminates false "Offline" badges for invoices when the desktop agent is enabled
- New persistent callback queue at `~/.taxnest-pra-agent/pending-callbacks.json` — failed `submit-result` POSTs are saved to disk and replayed on every heartbeat (50-retry cap, dedup by transaction_id). No PRA result is ever lost, even if your server is briefly unreachable.
- Self-healing sync: heartbeat now reads `stuck_transaction_ids` from the server and triggers an immediate sync cycle if anything is stuck.
- Update feed switched to GitHub Releases `latest` so future builds are picked up automatically.

## v20260418-4
- Initial public release of the desktop sync agent.
