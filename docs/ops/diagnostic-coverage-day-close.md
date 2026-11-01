# B06 phase 1: coverage gaps and frozen PRA cash evidence

This implements the first runtime slice of #310 / #319. It does **not** complete
A-to-Z diagnostics, the seven-day retention contract, printing complaints, or
the reported cash-balance incident. #301 remains the bulk-work tracker.

## What changed

Previously COMPANY_DIAGNOSTIC collected billing, PRA, printer and agent signals
without Day Close evidence; no obvious operational fault could produce GREEN
or RESOLVED. The direct OAuth reporting connector had no Day Close tool.

`get_pra_day_close_evidence` now uses the existing revocable connection and
company grant. It reads one stream at a time: `reports`, `openings`, or
`counter_closes`. Supply company name (and optional ID), up to 31 inclusive saved
business dates, optional branch/counter scope, and `after_id`/`limit` (1–50).
Branch/terminal zero are real legacy/no-branch identifiers. Terminal filtering
is rejected for shop reports; use counter closes instead. Unknown filters fail.

The reader performs SELECTs only. OAuth audit rows and existing Live Ops report
storage remain intentional administrative writes. It does not close/reopen a
day, recreate historical bills, retry fiscal submissions, adjust cash, change
settings, or infer historical cutoff from today's settings. Missing tables or
columns remain unknown. Missing closes do not prove an open/inactive day.

Stored report arithmetic is opening + net cash sales − rider cash out + prior
bill cash in; variance is counted − expected. These are consistency checks of
saved inputs, **not** independent validation of physical cash, payment buckets,
refunds, rider allocations or the original close calculation. Card/other totals
are reported separately and never added to cash. No input defaults to zero.
Separate counter-close rows lack rider inputs, so their expected-cash check
remains unavailable even when variance arithmetic is consistent.

JSON is capped at 64 KiB per field in the database SELECT. Only allow-listed
numeric snapshot values are returned; names, notes, receipt payloads, contact
details and credentials are excluded. Counter snapshots show at most 25 rows
and disclose truncation. The result includes all requested date buckets, the
full scoped match count, and cursor pagination. Do not sum shop, opening and
counter streams: they can describe the same cash.

COMPANY_DIAGNOSTIC retains these structured records in the owner artifact and
reports partial coverage. A saved arithmetic mismatch requests attention.
DAILY_OPS retains its operational status separately, exposes incomplete feature
coverage, and cannot label that incomplete coverage GREEN. Missing legacy
coverage metadata is not treated as proof. The autonomous engine cannot mark
an incompletely checked complaint RESOLVED merely because no operational fault
was found.

## Coverage inventory and remaining work

`get_diagnostic_coverage` returns 18 stable **feature-family** entries across
PRA, DI, FBR POS, Healthcare, ERP, admin and shared services. Available operations
are explicitly partial; missing readers are NOT_IMPLEMENTED. It performs zero
tenant checks. Product routing hints are not authorization boundaries.

Registered HTTP route templates are discovered without executing them and
paginated with a fingerprint/count. Only four exact PRA Day Close actions are
mapped to this partial reader; all other routes, including newly added actions,
remain UNMAPPED. This deliberately does not claim an exhaustive feature map.
Controllers/actions, settings, schemas, commands, jobs and offline clients still
need reviewed individual adapters. Route discovery cannot detect all of those.

| Next acceptance | Status in this slice |
| --- | --- |
| Independent cash/payment/refund/rider reconciliation | Not implemented; saved arithmetic only |
| Deleted bill/order evidence and printing timeline (#315) | Not incorporated; separate work |
| PRA restaurant, stock and accounts feature readers | Partial existing aggregates or not implemented |
| DI/FBR integration, retry and connection checks | Partial stored billing evidence; remaining readers pending |
| Healthcare/ERP/admin/support feature checks | Catalog gaps only |
| Durable seven-day event retention and disconnected uploads | Not verified/implemented by this change |
| Complete connected-client A-to-Z acceptance | Pending the remaining adapters |

Every current assessment remains incomplete. No percentage of total SaaS
coverage is claimed from these family counts. Normal diagnostic reads retain
the existing connection flow; there is no new per-query production approval.
Release and remediation authorization boundaries are unchanged.

## Verification

- Baseline reproduction at main `0aecc18`: the new connector regression failed
  because `get_pra_day_close_evidence` was not an available tool.
- Local PHP 8.4.23: final focused reporting/Live Ops suite passed with 123 tests
  and 735 assertions, including counter-only inconsistencies and agreement
  between the company card and report aggregate.
- Synthetic tests cover a seven-date range, cross-midnight saved close,
  multiple counters, no live bill table, unknown vs zero opening, rider gaps,
  arithmetic mismatch, branch-zero pagination, malformed/oversized snapshots,
  missing schema, product/tenant isolation, unsupported filters, bounds,
  unchanged business rows, OAuth audit/revocation and discovery of new routes.
- Extended the existing pinned MariaDB native probe and real desktop/mobile
  OAuth browser journey with frozen cash arithmetic, private-note exclusion,
  foreign company denial, coverage gaps, read evidence and revocation. These
  must pass in PR CI before handoff; no production browser/DB was used.
- Full-suite and exact-head CI results belong in the PR verification record.
  A local full-suite attempt was blocked by automatic approval review after
  an existing test attempted external PRA egress. It is not a passing run and
  is not retried outside isolation; full acceptance uses the existing
  loopback-only CI runner with external egress blocked.
  Code completion is not a claim that this is deployed or the cash complaint
  is resolved. After owner deployment, rerun the approved seven-day incident
  diagnostic and investigate its actual cash evidence.
