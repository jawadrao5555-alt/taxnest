# Whole-SaaS diagnostics bulk — P1

Base: 363a0d6823eae113336a6cd6069f160fc7a1a441 (merged #362). Priority: P1, owner requested 7 November 2026.

This is an implementation workstream opened as a Draft. Initial content records source reconciliation and acceptance; runtime implementation and acceptance are not complete. No approval-ready or deployed claim follows from documentation CI.

Source trackers: #337 / #319. Delivery order: second batch after billing; may be developed independently where paths do not overlap.

## Current source evidence

- Reporting/DiagnosticCoverage declares partial capabilities and explicit missing operations for DI connection, FBR POS operations, Healthcare, ERP and shared/admin families. It correctly does not claim tenant health from route inventory.
- #327/#349 already supply source reconciliation, retained print attempts and pairing-bound offline Agent evidence. Reuse these paths and preserve unknown/uncertain states.
- PraAgentDiagnosticEvidence and LiveOpsDiagnosticsService are existing integration points. No new unrestricted SQL, shell or parallel authentication surface is authorized.

## Implementation acceptance

- [ ] Build a versioned adapter registry using existing scoped report infrastructure; register actual supported product/source operations and explicit unavailable gaps.
- [ ] Report earliest/latest event and receipt time, retention window, sequence gaps, clock skew, upload failure, capacity and completeness per source.
- [ ] Extend durable bounded collection to remaining applicable streams; seven-day completeness requires observed source evidence, not just a retention setting.
- [ ] Correlate bill/order/KOT/print/payment/stock/fiscal attempts with real identifiers; preserve deleted/archived source uncertainty.
- [ ] Provide company/product/branch/business-day typed read-only reports with pagination and bounded exports.
- [ ] Distinguish disabled, no activity, stale, unknown and failed; a healthy state requires fresh applicable positive evidence.
- [ ] Show actionable failed checks/jobs and exact release evidence without automatic replay, customer messages, settings changes or deployment.
- [ ] Test all adapters, second tenant, revoked scope, future/incompatible schema, offline restart/gaps, retention expiry, bounded load and report failure isolation.

## Verification and preservation

Synthetic seven-day timelines prove algorithms only. Installed-device collection, physical printing and field soak remain separate evidence. Collection failure must not alter checkout, fiscal queues or printing. Required hosted PHP/native/browser/Agent gates must verify final HEAD.

## First implementation increment — retained source metadata

Implemented a version 1 allow-listed source registry and scoped reporting tool
for PRA logs, DI logs (tenant joined through invoices), FBR POS logs and
Healthcare audit timestamps. Existing OAuth scope, read auditing and revocation
are reused. Exact company resolution also supports ERPS without expanding the
older billing tools. No arbitrary table/query input or payload serialization.

The report permits at most seven calendar dates and 50 records per ID page.
It distinguishes missing source/schema from empty retained windows and reports
only IDs/timestamps with explicit unknown collection/deletion/sequence/skew
coverage. Page extents are not global retention extents. Missing DI parents
cannot be attributed. This does not implement the broader collector, retention,
offline buffering or seven-day completeness acceptance above.

Local PHP8.3.6/SQLite: 34 tests /391 assertions passed across the source,
authenticated OAuth/MCP and Day Close suites before the final registry display
addition. Coverage includes real HTTP scope denial/revocation, pagination,
PRA/DI/FBR/Healthcare storage shapes, missing/empty sources, timezone boundaries,
payload exclusion and unchanged source rows. Hosted PHP8.4/native/full suite and
browser acceptance remain required. No production access or business writes.

## Durable derived source observations

Added an additive, versioned observation ledger and daily bounded collection
for the four registered source adapters. Each run stores only source presence,
count and timestamp extents for the previous business date; no stored payload,
patient/customer data, token or diagnostic exception is copied. Collection
uses100-company batches and product/tenant attribution (including legacy
Healthcare spelling). Same-run retries use a unique identity; later runs retain
separate readings, preserving late-arrival evidence rather than overwriting it.

The scoped MCP source report now includes the latest applicable observation.
Missing source, empty retained source, query failure, missing observation and
incompatible observation schema stay distinct. A failed observation read does
not erase the independent source result. None of these observations claims
complete original collection, healthy operation, regulator acceptance or
seven-day field completeness. The daily command performs no messages, fiscal
requests, settings changes or business writes. Its retention deletes only
derived observations older than seven days; original source records are never
pruned by this collector. Query/storage failures produce a failed command.

Local PHP8.3.6/SQLite:57 reporting tests/524 assertions passed, including scoped
counts/timezone, same-run retry, retained late-arrival readings, missing vs
empty, old Healthcare identity, scheduled retention with unchanged original
records and an incompatible observation schema. Added a mandatory native
MariaDB two-worker observation probe for exact idempotency, second-tenant
exclusion and byte-for-byte source-row preservation. Syntax/diff checks pass;
supported PHP8.4, native and full required CI must verify the published HEAD.

Remaining: adapters beyond these four, source event/receipt correlation,
per-device sequence/clock/capacity evidence, remaining offline collection and
other broad acceptance above. Derived daily observations do not fill these
gaps. No installed-device seven-day soak or physical/fiscal success is claimed.
Owner's delivery decision remains: finish363/364/365, then combine on latest
main and run fresh combined acceptance before one owner-approved bulk release.
