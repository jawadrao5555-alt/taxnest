# TaxNest PRA Sync Agent — Changelog

## v20260418-5 (2026-04-18)
**Fix: Offline status issue + reliable callback retry + self-healing sync**

- Eliminates false "Offline" badges for invoices when the desktop agent is enabled
- New persistent callback queue at `~/.taxnest-pra-agent/pending-callbacks.json` — failed `submit-result` POSTs are saved to disk and replayed on every heartbeat (50-retry cap, dedup by transaction_id). No PRA result is ever lost, even if your server is briefly unreachable.
- Self-healing sync: heartbeat now reads `stuck_transaction_ids` from the server and triggers an immediate sync cycle if anything is stuck.
- Update feed switched to GitHub Releases `latest` so future builds are picked up automatically.

## v20260418-4
- Initial public release of the desktop sync agent.
