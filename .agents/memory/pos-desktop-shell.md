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

**Other deferred (owner not yet asked):** printer picker in the shell, auto-login, an FBR POS window, What's New announcement row for the desktop app.

## Offline Mode (Beta, v1.5.0) — `src/offline-snapshot.js`
Cold-start offline for the sale screen. The web app's IndexedDB bill queue + `offline_uuid` dedupe already handle mid-session outages; the shell only fixes "page won't load".
- **Passthrough-first**: `ses.protocol.handle('https')` on persist:pos forwards EVERY request to the network; the disk snapshot is served ONLY when the fetch throws AND the setting is still ON (`isEnabled()` recheck). Toggle OFF at window open = handler never registered = byte-identical behavior.
- **Same-origin forever**: never serve from file:// or a custom scheme — IndexedDB (offline bill queue) is origin-scoped; a different origin orphans queued bills.
- **Capture fetches MUST pass `bypassCustomProtocolHandlers: true`** or an offline cold start re-captures the snapshot FROM the snapshot (fresh savedAt on stale prices + stacked banners). Belt-and-braces: guard rejects any html containing `tn-offline-banner`. Login-redirect guard = final path + >50KB + sale-screen markers (res.url is unreliable under interception).
- **GET-only serving**: POST/PUT always surface as network errors so the page queues the bill; uncaptured html navigations 302 to the sale screen; uncaptured XHR = real error.
- Snapshot lives in `userData/pos-offline-snapshot/` (page.html + hashed assets + manifest.json tmp+rename); throttle 10 min, 8s settle delay, caps 200 assets / 5MB each / 60MB total; `/sw.js` never captured.
- **Beta distribution rule**: beta zips upload to live `public/downloads/` as `TaxNest-PRA-Agent-Windows-BETA.zip` (real file). NEVER touch the `TaxNest-PRA-Agent-Windows.zip` symlink or publish a GitHub release until owner approves — self-update auto-ships to live shops.
- Owner test script must cover: toggle ON while ONLINE (login POST redirect chain + one real bill submit — passthrough regression), then cable pulled: cold start, offline bill, reconnect sync.
- Phase 2 (BUILT, beta2): offline bills carry `offline_queued_at` + `offline_queued_by`; server honors them ONLY when `offline_uuid` present — timestamp clamped to [now-3d, now], user must belong to the same company else falls back to session user; `created_at` is NOT mass-assignable → set via explicit `$transaction->created_at=…; save()` AFTER create AND draft-promote paths (don't revert to mass assignment). Client poison cap: `tries>=50` = skip auto-retry (only server REJECTIONS increment; network errors break first). Phase 3 (BUILT): login blade pre-ticks remember when UA contains `NestPOSDesktop` (pos-window.js appends ` NestPOSDesktop/<ver>` to UA).
- Snapshot also captures embedded same-origin `/storage/` product-image URLs (`extractEmbeddedImagePaths`: unescape `\/` first, origin-checked, extension whitelist) so images render offline.

## NestPOS as a separate app (v1.5.0 beta3)
- POS window gets its OWN Windows taskbar identity via `win.setAppDetails({appId:'com.taxnest.nestpos', appIconPath, relaunchCommand '"exe" --pos', relaunchDisplayName:'NestPOS'})` in pos-window.js; main process keeps `app.setAppUserModelId('com.taxnest.pra-agent')`. Do NOT unify the two AppUserModelIDs — separate = separate taskbar group/pin.
- First "Open POS" click auto-creates Desktop + Start Menu `NestPOS.lnk` (`shell.writeShortcutLink`, target=process.execPath args=`--pos`, icon from `resources/nestpos.ico`) — one-time via store flag `posShortcutCreated`; tray item "Add NestPOS Icon to Desktop" re-creates. Shortcuts point at the SAME frozen exe → self-update zip-swap keeps them valid; never ship a second exe.
- `--pos` argv (launch + second-instance) opens the POS window directly, agent window starts hidden (`createWindow(startHidden)`).
- **Single-instance lock added** (`requestSingleInstanceLock`): second launch forwards argv and exits — prevents twin agents (double heartbeats/prints). Don't remove.
- `nestpos.ico`/`nestpos-256.png` generated from `public/icons/nest-pra/icon-512.png` (magick, depth 8); shipped via electron-builder `extraResources` → stable `resources/nestpos.ico` path (.lnk icons can't point inside app.asar).

## Agent auto-config (zero manual setup, v1.5.0 beta2)
- Unconfigured agent still opens the POS window at `DEFAULT_SERVER_URL` (live origin); after login, `pos-window.js` onLoggedIn (did-finish-load on a non-login /pos/ page) triggers `autoConfigureAgent(ses, origin)` in main.js — uses the POS session's cookies (ses.fetch) to GET `/pos/desktop/agent-config`, saves server_url/api_key + startAgent. NEVER overwrites an existing complete config.
- Server: `AgentManagementController::desktopConfig` (route `pos.desktop.agent-config`, pos.auth + company.approval). **CRITICAL PRA-routing guard**: `agentHandlesPra()` has a legacy `?? true` fallback — enabling the agent with NULL `agent_submits_pra` silently flips PRA submission to the agent. desktopConfig therefore ALWAYS pins `agent_submits_pra=false` on fresh-key generation AND when re-enabling with a NULL value; existing enabled key = zero writes. Never remove this pin.
- Post-beta hardening backlog (architect, non-blocking): desktopConfig is a state-changing GET (SameSite=Lax exposure) — UA-gate or POST-ify later; key generation has a benign last-write-wins race — make it a conditional `whereNull` update; consider snapshotting branch_id into offline payloads for multi-branch replay fidelity.
