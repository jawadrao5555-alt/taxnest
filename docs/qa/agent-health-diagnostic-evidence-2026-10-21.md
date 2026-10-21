# Agent Health diagnostic evidence — 21 October 2026

## Incident and live limitation

The owner requested read-only diagnosis of Admin offline-agent warnings and a
separate PR for verified software defects. Issue #233 requested exactly
`AGENT_HEALTH`; Actions run 39578683658 is waiting for Environment production
review. No live heartbeat/version report or redacted Agent logs have been
received. The physical cause of the reported shops' warnings remains UNKNOWN.
This PR fixes diagnostic reporting defects; it does not claim to fix those
live outages or change their warning behavior.

## Reproduced defects

1. Execute the actual standalone LiveOpsOwnerReportFormatter with an
   AGENT_HEALTH envelope containing enabled=2, online=1, long_offline=1, a
   bounded detail row, timestamp and version. Before this patch the returned
   owner text is just `AGENT_HEALTH completed.`; measured evidence disappears.
   A local smoke assertion failed before the patch and passes afterward.
2. The fleet reader applied newest-first LIMIT before computing all counts.
   A SQLite SQL reproduction with a fresh and a six-hour-stale enabled POS
   company and detail limit 1 reports enabled=1, long_offline=0 although the
   matching fleet has enabled=2, long_offline=1. The Laravel regression test
   exercises the real diagnostic service with the same scenario plus disabled
   and out-of-product companies. Full integration execution is pending CI.

## Change and compatibility matrix

- Count the complete existing in-scope enabled-company query while retaining
  the bounded newest-first detail list and existing response keys.
- Add total/returned/truncated coverage; omitted rows cannot imply no outages.
- Format measured last heartbeat/version and separate per-counter observations.
  Missing observations and physical outage causes stay UNKNOWN.
- Keep company-scoped access and existing PRA/product/test-data exclusions.
- Keep legacy/no-device reports, multiple devices and a second tenant in tests.
- Retain the 2-minute online and 2-hour warning thresholds, silent-print gates,
  agent_enabled, printer routing, update behavior and stored settings.
- No commands, restarts, updates, print jobs, remediation approval, merge or
  deployment. Existing diagnostic report/audit recording remains unchanged.

## Validation

Passed locally:
- Actual formatter smoke before/after (PHP 8.3 isolated CLI, no app/vendor load).
- Scoped two-counter observations, version/timestamp rendering, UNKNOWN fallback
  and empty actions_taken smoke against the actual formatter.
- PHP syntax lint of both changed services and both regression test files.
- `bash scripts/tests/live-ops-daily-ops-check.sh`.
- `bash scripts/tests/live-ops-autonomous-check.sh`.
- `git diff --check`.

Runtime limitation: the supported application runtime is PHP >=8.4.1. Only an
isolated PHP 8.3 CLI was available for pure formatter/lint checks; application
requirements were not changed or bypassed. Full PHPUnit/MariaDB have not run
locally and must pass on exact PR HEAD in CI. Added tests are
LiveOpsAgentHealthReportFormatterTest and the fleet coverage/isolation cases
in LiveOpsDiagnosticsTest. No UI markup changed: browser flow is N/A for this
backend/text diagnostic change. Existing live warning UI still needs independent
acceptance once a real diagnostic report is available.
