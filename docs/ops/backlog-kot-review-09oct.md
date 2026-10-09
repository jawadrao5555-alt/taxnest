# KOT backlog review — 9 October 2026

## Finding and scope
Issue #63 is still open, but the main branch already contains the fixes merged in PRs #64/#65 (local hand-back, duplicate-safe Action Required, watchdog and bounded agent pickup). Do not infer an active fault from the issue state, or claim physical paper delivery from a queue acknowledgement.

This focused verification PR adds coverage without changing printer routes, saved settings, agent contracts, fiscal data or recovery policy.

## Compatibility matrix
| Configuration | Behavioral check |
|---|---|
| Timer and realtime wake overlap, one local kitchen printer | Two concurrent drain calls print 16 distinct durable intents exactly once and create one durable acknowledgement each |
| Working kitchen + failed optional counter printer | Kitchen succeeds once; counter failure never retries the successful kitchen ticket |
| 25 cloud orders, two capable cashier devices | Three actual Agent HTTP claim batches (10/10/5) contain all intents once; repeated enqueue is idempotent |
| Case/space printer spelling difference | Both capable devices retain eligibility; stored target printer snapshots remain unchanged |
| Second tenant | Its queued KOT is never claimed by the first tenant |
| Legacy agent, reconnect, uncertain content/transport, watchdog | Existing KotZeroLossInstantPrintTest and local-kot cases remain mandatory |

## Evidence
- PHP and Composer are not installed in this workspace; local MariaDB/Laravel reproduction is unavailable. The isolated PR CI must execute the HTTP regressions and full suite.
- Before additions: `node pra-agent/test/local-kot.test.js` PASS on main.
- After additions: same command PASS, including the overlapping 16-intent drain and failed-counter scenario.
- No browser code changed. Existing isolated Chrome journeys remain required CI; these new queue scenarios are exercised through the real local domain and Laravel Agent HTTP API.
- The 25-order test models interleaved workers, not simultaneous database transactions. The existing native MariaDB concurrency gate remains required.
- This is verification coverage for an existing fix, not evidence that every current shop has installed the updated Windows Agent. Actual installed version, OS spooler and paper output remain owner live checks.
