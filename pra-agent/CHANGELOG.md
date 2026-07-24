# TaxNest PRA Sync Agent — Changelog

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
