---
name: PRA POS Deals feature rules
description: Day-based combo deals — invariants for pricing, stock, payload mapping, and where deals may/may not flow.
---

# PRA POS Deals — durable rules

- **Server-enforced price**: `resolveItemExemptions` 'deal' branch ignores client `unit_price` and uses the DB deal price. Never trust cart price for deals.
- **Frozen snapshot**: `pos_transaction_items.deal_snapshot` = `[{product_id,name,qty}]` captured at sale time. ALL stock math (deduct, edit old/new, void restore, provisional delete restore) expands from the SNAPSHOT via `expandDealComponentsForStock()` — never from live `pos_deal_items` (deal may be edited later).
- **Billing-only**: deals never go through restaurant hold/KOT — `hasDealItems()` disables Hold/Send-to-Kitchen and forces pay routing to `processPaymentManual`; server backstop = restaurant hold validation `in:product,service,manual` → 422.
- **Day filter is server-side**: `PosDeal::isActiveOn()` (ISO weekday 1–7 in `active_days` JSON, empty = daily, optional starts_on/ends_on) filters at page load. `storeInvoice` deliberately does NOT re-check (midnight-crossing cart honored — accepted policy, architect-cleared).
- **Not in localStorage product cache** — deals are day-dependent; always fresh from `$dealsForJs`.

## Payload-builder trap (the bug the architect caught)
`processPaymentManual`'s `items: this.cart.map(...)` had `type: item_type==='service' ? 'service' : 'product'` — flattening ANY new cart item_type to 'product', silently bypassing the whole server deal branch (client price honored, no snapshot, possible wrong-product stock deduction on id collision). **Any new cart line type must be added to this mapping AND tested with the payload the UI actually builds — a hand-crafted curl with the right `type` proves the API contract, not the UI path.**
