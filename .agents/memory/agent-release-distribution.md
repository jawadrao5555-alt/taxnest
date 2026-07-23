---
name: Desktop Agent release distribution
description: How customers actually get PRA Sync Agent builds — GitHub Releases latest is the ONLY channel that matters; publishing is a manual step easy to forget
---

# Desktop Agent (PRA Sync Agent) release distribution

**The rule:** building the agent (`pra-agent/dist/`) does NOT ship it. Customers only get builds through GitHub Releases **latest** on the repo — `AgentManagementController::downloadAgent` redirects to the largest matching asset of `releases/latest` (600s cache `taxnest_agent_latest_release`), and the agent's electron-updater checks the same feed. After ANY agent build: publish a GitHub release (tag newer than the last) with `TaxNest-PRA-Agent-Windows.zip` attached, then `artisan cache:forget taxnest_agent_latest_release` on live.

**Why:** Jul 2026 — silent-printing agent builds (20260715-1/20260717-1) sat unpublished for a week; latest GitHub release was still April's v2026.1.1, so every customer (e.g. PIZZA MASTER) ran the April agent with NO printer-reporting code → Printer Settings dropdowns stayed empty ("No printers reported yet") while the agent showed Online. Fixed by publishing v2026.2.0 with the July zip.

**Gotchas:**
- The 112MB zip is NOT in git on live — live's `pra-agent/dist/...zip` was a 134-byte pointer (the local-fallback download path served a corrupt file). If you rebuild, scp the real zip to live too (`public/downloads/...zip` is a symlink to it).
- electron-updater auto-update needs `latest.yml` + NSIS exe in the release; we publish zip-only → old agents' "Check Updates" will NOT auto-install. Customers must re-download from Agent Setup (`/pos/agent`) and run `install.bat` (settings survive).
- Release publish works with the token embedded in the workspace git origin URL (admin on the repo); the `github` connector has no connections. Read it from `.git/config`, never print it.
- Diagnosing "agent Online but printers empty": check `companies.pos_printer_settings.printers_reported_at` — NULL + fresh `agent_last_seen` = old agent build (reporting ships on start + every 5 min in builds ≥20260715-1).
