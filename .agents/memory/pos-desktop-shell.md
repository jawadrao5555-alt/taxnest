---
name: NestPOS Desktop shell (in the PRA agent)
description: Rules for the opt-in POS web-app window inside the Electron agent (v1.4.0+) — safety invariants, kiosk, print bridge, deferred work.
---

# NestPOS Desktop shell (agent v1.4.0+)

The Electron PRA agent doubles as "NestPOS Desktop": an opt-in BrowserWindow that loads the LIVE POS web app (serverUrl origin + /pos/invoice/create). The sync agent is untouched — the shell is purely additive.

**Rules / invariants:**
- `productName` / exe name **must stay "TaxNest PRA Agent"** forever — the self-updater locates the exe by that name; renaming bricks updates on live shops.
- POS window uses `partition: 'persist:pos'` (own cookie jar, separate from any future windows); sandbox + contextIsolation ON; preload = `src/pos-preload.js` exposing only `window.nestposDesktop` (desktop flag, printHtml, getVersion).
- Kiosk mode: settings toggle + tray checkbox; **Ctrl+Alt+K** toggles at runtime (before-input-event). Only works when the MAIN POS window has focus (not child popups) — known cosmetic limit.
- Offline fallback: `offline.html` (Roman Urdu, 15s auto-retry via `?target=`); loaded only on `isMainFrame && errorCode !== -3` did-fail-load.
- Child windows: same-origin → allowed in-app (same partition); external URLs → `shell.openExternal`, deny in-app.
- `pos-print-html` IPC is **sender-pinned** to the POS window's webContents — keep that check.

**Why:** owner picked "Rasta B" (grow agent into desktop POS) over a separate app; shops already run the agent, so self-update ships the shell for free.

**How to apply:** any change to the shell must keep agent sync paths byte-identical in behavior; test with the stubbed-electron module-load smoke test (GUI Electron cannot run in the Replit container — missing libglib; builds are `--dir` only).

**BEFORE wiring the web-side `nestposDesktop.printHtml` hook** (deferred): add a promise-chain mutex inside `printer.js printHtml` — the shared hidden print window has a loadURL race between queue jobs and bridge calls (architect flagged; currently unreachable since the web app never calls the bridge yet). Also note: receipt printing on the sale screen goes through a hidden iframe `fr.contentWindow.print()` — a whole-page silent print would be WRONG; v1 intentionally uses the native dialog + existing server print-queue.

**Other deferred (owner not yet asked):** Electron-side sale-screen caching, printer picker in the shell, auto-login, an FBR POS window, What's New announcement row for the desktop app.
