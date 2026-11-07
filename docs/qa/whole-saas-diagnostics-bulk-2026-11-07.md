# Whole-SaaS diagnostics bulk — P1

## Expanded retained evidence and explicit bill links (7 November continuation)

Registry v3 adds retained PRA order, IMS/DI submission, print-receipt and
offline-sample streams; DI sale rows; FBR callback/print receipts; Healthcare
payment/federal/stock streams. Both read and daily observation use identical
fixed product filters for shared print storage. Window-wide retained count and
timestamp extents are separate from paged extents. No source payload, amount,
patient/customer detail, raw error or token is selected for output. The PRA
offline source points to the existing scoped Agent collection operation for
sequence gaps, pending/dropped/reset/capacity and clock-or-upload-delay evidence;
it does not create a second collector or claim that upload delay is clock skew.

Bill evidence v2 adds explicit PRA IMS/DI attempts, order-only print events
through same-company order-to-bill identity, FBR callback/print evidence and
Healthcare charge/line/federal/bill-audit identities. Both parent and child
company constraints apply. Order-only evidence excludes already bill-linked
rows. Matching bill numbers/times/amounts are never substituted for IDs. A
single source QueryException yields SOURCE_QUERY_FAILED without erasing other
sources or returning SQL/exception content. An empty source stays distinct
from missing schema and failed reads.

Local PHP8.3.6/SQLite full Reporting: **69 tests /673 assertions passed**,
including new adapter tenant/product isolation, identical collection/read
filters, stable window extents across pagination, explicit federated and
order/print joins, Healthcare parent identity, payload exclusion, failure
isolation, OAuth scope/revocation and unchanged source rows. Syntax/diff checks
pass. The required native two-worker observation probe additionally verifies
IMS attribution and product-filtered print read/collection on the migrated
schema, with unchanged original bill and no private content returned. New
native/full supported PHP8.4/browser/validate results are required for the next
exact published HEAD. Local MariaDB/Chrome equivalence is not claimed.

Still open: per-bill offline event attribution (retained periodic samples have
no immutable bill identity), remaining clinical/pharmacy deep correlations,
shared/admin job/release/backup/support adapters, bounded exports/branch
acceptance and observed seven-day completeness. Field evidence is not
invented from synthetic tests. This increment does not complete #364 or make
the combined scope approval-ready. No operational/fiscal replay or business
records/settings changes were performed.

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

Remaining: adapters beyond the currently registered sources, source event/receipt correlation,
per-device sequence/clock/capacity evidence, remaining offline collection and
other broad acceptance above. Derived daily observations do not fill these
gaps. No installed-device seven-day soak or physical/fiscal success is claimed.
Owner's delivery decision remains: finish363/364/365, then combine on latest
main and run fresh combined acceptance before one owner-approved bulk release.

## Operational source adapter expansion

Registry version 2 adds direct, tenant-scoped retained-source adapters for PRA
sales, retained print jobs/attempt outcomes, inventory movements, FBR POS sales
and Healthcare bills. They reuse the same SELECT-only ID/timestamp contract and
daily derived observation ledger. No sale, payment, stock, print or fiscal row
is changed, and no payload, customer, patient, item or error text is serialized.

These adapters establish only retained row presence and time extents. They do
not yet correlate a bill to all payments, stock movements, print outcomes and
fiscal attempts; do not infer paper output, regulator acceptance, sequence
continuity or complete collection. Missing tables remain UNAVAILABLE and empty
windows remain NO_RETAINED_EVENTS. The broad checklist therefore stays open.

## Explicit bill/source associations

Added get_diagnostic_bill_evidence through the existing revocable OAuth/MCP
read path. Exact scoped company plus bill identity selects explicit retained
foreign-key/reference links: PRA payments, orders, print events, stock and PRA
attempts; DI fiscal attempts; FBR fiscal attempts/stock; Healthcare payments.
No matching-by-amount/time/number guess is made. Child tables without company_id
are scoped through the already verified parent; tables with company_id require
both the company and the parent link. Stock links require the correct reference
type, and print evidence requires its recorded product.

Each source returns only IDs, its own bounded cursor and explicit missing/empty
states. Deleted/missing parent bills are refused rather than reconstructed.
Payloads, payment amounts, customer/patient data and fiscal responses stay out
of this report. No business row changes, replay or acceptance inference occurs.
Order-only print linkage, federal IMS, offline events and additional streams
remain explicitly listed as unimplemented links. Scope completion remains open.

Local PHP8.3.6/SQLite full Reporting set:63 tests/586 assertions passed. Includes
all four products, foreign/inconsistent child attribution, cursor boundaries,
missing sources, exact/ambiguous identity, arbitrary-filter refusal and actual
authenticated MCP scope/audit/revocation with unchanged source rows. Full
PHP8.4/native/browser/validate acceptance must verify the next published HEAD.

## Registry v3/v4 continuation and native evidence

Registry v3 introduced retained PRA orders, IMS/DI submissions, print receipts,
offline samples, DI sales, FBR callbacks/print receipts and Healthcare
payments/federal/stock. Fixed product filters are identical in read/collection.
Bill v2 uses explicit same-tenant IMS/DI, order-only print, FBR callback/print
and Healthcare charge/line/federal/audit associations. Local full Reporting
69tests673assertions passed. Exactc724d0e1ecfa27db4b55a1d2a2dc1fcf7da3d12b
native job128472275758 passed two-worker observation uniqueness plus exact IMS
and print read/collection attribution with original bill unchanged; full PHP8.4
passed while browser/validate still needed their final result.

Registry v4 adds same-company platform-audit, notification and branch metadata
for every supported product; DI import batches/generated audit export identities;
Healthcare prescription and pharmacy sale identities. Only IDs/time extents
are returned, never message/audit/import/patient/item payloads. Global/null-tenant
rows and other-company rows remain excluded. All adapters retain explicit
UNAVAILABLE, empty, failed and partial states and use the same collection
registry; no arbitrary table/filter input is accepted.

Actual exhausted-cursor regression previously reported NO_RETAINED_EVENTS despite
rows existing in the selected window. Status now follows the independent window
count; an empty later page keeps the retained window evidence. Local full
Reporting **71tests790assertions passed**. Mandatory native probe additionally
checks real migrated notification rows, foreign/global exclusion, safe exhausted
cursor, identical collector count and unchanged source bytes. New required exact
success marker added without weakening the existing concurrency/IMS gates.
Fresh exact-HEAD full/native/browser/Node/build/validate acceptance is required.

Remaining full scope: explicit per-bill offline attribution (existing periodic
samples have no bill identity), deeper clinical/pharmacy relation correlation,
admin job/release/backup/support lifecycle adapters, branch/export interactions
and truthful field collection completeness. Existing device sequence/capacity/
clock-or-upload evidence is reused through get_pra_operations_evidence; its
presence does not prove seven-day device collection, paper or fiscal success.
No operational health or full-scope readiness claim is made. Finish all three
workstreams before one latest-main combined PR and fresh full acceptance.

## Company-linked support source (registry v5)

The durable support-case lifecycle is now a registered source for PRA, DI,
FBR POS and Healthcare. It is scoped by the case's exact company identity and
uses the case `updated_at` clock, so a resolve or reopen can appear in the
retained window without exposing the subject, evidence note, mailbox content,
actor details or resolution build. Reads and the bounded collector apply the
same company/product/window rules and return IDs/timestamps only. Missing
support schema remains explicitly unavailable on a partially deployed host;
an empty source is not called healthy. Local registry coverage verifies all
four product aliases, foreign-company exclusion, payload redaction, unchanged
case rows and derived-observation parity. This closes only the support adapter
slice; release/job/backup adapters and complete field evidence remain open.
