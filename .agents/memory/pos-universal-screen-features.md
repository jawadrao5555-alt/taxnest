---
name: POS universal sale screen — feature inventory
description: Full feature rules for pos/universal.blade.php — Screen Fit, search/category, cart mechanics, guided keyboard, F10/F11 modals (moved out of replit.md Jul 2026)
---

# Universal sale screen (`resources/views/pos/universal.blade.php`) — the ONLY live sale screen

Restaurant screen retired: `RestaurantPosController::pos()` early-redirects to `pos.invoice.create` (carries table_id). Mirrored in the FBR universal port — keep diffable (see `fbr-universal-sale-screen.md`).

## Screen & search features
- **Screen Fit**: root binds `:style="fitStyleStr"` — CSS zoom + px-height compensation (auto by viewport; manual 80–125% via action-bar "Fit" dropdown; localStorage `tn_screen_fit`).
- Barcode/SKU exact-match search with fast-path add; scans stay GLOBAL (never category-narrowed).
- Category dropdown next to search (optional, default All — same `activeCategory` as pills, always visible ≥sm; search/grid narrow to it; quick-create has exact-name duplicate guard; grid-OFF toggle resets to All).
- Customer phone filters-as-you-type; per-item NO TAX/TAX toggle; Quick Type manual entry (inventory-OFF).
- Order-type widget renders only when a restaurant-ish feature is on.

## Cart mechanics (critical)
- Manual-cart bypass → `processPaymentManual` POSTs to `pos.invoice.store` with `_manual: true` (no master-product auto-create).
- **NEW cart item types MUST be added to processPaymentManual's type mapping** (it flattens unknown types to 'product').
- Stable `_uid` cart keys (Alpine DOM-reuse).
- **Persistent receipt popup — explicit dismiss only** (auto-reloads/updates must hold while it's open; see `window.tnPwaUpdateHold`).

## Keyboard
- Guided Keyboard Billing Flow default ON per company (`pos_guided_flow_enabled`, opt-OUT) — `flowStep` chain customer→items→cart→finish; Enter advances, `P` in Pay modal = provisional, `!e.repeat` guards. Detail: `pos-guided-keyboard-flow.md`.
- Cart-row shortcuts T/D/N: plain key fires ONLY on body/non-input focus (inside search input letters ALWAYS type — never re-add an "empty search = shortcut" branch); Alt+T/D/N anywhere; D/N gated OFF while modals open. Detail: `pos-plain-letter-shortcuts.md`.

## Provisional/failed modals
- Cashier saves "Provisional" from Pay modal (local status, no PRA submit), later promoted.
- F10 header "Local" modal (list/edit/delete/promote via `/pos/api/provisional-bills*`); F11 "Failed" modal (retry/edit/delete via `/pos/api/failed-bills*`, race-safe atomic claim on retry).

## PWA update hold (Jul 2026)
`window.tnPwaUpdateHold` registered in `_startAutoSync` init: returns true while cart non-empty, pay modal open, submitting, or receipt popup open — pwa-update toast defers auto-reload (retries every 30s; manual Refresh bypasses). Mirrored in FBR universal.
