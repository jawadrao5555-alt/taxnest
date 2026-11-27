# Bulk billing rollout and company lifecycle

Implementation draft based on main after production release #422 (`fe05426c`). This register carries the full remaining contracts of #264, #374 and #316. Each source stays open until all of its requirements have implementation, acceptance and verified release evidence. Record deliverables already satisfied by earlier releases by requirement and commit.

| Source | Contract | Current baseline | Work and evidence to finish |
| --- | --- | --- | --- |
| #264 / issue #263 | [Digital Invoicing multi-cycle billing](https://github.com/jawadrao5555-alt/taxnest/blob/c4b42f42da76473a3ed71f96b7bfe77eaa2f08ad/docs/di-billing-cycles-263.md), also carried by #313 | #367 configured DI cycles and immutable quotes, #362 remaining-term renewal, #363 purchase evidence, #422 Cash/Card settlement | Reconcile all four cycles, effective-date and pricing/discount/tax/currency configuration, displayed payable and effective monthly amounts and term dates. Complete policy and evidence for upgrade/downgrade, cancellation, failure and notices. Test expiry, tenant isolation, preserved legacy prices, retries and documents. |
| #374 | [Shared billing periods](https://github.com/jawadrao5555-alt/taxnest/pull/374/files) | #393 shared resolver, explicit rate preparation and opt-in flags. #367 DI periods. #422 selected-cycle signup and admin paid collection. | Verify pricing, signup, authenticated checkout, proof review, admin assignment, receipts/history, access and renewal across DI, PRA POS, FBR POS and ERP. Validate missing prices and forged periods, pending frozen quotes, both checkout flows, add-ons, and repeat and concurrent approvals. Record approved commercial rates, the fresh-monthly policy and staging/production rollout evidence. |
| #316 | [B01 scope and acceptance ledger](https://github.com/jawadrao5555-alt/taxnest/blob/681e2645cbde404a1f4a282e6f8d9961bf1c70f0/docs/planning/bulk-company-subscriptions-accounts-2026-10-30.md). Preserve all B01-R01–R09, B01-A01–A05 and named scenarios. | #362 paid renewal, #363 frozen terms and reviewed documents, #393 prospective trial and grace policy plus scoped finance, #422 package approval and settlement | Complete company overview, lifecycle timeline and saved views, one explainable access decision across approval/paid/suspension/grant/maintenance states, versioned entitlement limits, cross-product lifecycle boundaries, financial allocations and adjustments, source-linked platform finance, discount/add-on/referral policy and clawback, and isolated company grouping/migration. Verify requirement by requirement against implemented behavior before changing policy or adding features. |

## Implementation order

The admin payment proof queue renders the feature add-on approval form independently of subscription quotes. Subscription review keeps a saved short cycle selected when the catalogue no longer offers it. The fictional route tests cover both queue layouts and approval against the original DI quote.

The payment proof queue shows the saved validated amount for existing quotes, including a removed catalogue period. For proofs without a saved quote, the current rate is labelled separately from the submitted amount.

PRA POS signup can reserve one priced federal connector on an annual package and extra branch slots on a package with a fixed branch limit. The immutable signup quote carries the itemized total into checkout, the Cash/Card confirmation or uploaded-proof review, and a single invoice and receipt. The fictional route scenarios in `PraSignupBundleTest` exercise both settlement paths, price changes, short payment, replays, and federal access after the existing paid-term start. The shared rollout, lifecycle and commercial acceptance in the table remain open.

1. Reconcile the three source contracts against deployed code. Pin implementation, tests and live evidence for each delivered requirement. Prioritize company overview and usage as read-only views and validate permission and export scope.
2. Verify existing period selection and quotes on fictional tenants. Fill verified gaps in selectors, admin assignment, entitlement and renewal, then verify cutover with legacy monthly terms and pending proofs. Use approved rates and an explicit rollout decision for shared periods and monthly retirement.
3. Complete lifecycle decisions and financial flows. Keep #422's Cash/Card receipts and historical reprints tied to the original collection. Add only missing cases for partial payment, credit, refund/reversal, discounts/referrals and commission, with the established approval path for commercial rules.
4. Verify exact 72-hour trial and 7-day paid grace boundaries, PKT and calendar boundaries, old and new tenants, permission separation, retry/concurrency/rollback, and agreement across purchase, allocation, document, access and commission. Use hosted required CI with final validate and browser evidence for affected customer/admin journeys.
5. After owner release, record the deployed SHA and live verification for each source. Close each superseded PR individually when its full contract passes. Keep #263 and the broader #313, #337 and #301 scopes traceable.

| Source closure | Status | Implementation and acceptance | Verified release |
| --- | --- | --- | --- |
| #264 | Open | Reconciliation and implementation pending | Pending |
| #374 | Open | Shared rollout evidence pending | Pending |
| #316 | Open | Full B01 reconciliation and implementation pending | Pending |
