# Udhaar Book — separate parked workstream

Owner instruction, 2 November 2026 (Pakistan): keep this PR separate from the customer-issues bulk. Specification only; no implementation, schema migration or deploy readiness. Work is parked pending the owner's chosen order.

## Confirmed behavior
- Rider chooses full Amount Received OR full Credit. No partial payment.
- Full received remains rider cash to settle. Full credit moves the amount out of rider cash liability into the company's customer receivable; never classify credit as received cash.
- Preserve immutable attribution: which rider supplied which customer, business date/time and original credit amount.
- Only authorized company admin/owner records later collection and clears credit. Rider cannot collect/clear older credit through this feature.
- Later settlement does not erase the original rider/date/customer attribution.
- If local billing is deleted at day close, the shop khata retains amount/date/customer/rider and collection history only. Do not expose deleted bill number, product/item/quantity details in khata.
- SaaS-held support/history data is separate from shop cleanup and subject to SaaS-admin retention/delete authority. Reconcile existing consent and archive behavior explicitly; do not silently change saved tenant choices.
- Preserve balances after bill cleanup: ledger must not cascade-delete, re-create deleted invoice detail, lose credit or double-post.
- Existing nine report/print designs and bulk report fixes are outside this PR.

## Required acceptance before implementation completion
- Map existing customer/rider ledger, settlement, business-day and retention code before adding a parallel mechanism.
- One authoritative transaction boundary for credit/received decision, rider liability and customer balance; retries/double taps/concurrent owner collection remain idempotent.
- Company/branch/role isolation, authenticated owner collection, audit attribution, no unauthorized cross-customer edits.
- Full credit, full received, cancellation/return/reversal, failed request/retry, already-settled rider, later owner collection, day-close retain/delete, repeated cleanup and historical status.
- Keep money exact and show outstanding/cleared history truthfully. No financial writes during preview.
- Use fictional fixtures and loopback DB/browser only. No private customer data in this public repository.
- Do not merge/deploy this planning PR as a functioning feature.
