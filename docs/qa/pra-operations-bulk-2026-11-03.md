# PRA operational evidence: combined B06/B03 implementation

One implementation batch from planning #319 (B06 diagnostics) and the remaining
print/Agent evidence in #304 (B03), following merged #315/#327/#329/#334. Those
planning PRs remain requirements; this does not complete all of either plan.
Reused tree `2f0971f372d91fdc5d91702bce295713d0166f18` against its actual
baseline `f2e2fc005e951e6d3d42be26491c9ccb61db28e4`; applied only that delta
to current main `368c13febeabd8ea7fe421a2f5741ab406a3c435` (merged #348).
This is the prioritized three-part runtime batch tracked by #336, not completion
of every #336/#304/#319 requirement. Agent 1.13.35 preserves the newer
1.13.34 IMS discovery/acknowledgement and Caller ID fixes.

## Reproduction and root cause

The existing Day Close reader checks saved arithmetic only. A synthetic close
with cash 800, rider out 100, prior-day receipt in 50 and opening 0 correctly
passes its saved expected-cash check (750). Independent sale/return records net
900 cash: actual available sources imply 850, a -100 frozen/source difference.
The new test exercises the old reader and new reader on the same records.

A previous successful print attempt does not establish the next attempt's
outcome. A second content fetch without a result must remain OUTPUT_UNKNOWN.
Latest-only heartbeat settings also cannot answer a seven-day history query.

## Delivered behavior and boundaries

- `get_pra_operations_evidence` uses existing revocable, company-scoped OAuth.
  `cash_reconciliation` compares frozen closes with independently selected
  final PRA-mode sales, returns, card aliases, opening records, delivery/rider
  balances and dated settlement allocations. Archived rows remain visible.
  Money-from-folio payments/refunds and hotel credit-note refunds are counted
  once with their stored business-date/branch attribution. Provisionals are
  excluded; reporting-OFF finals remain included.
- `print_chains` compares retained enqueue, claim, content and result evidence
  per attempt, including partial, conflicting and missing outcomes. No automatic
  retry or print request is issued. Spool acceptance never proves paper.
- `agent_collection` shows retained operational samples, original device time,
  server receipt time, sequence gaps, reported pending/dropped/reset counts,
  stale observed streams and empty receipt days. It is not full activity logs,
  a device inventory or proof of seven uninterrupted days.
- New paired Agents negotiate support through the existing heartbeat. They
  sample every five minutes while running; a file bound to company, device,
  server and credential retains at most 4096 samples / fourteen days / 2 MiB.
  Batch uploads contain at most 50 samples. Exact persisted acknowledgements
  remove samples; offline failure and unacknowledged samples survive restart.
  A stale response after pairing change cannot consume either buffer.
- The server stores allowlisted numeric/boolean/enum observations only. No raw
  logs, buyer details, host/printer names, payloads or credentials are retained
  in these new diagnostic tables or exposed by these reports. Device identity
  is hashed; it is still operational metadata subject to retention.
- Sample retention is 30 server-receipt days, pruned in batches of 1000 every
  five minutes; empty inactive streams expire after 90 days in batches of 100.
  Scheduler operation/capacity must be verified after an approved deployment.
  Cleanup touches only these diagnostic tables.
- Reports select at most seven dates. Cash pages contain at most ten reports,
  each capped at 5000 bills, 200 receipts and 2000 allocation entries; allocation
  bill lookups are batched. Other pages contain at most 50 jobs/samples, print
  chains at most 500 retained events/job, and observed stream inventory 100.
  Limits/gaps are explicit, not successful zeroes. Receipt audit totals are
  labeled company-wide; prior-bill cash-in follows the selected close's branch.

## Compatibility and evidence

| Case | Evidence |
|---|---|
| Saved arithmetic hides source discrepancy | Old reader PASS and new -100 difference, same fixture; business rows unchanged |
| Working flow | Matching sources, zero opening, archived bill, return, card alias, partial rider payment, other branch |
| Missing/changed evidence | Deleted bill, later settlement, legacy undated receipt, missing schema, oversized allocations remain PARTIAL/UNAVAILABLE |
| Hotel accounting | Folio collection/refund, no double credit-note refund, foreign-company denial, unavailable schema gives null |
| Collection | Seven simulated offline days survive restart; retry deduplication, conflicting sequence, disk/corruption, caps, source clock skew and receipt-day gaps |
| Backward compatibility | Old Agent heartbeat, unsupported server, missing tables, fiscal-mode product exclusion, pairing isolation and stale response |
| Access | Actual authenticated heartbeat plus OAuth scope, strict filters, revocation, secret redaction and no business-table writes |
| Required native/browser | Existing desktop/390px consent journey extended with native cash -100, sample deduplication/gap, print-chain, foreign-company and revoke checks |

Current local validation (5 November 2026):
- PHP reporting/heartbeat suite: 59 tests, 512 assertions, passed on isolated
  in-memory fixtures. Includes the old-reader PASS versus independent -100
  difference and tenant/branch, ambiguous print attempt and sample gaps.
- Focused Agent diagnostic/buffer plus IMS acknowledgement/bridge/release suite:
  21 passed. The actual heartbeat harness isolates unrelated IMS discovery and
  bridge requests; those flows run under their own existing regression tests.
- Npm lock portability passed. Parsed lockfile comparison proves only two root
  version fields changed; all dependency entries and integrity pins preserved.
- Announcement parser, Node syntax and diff checks passed.
- Local PHP 8.3.6 is below the unchanged ^8.4.1 platform contract; full suite is
  delegated to required hosted PHP 8.4. No native MariaDB binary is installed.
  Native MariaDB, desktop/390px authenticated reporting journey, Electron and
  Windows packaging remain required hosted checks for the actual published HEAD.
  Do not treat this limitation as a pass or use historic tests as current evidence.

## Release and remaining scope

Migrate before Agent rollout. Old Agents/servers continue their existing flow;
new support starts buffering only after a capability acknowledgement. A failed
store does not acknowledge samples; retries do not submit invoices or print.
Report reads never change cash, bills, fiscal status or routing settings.

Owner approval/relay is required for merge/deployment. Installed Windows,
physical paper, real bank/drawer balances, seven-day field soak and deployed
OAuth/client acceptance remain separate evidence. Historical deleted rows,
complete route/job/feature coverage, and B03 staff/family-discount proposal and
approval remain pending; #304/#319/#301 must not be closed as complete here.
