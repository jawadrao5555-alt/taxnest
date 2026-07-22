---
name: POS universal sale screen — feature inventory
description: Full feature rules for pos/universal.blade.php — Screen Fit, search/category, cart mechanics, guided keyboard, F10/F11 modals (moved out of replit.md Jul 2026)
---

# Universal sale screen (`resources/views/pos/universal.blade.php`) — the ONLY live sale screen

Restaurant screen retired: `RestaurantPosController::pos()` early-redirects to `pos.invoice.create` (carries table_id). Mirrored in the FBR universal port — keep diffable (see `fbr-universal-sale-screen.md`).

## Screen & search features
- **Screen Fit**: root binds `:style="fitStyleStr"` — CSS zoom + px-height compensation (auto by viewport; manual 80–125% via action-bar "Fit" dropdown; localStorage `tn_screen_fit`).
- Barcode/SKU exact-match search with fast-path add; scans stay GLOBAL (never category-narrowed).
- FIRST-LETTER PRIORITY ranking (customer suggestion, Jul 2026): name-prefix matches rank ABOVE mid-word matches in BOTH matchers (grid `filterProducts` stable sort + dropdown `onSearchInput` pref/other buckets, loop stops only at 12 PREFIX hits) — mid-word matches stay listed below, exact barcode/SKU still jumps to top (scanner sort runs AFTER the bucketing). Mirrored in FBR universal port — any change to one matcher must hit all FOUR spots (2 files × grid+dropdown).
- Category dropdown next to search (optional, default All — same `activeCategory` as pills, always visible ≥sm; search/grid narrow to it; quick-create has exact-name duplicate guard; grid-OFF toggle resets to All).
- Customer phone filters-as-you-type; per-item NO TAX/TAX toggle; Quick Type manual entry (inventory-OFF).
- Order-type widget renders only when a restaurant-ish feature is on.

## Cart mechanics (critical)
- Manual-cart bypass → `processPaymentManual` POSTs to `pos.invoice.store` with `_manual: true` (no master-product auto-create).
- **NEW cart item types MUST be added to processPaymentManual's type mapping** (it flattens unknown types to 'product').
- Stable `_uid` cart keys (Alpine DOM-reuse).
- **Persistent receipt popup — explicit dismiss only** (auto-reloads/updates must hold while it's open; see `window.tnPwaUpdateHold`).
- **Pay/hold fetch handlers MUST parse the JSON error body on !res.ok** — backend sends the real reason (insufficient stock / already paid / quota) as 4xx JSON; a generic "HTTP 400" toast hides it from the cashier (Frost & Brew live incident). `payHeldOrderDirect` returns true/false; on failure the billing-flow caller KEEPS the cart and sets `recalledOrderId = holdData.order.id` so retry reuses (cancels+replaces) the held order instead of minting orphan 'held' rows per attempt. Callers must NOT force-close the pay modal on failure (stockError banner lives inside it; x-effect clears `payingHeldOrderId` on dismiss).

## Keyboard
- Guided Keyboard Billing Flow default ON per company (`pos_guided_flow_enabled`, opt-OUT) — `flowStep` chain customer→items→cart→finish; Enter advances, `P` in Pay modal = provisional, `!e.repeat` guards. Detail: `pos-guided-keyboard-flow.md`.
- Cart-row shortcuts T/D/N: plain key fires ONLY on body/non-input focus (inside search input letters ALWAYS type — never re-add an "empty search = shortcut" branch); Alt+T/D/N anywhere; D/N gated OFF while modals open. Detail: `pos-plain-letter-shortcuts.md`.

## Provisional/failed modals
- Cashier saves "Provisional" from Pay modal (local status, no PRA submit), later promoted.
- F10 header "Local" modal (list/edit/delete/promote via `/pos/api/provisional-bills*`); F11 "Failed" modal (retry/edit/delete via `/pos/api/failed-bills*`, race-safe atomic claim on retry).

## Edit-provisional-in-sale-screen (Jul 2026)
- `?edit_bill={id}` on the sale screen loads a PROVISIONAL ONLY (local/local, NULL fiscal serial, company-scoped); anything else redirects to the classic edit page whose own guards apply. F10 modal Edit links + 'e' key use this; F11 FAILED-bill edits stay on the classic page (deliberate).
- In edit mode: F9 becomes "Update Bill L-XXX" → PUT `/pos/transaction/{id}` JSON (updateTransaction is wantsJson-aware); Pay + Hold are toast-BLOCKED — finalizing must go through the F10 promote path (owns quota/serial/PRA rules). Bill keeps its L-serial and stays provisional on update.
- **saveCart is double-guarded** (entry + inside debounce timer) in edit mode — without the timer re-check the edited bill's cart clobbers the cashier's own saved localStorage cart and resurrects as a duplicate new sale.
- Deal lines in an edited provisional get RE-PRICED at the current deal price server-side (`resolveItemExemptions` enforces); a deleted deal falls back to client price with item_id nulled — pre-existing behavior, more reachable now.

## PWA update hold (Jul 2026)
`window.tnPwaUpdateHold` registered in `_startAutoSync` init: returns true while cart non-empty, pay modal open, submitting, or receipt popup open — pwa-update toast defers auto-reload (retries every 30s; manual Refresh bypasses). Mirrored in FBR universal.
