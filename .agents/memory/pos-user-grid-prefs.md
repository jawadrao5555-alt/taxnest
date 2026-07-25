---
name: Per-user POS grid visibility prefs
description: pos_user_item_prefs rules — user pref overrides admin show_on_sale BOTH directions, grid-only, search never filtered
---

# Per-user sale-grid visibility (owner, 25 Jul 2026)

- Table `pos_user_item_prefs` (user_id, item_type product|service|deal, item_id, visible, unique triple). No row = admin default (products: show_on_sale; services/deals: visible). Row = that USER's override, BOTH directions — a user may un-hide an admin-hidden item on their own grid. Owner explicitly REJECTED the 2-layer admin-wins design; do not "fix" it back.
- **Why:** owner wants every user (cashier/waiter/manager) full authority over their own screen clutter; `is_active=false` stays the only true kill switch.
- **How to apply:** ALL grid read paths go through the view's `isItemVisible()` (universal: idle filterProducts branch, addRandomProduct, Items count; waiter: filterProducts + category pills). Typed SEARCH is NEVER pref-filtered (standing rule). editTransaction picker / storeInvoice / KOT / Table-se-Bill never read prefs. FBR universal port NOT ported (frozen).
- Endpoints: POST `/pos/grid-prefs/toggle` + `/reset` — pos.auth ONLY (no company.approval, Madadgar precedent), in PosAuth waiter whitelist, CSRF-exempt like all pos/*. Controller checks company ownership of the item (404) and keys rows on auth id — never trust a user_id from input.
- `PosUserItemPref::mapForUser()` is hasTable+try/catch guarded and the migration idempotent — file-deploy may land before live migrate; toggle returns 503 not_ready in that window.
- Grid EDIT mode ("Grid Tarteeb" chip in tn-cat-strip): renders ALL items (hidden dimmed), tile click/Enter toggles pref instead of add-to-cart, stock-out pointer-events class skipped so out-of-stock tiles stay togglable. tn-cat-strip now renders for ALL companies (was inventory-OFF only); the master Products toggle stays inventory-OFF only. Saaf style hides the strip behind "Mazeed" (pos-saaf.css) — Saaf users reach Grid Tarteeb only via Mazeed.
- Waiter loader no longer server-filters show_on_sale — filtering moved client-side (pref-less output identical); category pills track effective visibility.
