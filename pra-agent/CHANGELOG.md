# TaxNest PRA Sync Agent — Changelog

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
