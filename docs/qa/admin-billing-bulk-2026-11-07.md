# Admin billing bulk — P1

Base: 363a0d6823eae113336a6cd6069f160fc7a1a441 (merged #362). Priority: P1, owner requested 7 November 2026.

This is an implementation workstream opened as a Draft. Initial content records source reconciliation and acceptance; runtime implementation and acceptance are not complete. No approval-ready or deployed claim follows from documentation CI.

Source trackers: #337 / #316. Delivery order: first active batch.

## Current source evidence

- SubscriptionAssignmentService and AdminPaymentProofController already provide company/proof locking. #362 fixes same-package renewal duration. Preserve this path and its native concurrency probe.
- PosAddonService already quotes remaining package months, narrows submitted quote snapshots and resolves existing entitlements. Do not replace it with a second entitlement service or assume all quoting is missing.
- BillingController already stores distributor quote snapshots; add-on snapshots exist. Complete immutable purchase coverage requires reconciliation across all purchase paths.

## Implementation acceptance

- [ ] Inventory every signup, payment-proof, manual assignment, renewal, upgrade and add-on path; identify invoice/receipt/allocation authority and legacy behavior.
- [ ] Freeze agreed price, product, plan limits and purchased features at purchase/approval with an explicit snapshot version; catalogue edits must not rewrite historical purchases.
- [ ] Link unique subscription invoice, verified payment, receipt, allocation and activation atomically; retries and concurrent approval create one result.
- [ ] Complete itemized company/admin billing history and authorized invoice/receipt downloads.
- [ ] Reconcile upgrade/add-on prorating against existing rules. Preserve existing agreed terms; proposed annual prices are not approved.
- [ ] Verify shared backend/browser/mobile/Agent feature enforcement against purchased access, including legacy records with no snapshot.
- [ ] Handle partial payment, credit and refund/reversal through explicit audited transitions; never delete accounting history or automatically issue real refunds.
- [ ] Exercise DI/PRA/FBR POS, trial/expired/granted/paid states, a second tenant, concurrent reviewers, duplicate callbacks, catalogue changes and reversals.

## Verification and preservation

Use fictional tenants and mocked mail/payment delivery. Required PHP8.4/full suite, native MariaDB concurrency and authenticated desktop/mobile billing journeys must pass for the final HEAD. Preserve sales, payments, stock, subscriptions, fiscal references, printers and pairing. Unknown legacy terms require explicit review rather than guessed backfill.

## First implementation increment — assignment evidence

The real synthetic payment-approval route reproduced a missing snapshot: the
new subscription's purchase_snapshot was null. Assignment now stores version 1
catalogue identity, feature flags, limits, quoted package/branch price and period
under the existing company lock and a locked plan read. A nullable additive
migration leaves legacy history unknown; no backfill or price policy change.
Normal Eloquent updates reject replacing or adding historical snapshots. This
is an application write guard, not a database trigger against arbitrary SQL.

The snapshot describes assignment-time package terms, not bank receipt or net
distributor payment. It is not yet the authoritative entitlement reader. The DI
self-subscribe path, signup/trials, submitted purchase quote, add-on documents,
invoices/receipts, purchased-access enforcement, prorated upgrades and reversals
still require implementation/reconciliation. The checklist above stays open.

Local PHP8.3.6/SQLite tests passed: 51 tests /1178 assertions for the new actual
HTTP DI/PRA/FBR POS snapshot regressions plus renewal, instant-access, annual
pricing and account-overview suites. Tests cover catalogue edits, duplicate
approval, write immutability, unknown legacy rows and another tenant. The
initial adjacent add-on run hit missing local GD; GD was added to the private
runtime and the unchanged add-on suite was rerun. Supported PHP8.4/full-suite
and native MariaDB validation remain hosted requirements. The existing native
two-worker paid-renewal probe now also asserts snapshot stability across plan
edits and approval retry. Syntax and diff checks pass.

No UI markup changed in this increment. A full authenticated billing journey
and native result must still be verified on the published commit. This PR is
work in progress and is not ready for owner approval/deployment.

## Verified subscription invoice and payment evidence

Reproduced the actual approval HTTP route for PRA, FBR POS and DI: the new term
was activated but subscription_invoices remained empty. Added reviewed
subscription documents to the existing invoice/payment tables inside the same
proof/company/assignment transaction. A nullable migration preserves legacy
rows without guessed backfill. Each proof has one unique invoice identity and
its payment receipt; the existing account overview now has actual source rows.
No second billing ledger or entitlement authority was introduced.

Versioned document snapshots retain assignment terms, the reviewed amount,
company/product, reviewer/time and allocation. The invoice amount comes from
the existing enforced subscription price or already-validated distributor net
quote; the payment amount comes from independent admin verification, never the
customer's entered claim. Underpayment retains a part_paid balance; excess is
recorded as unallocated evidence, not reusable credit or an automatic refund.
The existing approval/access policy is preserved. In particular, DI's existing
enforced annual pricing is retained even when a synthetic catalogue row has a
different raw price. Proposed prices/billing policies remain unapproved.

Issued snapshots and document fields reject normal Eloquent changes; this is
an application guard, not a database trigger against arbitrary SQL. Legacy
history remains unknown. No live payment or transfer is performed.

Local PHP8.3.6/SQLite:54 tests/1236 assertions passed across snapshot/documents,
payment instant access, annual pricing, account overview and real-migration
renewal. Includes actual approval for three products, partial/excess payment,
immutable documents, retry and a real late receipt uniqueness failure that
rolls back invoice/assignment/proof while preserving another tenant's ledger.
The focused snapshot/document suites were rerun after adding explicit
post-retry invoice/payment counts:7 tests/115 assertions passed.
The required native two-worker approval probe also checks one linked invoice
and receipt, preserved after catalogue edit/retry. Its exact-HEAD hosted result
is still required, alongside PHP8.4/full suite/browser/validate.

This increment covers reviewed subscription proofs only. Add-on document
paths, authoritative purchased-access reading, self-service upgrade/proration,
explicit adjustments/refunds/credits
and the remaining signup/payment paths are not complete. The broad acceptance
checklist stays open. Owner's latest delivery decision is to finish363/364/365
and then combine on a latest-main feature branch with fresh combined testing
before presenting one bulk PR for owner approval. No merge/deploy authorized.

## Authorized verified-document history and downloads

Added a shared company-scoped history reader for the verified subscription
documents above. Digital Invoice, PRA POS and FBR POS billing screens now show
the frozen package/period, due amount, independently verified receipt amount,
remaining balance and unallocated excess. Owner/admin users can download the
invoice and receipt PDFs; SaaS admins receive the same documents from the
company account view. Every lookup is constrained to the authenticated company
or explicit admin company route. An independently logged-in product guard can
no longer be selected accidentally: the route name chooses the exact guard.

Only records carrying both a document identity and versioned snapshot are
downloadable. Unknown legacy charges stay visible to SaaS admins but are not
turned into invented invoices or receipts. PDFs render an allow-listed subset
of the snapshot and do not expose proof files, customer claims or arbitrary
metadata. Responses are private/no-store. This adds no activation, payment,
credit, refund or pricing behavior.

Local PHP8.3.6/SQLite focused regression:21 tests/1102 assertions passed across
tenant/admin authorization, cross-tenant denial, legacy unknown handling,
actual PDF responses, verified document creation and company account history.
Supported PHP8.4/full suite and authenticated desktop/mobile acceptance remain
hosted requirements. Add-on documents and the other unchecked billing items
remain pending, so the broad checklist and PR stay work in progress.
