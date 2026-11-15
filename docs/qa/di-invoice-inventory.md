# Optional DI stock projection (#266/#313)

## Boundary

The existing audited company inventory switch is the explicit opt-in. Only newly saved web/API/duplicated DI drafts capture eligibility and a server-validated Product/branch/base-UOM/base-quantity snapshot. Free-text lines, imported historical documents without a selected Product, and earlier drafts without eligibility are not inferred into stock. Turning the switch on later does not backfill them.

Drafts, previews, simulation, submission attempts and admin protection-only locks do not post stock. The existing DI authoritative accepted/locked production-commercial finalization is the posting boundary. The first canonical fiscal reservation freezes the selected requested environment, before dispatch. Sandbox/test acceptance displays an explicit no-stock outcome and never changes physical inventory; later company or endpoint changes cannot retroactively make that invoice eligible. Missing original-environment proof is held, not inferred. Sync, batch (through sync), legacy job, acknowledged-log recovery and manual confirmed-reference paths share one acceptance-and-stock helper. Stock writes are atomic within a nested savepoint; an inventory failure after external acceptance preserves the exact accepted reference and locks the fiscal invoice, with a separate inventory reconciliation HOLD. No new regulator endpoint or live acknowledgement is claimed.

Each immutable invoice item has one unique posting, linked to one generic movement. Product-row locks serialize even previously empty unassigned-campus stock, avoiding MariaDB nullable-unique duplication. Stock arithmetic uses exact decimal four places. The shared stock quantity and movement quantity/balance columns widen additively to four decimals; existing values and negative-stock policy are retained. Migration rollback intentionally never narrows or rounds historical quantities.

Credits can replenish only a selected original posted sale line and original branch, never legacy unposted invoice quantities. Saved credit drafts limit quantities under original-posting locks. Before fiscal dispatch, an explicit durable reservation keyed to each return invoice item counts posted and reserved quantities. Unknown fiscal outcomes retain reservations; only an unsubmitted draft or authoritative rejection can release them on authorized edit/delete. Finalization consumes the reservation and again verifies posted quantity. A return uses the original sale posting cost snapshot; unavailable source cost stays explicitly NULL in the posting evidence rather than being certified as zero cost. Monetary debit notes do not imply physical goods movement. Later packing definitions do not reinterpret the frozen base quantity.

Existing non-inventory integrity hashes remain v4-compatible; new eligible invoices use v5 including their eligibility and stock-line snapshot. No historical hash is recomputed. Generic DI stock screens display fractional quantities without hiding the third/fourth decimals. PRA/FBR-POS/Health have no new projection entry points.

## Evidence and remaining boundaries

Synthetic HTTP API draft/edit/manual-confirm and stock assertions, original packing examples, branch isolation, disabled/legacy preservation, overreturns, duplicate finalization, atomic rollback and integrity tampering are covered by `DiInvoiceInventoryTest`, `DiSellingPackingTest`, `DiIntegrityCoverageTest`.

The socket-only native probe `rc_di_invoice_inventory.php` adds independent-process duplicate finalization, concurrent empty unassigned-stock creation and competing pre-dispatch return reservations, exact fractional balances and rollback. It must pass in hosted MariaDB CI; syntax checking alone is not runtime acceptance.

No real FBR, customer invoices, stock, payment or production settings were used. Imported unlinked source rows require explicit product evidence rather than inferred matching. Generic historical duplicate stock rows require reconciliation and are not silently merged. An unexpected stock projection error after acknowledgement preserves the saved accepted identifier and a visible stock-only HOLD; an owner may explicitly retry only the local stock projection after correcting the issue. That recovery verifies the immutable invoice hash and never reserves or invokes fiscal submission. A database-wide outage still requires original fiscal acknowledgement-log reconciliation; no system can claim a database commit that failed.

## Hosted browser acceptance (pending execution)
`di-inventory-projection` seeds two independent fictional DI tenants for desktop and 390px. No regulator transport is invoked: acknowledgement references explicitly start `SYNTHETIC-NOT-REGULATOR` and provenance is `synthetic_browser_fixture`.

The UI proof checks posted status, one movement with base quantity50 and balance50 from opening100, sandbox no-stock status, and a synthetic interrupted projection held before stock writes. Clicking only local stock retry posts10.1234 to39.8766; exact retry must leave that balance and one movement unchanged. Foreign-tenant invoice and stock history URLs must deny without acknowledgement leakage. Browser fiscal submission routes are blocked and any attempted dispatch fails acceptance. Sanitized screenshots cover posted invoice, movement, sandbox notice and local recovery at both widths. This is not real FBR acceptance evidence or completed hosted-browser proof until CI executes it.

## Operational rollback and fatal database failures

Do not run `migrate:rollback` after any invoice posting/reservation data exists: down() drops additive posting evidence, even though it deliberately does not narrow physical quantities. Operational rollback must disable new rollout/code only while preserving tables and verified snapshots. A schema rollback is not certified as financially reversible.

A recoverable stock statement/validation error rolls back its savepoint and persists the accepted invoice with inventory HOLD. MySQL deadlock, serialization loss or lost connection may destroy the whole parent transaction; these errors are rethrown, never swallowed as a successful accepted-row commit. The already committed fiscal lease remains non-reclaimable, and automated sync/job/API/batch acceptance has the separate FbrService success log (retaining the regulator invoice number) written before stock finalization. Recovery must use that evidence, never issue another regulator request blindly.

Manual confirmation is an owner-provided assertion, not a regulator call. If its transaction fails fatally, the invoice remains pending verification; the owner must repeat the explicit confirmation. There is no automatic confirmation, automatic regulator retry or invented durable manual-acknowledgement journal.
