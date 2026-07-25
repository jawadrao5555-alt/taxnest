---
name: POS universal sale screen — feature inventory
description: Full feature rules for pos/universal.blade.php — Screen Fit, search/category, cart mechanics, guided keyboard, F10/F11 modals (moved out of replit.md Jul 2026)
---

# Universal sale screen (`resources/views/pos/universal.blade.php`) — the ONLY live sale screen

Restaurant screen retired: `RestaurantPosController::pos()` early-redirects to `pos.invoice.create` (carries table_id). Mirrored in the FBR universal port — keep diffable (see `fbr-universal-sale-screen.md`).

## Boot splash (slow-connection blank-screen fix, Jul 2026)
- Inline-styled `#tn-boot-splash` overlay near top of universal.blade.php — page streamed ~700KB and looked BLANK WHITE on slow shop internet until Alpine painted the grid. Removed on `alpine:initialized` + window-load+800ms + 12s failsafe (can never stick). Do NOT remove, and do NOT move product JSON out of the page to "fix" load time — offline billing + localStorage product cache depend on inline data.
- Companion: bunny.net Inter stylesheet in pos-app layout is non-render-blocking (`media="print"` onload swap + noscript). Receipts/KOTs print from standalone templates, unaffected.

## Screen & search features
- **Screen Fit**: root binds `:style="fitStyleStr"` — CSS zoom + px-height compensation (auto by viewport; manual 80–125% via action-bar "Fit" dropdown; localStorage `tn_screen_fit`).
- Barcode/SKU exact-match search with fast-path add; scans stay GLOBAL (never category-narrowed).
- FIRST-LETTER PRIORITY ranking (customer suggestion, Jul 2026): name-prefix matches rank ABOVE mid-word matches in BOTH matchers (grid `filterProducts` stable sort + dropdown `onSearchInput` pref/other buckets, loop stops only at 12 PREFIX hits) — mid-word matches stay listed below, exact barcode/SKU still jumps to top (scanner sort runs AFTER the bucketing). Mirrored in FBR universal port — any change to one matcher must hit all FOUR spots (2 files × grid+dropdown).
- Category dropdown next to search (optional, default All — same `activeCategory` as pills, always visible ≥sm; search/grid narrow to it; quick-create has exact-name duplicate guard; grid-OFF toggle resets to All).
- Customer phone filters-as-you-type; per-item NO TAX/TAX toggle; Manual Item entry (inventory-OFF).
- Quick Type Mode (F7 "chai 2, samosa 1" → cart) is OPT-IN per company, default OFF (customers called the button clutter); enable via Customize POS. NEVER re-add it unconditionally. FBR universal port deliberately NOT gated (owner PRA-only focus) — its always-visible Quick button is intentional, not a gap.
- Order-type widget renders only when a restaurant-ish feature is on.

## Cart mechanics (critical)
- Manual-cart bypass → `processPaymentManual` POSTs to `pos.invoice.store` with `_manual: true` (no master-product auto-create).
- **NEW cart item types MUST be added to processPaymentManual's type mapping** (it flattens unknown types to 'product').
- Stable `_uid` cart keys (Alpine DOM-reuse).
- **Receipt popup auto-close (owner, 23 Jul 2026 — REVERSED the old "persistent, explicit dismiss only" rule)**: popup closes itself after `companies.pos_receipt_autoclose_seconds` (NULL = 10s default, 0 = never/old persistent behavior; allowed 0/5/10/15/20/30 via POST `/pos/settings/receipt-autoclose`, admin-only, set on Customize POS). Countdown pill top-left ("Ns · Roko"); hover on the card PAUSES, any click/keypress inside CANCELS. NEVER closes while pendingPrintTimers/printMessageHandlers busy — waits 2s and re-checks (closing fires cancelPendingPrints via x-effect and would kill queued prints). FBR universal port deliberately NOT mirrored (stub no-ops kept, PRA-only focus — Quick Type precedent). Auto-reloads/updates still hold while popup open (`window.tnPwaUpdateHold`).
- **Pay/hold fetch handlers MUST parse the JSON error body on !res.ok** — backend sends the real reason (insufficient stock / already paid / quota) as 4xx JSON; a generic "HTTP 400" toast hides it from the cashier (Frost & Brew live incident). `payHeldOrderDirect` returns true/false; on failure the billing-flow caller KEEPS the cart and sets `recalledOrderId = holdData.order.id` so retry reuses (cancels+replaces) the held order instead of minting orphan 'held' rows per attempt. Callers must NOT force-close the pay modal on failure (stockError banner lives inside it; x-effect clears `payingHeldOrderId` on dismiss).

## Keyboard
- Guided Keyboard Billing Flow default ON per company (`pos_guided_flow_enabled`, opt-OUT) — `flowStep` chain customer→items→cart→finish; Enter advances, `P` in Pay modal = provisional, `!e.repeat` guards. Detail: `pos-guided-keyboard-flow.md`.
- Cart-row shortcuts T/D/N: plain key fires ONLY on body/non-input focus (inside search input letters ALWAYS type — never re-add an "empty search = shortcut" branch); Alt+T/D/N anywhere; D/N gated OFF while modals open. Detail: `pos-plain-letter-shortcuts.md`.

## Provisional/failed modals
- Cashier saves "Provisional" from Pay modal (local status, no PRA submit), later promoted.
- F10 header "Local" modal (list/edit/delete/promote via `/pos/api/provisional-bills*`); F11 "Failed" modal (retry/edit/delete via `/pos/api/failed-bills*`, race-safe atomic claim on retry).

## Reprint modal (Alt+R, 23 Jul 2026)
- Header "Reprint" button + Alt+R (Alt-chord ONLY — plain R must keep typing in search). Read-only list of ALL of TODAY's completed bills via GET `/pos/api/todays-bills` (limit 300); cashiers allowed.
- API visibility MUST mirror `receipt()`: archived rows listed only when `invoice_mode='local'` — an archived PRA row would 404 on the iframe print. Badge from ACTUAL PRA outcome: fiscal number→PRA, local→Provisional, offline/pending→Queue, failed→Failed, NULL→Local.
- Click = print the ORIGINAL receipt, NO COPY label (owner rule 23 Jul 2026). Path mirrors `printReceipt()` for an arbitrary id: silent agent print first (deduped flag = already queued, toast to wait), `_printViaIframe` fallback on the same restaurant/normal receipt URL split.
- Keyboard: ↑↓/Enter/Esc live as ELEMENT-LEVEL @keydown handlers ON the search input itself — the window handleKey has a global input-field gate that swallows keys while any input has focus (and openReprint auto-focuses the search box), so a window-level modal branch alone is DEAD there (architect-caught bug). The window branch exists too for body-focus only. openReprint() resets reprintBusyId (self-heal — onAfterPrint can be skipped by stale print sessions). `showReprint` added to EVERY plain-letter (T/D/N) + F10/F11 gate list — a new modal flag must be added to all of them or cart shortcuts fire underneath.
- FBR universal port deliberately NOT mirrored (PRA-only focus — Quick Type precedent).

## Edit-provisional-in-sale-screen (Jul 2026)
- `?edit_bill={id}` on the sale screen loads a PROVISIONAL ONLY (local/local, NULL fiscal serial, company-scoped); anything else redirects to the classic edit page whose own guards apply. F10 modal Edit links + 'e' key use this; F11 FAILED-bill edits stay on the classic page (deliberate).
- In edit mode: F9 becomes "Update Bill L-XXX" → PUT `/pos/transaction/{id}` JSON (updateTransaction is wantsJson-aware); Pay + Hold are toast-BLOCKED — finalizing must go through the F10 promote path (owns quota/serial/PRA rules). Bill keeps its L-serial and stays provisional on update.
- **saveCart is double-guarded** (entry + inside debounce timer) in edit mode — without the timer re-check the edited bill's cart clobbers the cashier's own saved localStorage cart and resurrects as a duplicate new sale.
- Deal lines in an edited provisional get RE-PRICED at the current deal price server-side (`resolveItemExemptions` enforces); a deleted deal falls back to client price with item_id nulled — pre-existing behavior, more reachable now.

## Saaf skin (Jul 2026) — CSS-only, no fork
- Companies with `pos_dashboard_style='saaf'` get a SKIN, not a fork: `body[data-saaf="1"]` set in pos-app layout + gated `<link>` to `public/css/pos-saaf.css` (all selectors scoped under `body[data-saaf]`) + a handful of `@if($isSaaf)` blade tweaks ($isSaaf defined in a block `@php` at the top of universal.blade.php — inline `@php(...)` with nested parens does NOT compile).
- Secondary toolbar buttons (Rush, Screen Fit, Keys/F1, Quick/F7) carry gated `data-saaf-secondary="1"`; CSS hides them unless `body.saaf-show-all` (toggled by the saaf-only "Mazeed" button). Buttons stay in the DOM so Alpine state + window-level F-keys keep working. Guided-flow focus targets must NEVER get `display:none`.
- **Invariant: Full-style companies' sale-screen HTML stays byte-identical** (modulo whitespace inside tags) — verify any saaf edit by diffing a Full-company curl snapshot before/after.
- Discount rule (owner, 22 Jul 2026, ALL styles): limit% applies to BOTH discount types — amount capped at limit%×subtotal via client `maxAmountDiscount` getter + server clamps in storeInvoice/updateTransaction (cashier-only, mirrors percentage semantics) + RestaurantPosController::holdOrder `$maxAmountFromPct`.

## PWA update hold (Jul 2026)
`window.tnPwaUpdateHold` registered in `_startAutoSync` init: returns true while cart non-empty, pay modal open, submitting, or receipt popup open — pwa-update toast defers auto-reload (retries every 30s; manual Refresh bypasses). Mirrored in FBR universal.
## Sale-screen redesign (24 Jul 2026, owner-approved — DEPLOYED LIVE 24 Jul 2026 + What's New row published)
- **Nav sale-tools teleport**: New Sale / Local F10 / Failed F11 / Reprint Alt+R / Held F3 / sync pill / Switches dropdown live in the TOP NAV via `<template x-teleport="#tn-nav-sale-tools">` (anchor in pos-app.blade.php, OUTSIDE the saaf @if — shared by Full + Saaf navs, empty on other pages). Teleported nodes keep restaurantPos() scope. Old in-page buttons remain as the MOBILE fallback — handler changes must hit BOTH copies.
- **Akhri Bills strip** (under grid, desktop only): today's bills as one-click reprint chips; reuses `reprintBills`/`reprintBill()`; loaded on init (+1.8s) and refreshed via `loadReprintBills()` in BOTH final-sale paths (processPaymentManual + payHeldOrderDirect). Any NEW final-sale path must call it too.
- **Bada total band**: solid `bg-purple-900` (in pos-app theme remap set; saaf CSS §11 maps it to #0A4D5C). All old total rows kept inside it.
- **One-tap CASH/CARD + payPreselect pattern**: buttons/Alt+1/Alt+2 set `payPreselect` (0/1) then open the Pay modal — NEVER instant-finalize. The modal x-effect consumes it: `payMethodIndex = (payPreselect === 1 ? 1 : 0); payPreselect = null` (self-write inside x-effect does not re-trigger). Alt+1/2 handler uses the same modal-guard list as F10/F11/Alt+R + cart-empty guard; alt-chord keeps plain 1/2 qty-typing and in-modal digit-jump intact.
- **Method-aware totals**: getters `cartQtyCount` / `cartTotalForMethod(method)` / `cartMethodHint` mirror taxAmount/cardSaveTotalForRate math for all THREE tax modes; `payModalTotal` now delegates to `cartTotalForMethod` for the live cart in EVERY mode (was cash-only in exclusive — architect-caught mismatch vs the buttons). Estimates only — backend recomputes on submit.
- **Order Notes UI REMOVED / compact cart rows v2 (owner, 26 Jul 2026)**: per-item TAX + Disc are now icon buttons in the MAIN cart row beside trash (% svg = tax, green+cross = NO TAX; tag svg = disc, value text when set — `min-width:28px` INLINE because min-w-[28px] isn't in built CSS); the whole per-item second row is gone. Cart-footer Order Notes textarea DELETED — only the order-level % Discount trigger remains (Limit/Override still inside the discount panel). `kitchenNotes` model/watchers/payloads INTACT: edit-mode/recalled-order notes load + resend invisibly (never lost, but no UI to view/edit them); N shortcut no-ops via its `if (el)` guard; per-item notes (kitchen_notes feature) still the only visible note entry. Do NOT re-add the textarea without owner asking.
- **FBR universal port FROZEN pre-redesign** — dated divergence comments in both files; do not "fix" the diff until owner approves porting.
- E2e note: headless test runs die at native PRINT dialogs (receipt auto-print, reprint chips) — that's the print firing, not a bug; verify sale completion via pos_transactions rows instead.

- 2-row action bar (owner, 24 Jul 2026): row 1 = WIDE customer box (partial flex-1 min220/max520) + Table/order-type + Mazeed + utility buttons (delivery-charge input MOVED 25 Jul 2026 → cart panel strip under Current Order header, customer video feedback; gated @if features->delivery + x-if orderType==='delivery', same setDeliveryCharge mechanism); row 2 (`.tn-action-row2`, carries border-b+shadow) = category dropdown + full-width search + sync/Local/Waiter/Failed/Reprint/Held + Hold/Kitchen/Pay. Cart column 340/400/460px (arbitrary classes — npm build needed; widened again 24 Jul 2026 with cart-footer merge: Save Provisional + PAY now ONE grid row cols-5 = 2+3, PAY stays dominant; text-only product tiles compacted — grid gap-2, tile pad px-2.5 py-2, top badge row min-h-0, + button w-7). Mobile media block targets `.tn-action-row1 > div:first-child` / `.tn-action-row2 > .flex-1.relative` (both 100%). Screen Fit zoom unaffected (fixed 48px nav offset only). FBR port still single-row — see fbr-universal-sale-screen.md.

## Mobile cart layout (25 Jul 2026, owner iPhone fix)
- On phones (`@media max-width:767px` in the inline style) the cart column `.tn-cart-col` is the SCROLL CONTAINER (`overflow-y:auto`) and `[x-ref="cartList"]` flips to `flex:none; overflow:visible` — the whole column scrolls, nothing clips. Desktop keeps the inner-list scroll; don't move these rules out of the media block.
- `.mobile-sticky-pay` must keep a SOLID background (#f9fafb / dark #111827). **Why:** `background:inherit` resolved the parent's translucent `bg-gray-50/80` → the sticky CASH/CARD block hovered see-through over Order Notes (owner screenshot bug). Never use inherit/transparent on sticky bars over scrollable content.
- Mobile action-bar tidy-wrap: `.tn-action-row1/.tn-action-row2 > button` stretch `flex:1 1 auto` + centered, order-type group stretches too — mobile.css §37 44px inflation otherwise wraps pills raggedly. Divider `div.w-px` deliberately NOT stretched (delivery-charge div no longer in action bar — moved to cart panel 25 Jul 2026).
- Auto-Sync pill has `x-cloak` (label is x-text-only → rendered as a blank white square pre-Alpine). Any new x-text-only pill on the sale screen needs x-cloak or default text.
- REMOVED 25 Jul 2026 (customer voice-note, owner-endorsed): floating purple "Edit Cart" pill (overlapped tiles; edit stays via header Edit btn/F6/Ctrl+E/Tab/arrows) AND the entire client-side Smart Upsell ("Suggested Add-on") system incl. its Enter/Esc keyboard priority. Do NOT re-add either without owner go-ahead. FBR frozen port still has both.
