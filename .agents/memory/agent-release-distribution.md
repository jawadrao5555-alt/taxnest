---
name: Desktop Agent release distribution
description: How customers actually get PRA Sync Agent builds — GitHub Releases latest is the ONLY channel that matters; publishing is a manual step easy to forget
---

# Desktop Agent (PRA Sync Agent) release distribution

**The rule:** building the agent (`pra-agent/dist/`) does NOT ship it. Customers only get builds through GitHub Releases **latest** on the repo — `AgentManagementController::downloadAgent` redirects to the largest matching asset of `releases/latest` (600s cache `taxnest_agent_latest_release`), and agents ≥v1.3.0 self-update off the same feed. After ANY agent build: publish a GitHub release (tag newer than the last) with `TaxNest-PRA-Agent-Windows.zip` attached, then `artisan cache:forget taxnest_agent_latest_release` on live.

**Self-update (v1.3.0+, Jul 2026):** electron-updater is REMOVED. The server piggybacks `agent_update` {version, tag, zip_url, zip_size} on BOTH heartbeat responses (PRA + FBR); the agent compares versions client-side (server always sends latest info — that's intentional), downloads the zip, verifies size, PowerShell Expand-Archive, then a detached `apply-update.cmd` robocopy-swaps files after the app quits. One attempt per version per run. Only tags matching `^v?(\d{1,2})\.(\d+)\.(\d+)$` (major ≤ 99) are advertised — date-tags like v2026.2.0 can never trigger an update loop. Release asset MUST stay named `TaxNest-PRA-Agent-Windows.zip` with top-level folder `TaxNest-PRA-Agent/` (win-unpacked contents + install.bat). New release checklist: `--dir` build (no wine needed — NSIS/32-bit exec impossible in this container), bump package.json version with a HIGHER semver (agent-style, not date-style), zip, publish release, cache:forget on live. v1.2.0-and-older installs have NO self-update — they need ONE more manual install.bat run.

**Why:** Jul 2026 — silent-printing agent builds (20260715-1/20260717-1) sat unpublished for a week; latest GitHub release was still April's v2026.1.1, so every customer (e.g. PIZZA MASTER) ran the April agent with NO printer-reporting code → Printer Settings dropdowns stayed empty ("No printers reported yet") while the agent showed Online. Fixed by publishing v2026.2.0 with the July zip.

**Gotchas:**
- The 112MB zip is NOT in git on live — live's `pra-agent/dist/...zip` was a 134-byte pointer (the local-fallback download path served a corrupt file). If you rebuild, scp the real zip to live too (`public/downloads/...zip` is a symlink to it).
- Pre-v1.3.0 agents (electron-updater era) cannot auto-install zip-only releases — those customers must re-download from Agent Setup (`/pos/agent`) and run `install.bat` once (settings survive); after that, self-update takes over.
- Agent Setup page (`agent.blade.php`) picks the Windows button by asset type: exe → installer button, zip-only → zip becomes the PRIMARY button ("Portable — no installer needed"); "Build in progress" only when NEITHER exists.
- Release publish works with the token embedded in the workspace git origin URL (admin on the repo); the `github` connector has no connections. Read it from `.git/config`, never print it. Replacing an asset = DELETE old asset id, then re-upload (uploads.github.com).
- `npx @electron/asar extract-file app.asar main.js` writes into the CURRENT DIR — run inside pra-agent/ and it OVERWRITES the source file (then a careless `rm` destroys it). Extract with `--out /tmp/...` always. Also: the asar's package.json is STRIPPED (no scripts/build config) — never "restore" source package.json from it; rebuild from git HEAD instead.
- electron-builder 26 rejects `win.signingHashAlgorithms` (schema error "configuration.win should be one of these: null" — the message never names the bad key). Keep it removed; `signAndEditExecutable: false` is the supported way to skip signing.
- Agents ≥v1.3.0 host-pin zip_url: only `https://github.com/jawadrao5555-alt/taxnest/releases/download/` is accepted — if the repo ever moves/renames, self-update breaks silently until a manually-installed build updates the pin.
- Diagnosing "agent Online but printers empty": check `companies.pos_printer_settings.printers_reported_at` — NULL + fresh `agent_last_seen` = old agent build (reporting ships on start + every 5 min in builds ≥20260715-1).
