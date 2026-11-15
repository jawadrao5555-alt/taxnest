# DI retained stock diagnostic links

Read-only association of already-posted stock, not a posting/reconciliation or
backfill operation. Registry v13 adds `di.stock_postings`; bill evidence v6 adds
`stock` with immutable posting-ID pagination and linked movement IDs. Generic
source reads retain the existing ID/time-only allow-list. No quantities, costs,
notes, payloads or private customer fields are exported.

Each posting must match its company and invoice, invoice item, movement ID,
product, `di_invoice_item` reference and null-safe original branch. Non-null
branches must belong to the same company. A changed current invoice branch is
reported as `INCONSISTENT_RETAINED_LINKS`, not hidden as no history. Deleted or
mismatched referenced rows likewise fail closed; unavailable schema remains
`UNAVAILABLE`. Inconsistency suppresses IDs/counts for the requested bill or
source company/date/branch scope, independently of the pagination cursor.
Other valid campus/date scopes remain usable. Retained rows are never repaired.

The existing diagnostic collector applies the same integrity boundary before
recording an observation. It may append its normal derived observation only;
it cannot label inconsistent links OBSERVED or mutate any business row.
No evidence asserts physical stock, regulator acceptance or complete collection.
DI payment/print evidence and other unsupported product associations remain
explicitly unimplemented.

Local PHP8.4 egress-blocked Reporting suite: 108 tests / 1192 assertions PASS.
Includes cross-tenant/branch and direct-ID denial, forged item/movement IDs,
wrong namespace, null branch, changed branch, deleted movement/missing table,
pagination, source-row preservation and collector parity. Final integrated
native/browser/CI evidence remains required; this is not a hosted result.

## Frozen identity and native probe follow-up

The adapter additionally requires the retained item's version-1 inventory
snapshot company/product/branch identity (including explicit null branch).
Matching a forged posting and movement product is insufficient. Missing or
contradictory snapshots are integrity-unknown; current catalogue existence or
price is not used, preserving legitimate retained history after catalogue edits.

Updated local Reporting suite: 110 tests / 1196 assertions PASS. The guarded
`rc_di_invoice_inventory.php` native probe now invokes the read-only diagnostic
helper after actual synthetic inventory posting. It verifies exact IDs,
pagination and explicit inconsistency after forged company/product/branch links;
all tampering runs under rollback and original business rows are byte-compared.
The MariaDB runner requires a separate diagnostic PASS marker. PHP/bash syntax
checks passed. Native execution remains pending hosted CI because local MariaDB
socket startup is restricted; syntax or SQLite results are not native proof.
