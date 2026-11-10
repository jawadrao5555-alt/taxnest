# Shared billing periods

## Customer and admin behavior

| Product | Current checkout | Target selectable periods |
| --- | --- | --- |
| Digital Invoice | Configured monthly, quarterly, semi-annual and annual | Quarterly, semi-annual and annual |
| PRA POS | Annual | Quarterly, semi-annual and annual |
| FBR POS | Annual | Quarterly, semi-annual and annual |
| TaxNest ERP | Annual plan configuration | Quarterly, semi-annual and annual |

Keep existing tiers and annual prices. Use explicit amounts for 3, 6 and 12 months. A missing short-period amount hides that choice and rejects a forged purchase or admin assignment. Use one resolver for pricing, signup, selection, quote, proof approval, assignment, customer and admin history. An unsupported period must not silently become annual.

Preserve paid monthly access and recorded proof and quote terms. Stop advertising and accepting fresh monthly orders and renewals once the replacement policy is enabled. Permit settlement of a valid frozen pre-cutover quote under its original terms. Keep monthly invoice allowances separate from billing period. Advertise the actual payable total first.

Reuse #362 remaining-term renewal and #363 reviewed documents and purchase snapshots. Reuse #367 configurable DI short periods. Confirm PRA extra branches and plan add-ons, distributor eligibility, first-purchase vs renewal, VAT/tax fields when applicable, partial proof and refunds. Leave unpriced periods unavailable.

## Acceptance

For each product and configured period, confirm public pricing, admin assignment, customer checkout, proof approval, history, access, expiry and renewal all agree. Cover cross-tenant plan IDs, forged monthly and missing-price periods, trial conversion, pending proof cutover, amount tampering, concurrent approval, month-end/leap-year and early renewal. Confirm annual-only plans still work, held prices survive catalogue edits and scope expires at the recorded end date. Admin and customer acceptance require a disposable tenant.
