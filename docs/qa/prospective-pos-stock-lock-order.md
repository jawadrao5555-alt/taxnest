# Prospective POS stock lock order

## Confirmed source mechanism

The inventory module migration declares real `company_id -> companies.id` foreign keys on both `inventory_stocks` and `inventory_movements`. New prospective billing admission holds the company row before taking stock or invoice locks. Receiving previously locked/updated a stock row and then inserted a movement, whose foreign-key check can require a shared company lock. Product edits and refunds can similarly own a product/original-invoice lock before inserting a company-linked row. This establishes a possible company-to-child versus child-to-company cycle. Source inspection is not a claim of a reproduced native deadlock.

## Bounded fix

The shared `ProspectiveQuotaGuard::lockCompanyForInvoiceWork` is lock-order only. It does not check subscription access, quota, fiscal entitlement, or whether a refund is allowed. Its legacy/missing-schema path is a no-op.

Outer FBR product creation/edit/stock correction, stock purchase/void/transfer, pharmacy stock work, supplier return and generic supplier receive paths acquire that lock before their first child write or lock. FBR and PRA returns, Local Core refund/restore and pharmacy batch work follow the same order. Acquiring it only inside `InventoryService` would be too late for a caller that already updated the product or inserted a company-linked purchase row. Ledger's separate patch covers the shared inventory primitives and PRA product/day-close entrypoints.

The three FBR prospective hints also check policy schema availability before querying subscriptions, preserving legacy/minimal-schema store and delete paths.

No quantities, amounts, fiscal behavior, legacy entitlements, or refund eligibility are changed. Required native receiving-versus-sale and other lock-order probes must run on the integrated commit; SQLite regressions cannot establish InnoDB deadlock freedom.
