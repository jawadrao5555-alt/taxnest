# Retained payment diagnostic evidence (PR393)

Registry version 14 adds two bounded read-only sources. Bill-link protocol version 7 adds only the explicit FBR allocation link.

- `di.payment_events`: company-bound `customer_ledgers` rows of type `payment`, with null invoice reference. Returns immutable row ID and server timestamp only. This is a tenant-level payment event, not an invoice settlement, payment-provider confirmation, customer identity or branch attribution. Unexpected non-null invoice references produce `INCONSISTENT_RETAINED_LINKS` instead of inferred linkage. DI bill payments and printing remain explicitly unimplemented.
- `fbrpos.payment_allocations`: follows allocation → wasooli ledger and udhaar ledger → retained FBR bill. Company and customer identity must agree at every link, including the retained customer and branch where present. Properly linked `return_adjust` rows are not payment receipts. Broken or foreign links produce `INCONSISTENT_RETAINED_LINKS`; absent schema is `UNAVAILABLE`. Bill evidence exposes allocation IDs, never amounts or customer fields. Unallocated/unretained payments remain unknown. No ordinary offline event-to-FBR-bill link is invented.

Sources enforce existing connection company scope, exact product and branch authorization, seven-day windows and bounded immutable-ID cursors. Late arrivals are visible by re-reading the period; pages do not claim snapshot consistency. Derived observations keep source-integrity status separately and never assert seven-day collection completeness or fiscal/payment success.

Synthetic tests cover company/product ID collisions, foreign customer/ledger/bill links, branch boundaries, return adjustment exclusion, missing schema, late cursors, exact output field allowlists and collector integrity status. Native protocol version expectation is explicitly advanced from 6 to 7; all prior association assertions remain.
