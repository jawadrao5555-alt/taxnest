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
