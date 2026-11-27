# TaxNest production audit and follow-up

[PR #476](https://github.com/jawadrao5555-alt/taxnest/pull/476) deployed on 27 November 2026 at `6d322dad78eb0447c81bc0ab5980bc004b6c2c0d`. SaaS Admin Deploy Approvals recorded Deployed / Success. The [Deploy Production run](https://github.com/jawadrao5555-alt/taxnest/actions/runs/46033484587) passed its exact-commit gate, deployment, live verification and callback. Production verification covered the NestPOS QA sign-in, three ERP cards, and the Healthcare and School sign-in pages. The three public ERP signup gates were closed. Public pricing offered a quote. Authenticated Hospital, Lab and School journeys and production package purchases remain separate checks.

The [per-PR history](taxnest-pr-history-001-476.md) records 437 PRs and 39 separately numbered issues through #476, then reconciles #477 and the open source backlog. Those counts describe GitHub disposition and integration ancestry. Release verification below records the observed behavior by area.

## Reported issues

| # | Finding and change | Acceptance to complete |
| --- | --- | --- |
| 1 | #476 distinguishes network, authentication and server errors, keeps the last successful update and retained tables, and exposes retry. A response that sent headers but stalled during JSON parsing could still leave Loading indefinitely. #477 keeps the deadline active through body parsing and preserves the existing table snapshot and active cart. | Run the full fixture and browser checks on the final head. Time cold and repeat opens at the affected terminal. |
| 2 | #476 carries readable address input, saved address selection. #477 adds a wrapping preview of the selected address and explicit feedback for a blank Save. | Replay address selection at the affected POS using an isolated fixture. |
| 3 | #476 carries table Cash Received and Cash Change behavior under Customize Bill, with retry/idempotency coverage. | Verify PKR 2,420 due, PKR 3,000 received and PKR 580 change across one settled payment and its receipt. |
| 4 | #476 carries selected delivery address snapshots through the order and receipt when the applicable print options and order scope call for them. Pure takeaway address behavior needs explicit acceptance. Header and QR vary with receipt settings and fiscal status. | Verify saved type and address on the cashier screen and receipt for takeaway and delivery with the intended tenant print settings. |
| 5 | #476 carries matching Local/PRA filter links. Completed Local L-series sales can contribute to Today’s Ledger while financial history shows them separately as provisional. #477 displays final Local net and the total including provisional activity side by side and makes other payment methods visible. Saved day-close cash and fiscal treatment retain their existing rules. | Reconcile the affected ZFC prior business day inside the authorised POS. Verify exact recording date and branch before comparing figures. Use midnight, day-close and refund cases. |
| 6 | The recording shows repeated identical Desktop Agent offline warnings. The fix limits the dashboard to the current outage notice and clears it after confirmed recovery. | Repeat on the final head and observe a real reconnect. Physical devices and installed build require their own evidence. |
| 7 | PRA billing can omit unpriced or otherwise unavailable DI and FBR POS IMS from checkout. Delivery Riders and Rider Live Tracking have separate entitlements. #477 clarifies included tracking, rates pending and account availability on the billing screen. | Verify the current tenant package, branch limits and each authorised checkout path with configured prices. |
| 8 | Loading an unsettled waiter order allowed settlement while the standard Hold/F5 path refused to save additions. #477 routes the standard action to the existing same-order update and shows it to the owning waiter through the active kitchen states and existing refresh. | Verify authorised cashier, Admin and support roles, denied users, reconnect, concurrent revisions and one separate settlement on the final head. |

## Website and Admin

- Website Clients can find existing cards and candidates from the same search, filter by product and see paid eligibility at review. Product pages show all eligible approved cards. The POS product page also displays approved Standalone POS clients. The homepage keeps its six-card preview. Public cards require a linked eligible paid company and explicit approval of the public details.
- Admin Deploy Approvals and PR History use higher-contrast labels, inputs and keyboard focus. Earlier product website and Admin contrast coverage is recorded in [website-controls-contrast.md](../qa/website-controls-contrast.md).
- The Audit UI company search found one ZFC result. The other two requested public names showed no exact Companies search results. Account mapping, publication approvals and public visibility still need verification. Use account codes where public names differ.
- #476 carries the School and Hospital Collections upgrade rules. They credit the verified unused balance and charge the remaining difference, including the Hospital zero-balance case. Verify the live preview and the expected package, dates and rates through the authorised demo journey.
- Verify live demo account names and access for Hospital, Lab and School. Use synthetic fixtures for any operation that creates invoices, orders or payments. Keep existing production demo companies available for owner review.

## Source closure

[#457](https://github.com/jawadrao5555-alt/taxnest/pull/457) was closed after its only file matched the deployed version byte for byte. [#460](https://github.com/jawadrao5555-alt/taxnest/pull/460) retains the implementation and the pending FBR sandbox evidence. Keep other source PRs tied to the complete replacement scope, exact release and applicable acceptance before closing them.

## Verification

The initial #8 isolated PHPUnit run passed 48 tests with 285 assertions. The initial #6 isolated run passed 18 tests with 84 assertions, plus disposable MariaDB migration and desktop dashboard DOM checks. The local executor disconnected before integrated testing. The reconstructed PR head needs the full CI and scoped browser checks. Device, actual tenant and vendor outcomes require evidence from their authorised systems. Keep private customer records, operational screenshots and payment details out of the PR.
