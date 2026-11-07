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

Initial validation: source inspection only. Runtime and browser acceptance pending. No production access or release action.
