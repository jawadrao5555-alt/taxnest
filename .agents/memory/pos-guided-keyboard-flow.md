---
name: POS guided keyboard flow — Enter must always advance
description: In the opt-in PRA POS guided billing flow, every step's Enter must move the chain forward and never be blocked by a modal/forced sub-form.
---

# POS guided keyboard flow — Enter must always advance

The universal PRA POS sale screen has an opt-in "Guided Keyboard Billing" flow
(per-company toggle) that chains steps customer → items → cart → finish, driven
entirely by Enter.

**Rule:** In guided mode, every step's Enter must move the chain FORWARD. The customer
step is OPTIONAL: with an EMPTY or non-numeric box, Enter advances to item search
(unmatched = walk-in). But when the cashier HAS typed a valid mobile number, Enter is a
first-class KEYBOARD add-customer path (owner requirement, Jul 2026): matched → attach +
advance; unmatched → open the INLINE new-customer form (name → Enter → address → Enter
saves & advances). This inline form is additive and non-blocking (Esc skips), so it does
NOT violate "never pop a blocking modal." Do NOT downgrade this back to "add via mouse
click only" — the owner explicitly asked for full keyboard add-customer.

**Why:** The customer-phone Enter handler opened the inline "+ New Customer" modal for
any unmatched number, which stalled the entire keyboard chain at step 1 — the cashier
could neither continue nor keyboard-add products, so the owner reported "keyboard flow
doesn't work / products don't show."

**How to apply:** When adding a new guided step, gate it on the guided-flow getter and
make its Enter handler advance `flowStep` + focus the next field. Note the customer
lookup is debounced (~300ms), so attaching a matched customer on a fast type-then-Enter
is best-effort, not guaranteed.

## There are TWO item-add paths — wire BOTH

The items step has two distinct code paths and the guided chain breaks if you only
wire one:
1. Saved-product match → `quickAddItem` (advances flowStep customer→items, refocuses search).
2. Inventory-OFF unmatched line → `quickCreateProduct` (creates the product, opens the
   inline price editor) then `saveQuickPrice` on Enter.

**Why:** Owner runs inventory-OFF (manual/quick entry), so they ONLY hit path 2. The
flow felt "still not working" because `quickCreateProduct`/`saveQuickPrice` did not
advance `flowStep` and did not return focus to search after the inline price was
committed — the chain stalled after the first item even though path 1 worked.

## Restaurant register handleKey ordering must mirror universal (hotkeys → qty gate)

When porting the guided flow into the RESTAURANT register (`pos/restaurant/pos.blade.php`),
the keyboard "felt dead while a field is focused" for the SAME reason every POS register
hits: `handleKey()` bailed out (`if (isInput) return`) before reaching any F-key. The fix
is the universal ordering, and it took 3 review rounds to get right:

1. **Global hotkeys (F1–F8 / Alt+P / Ctrl+S / Ctrl+E) must run BEFORE the `data-qty-input`
   gate AND before the `if (isInput) return` gate.** If the qty gate runs first it swallows
   F-keys while a qty box is focused — and F5 then reloads the *browser* instead of holding
   the order.
2. **Hoisting hotkeys above the input gate re-introduces a leak: F4/F8 fire behind any modal
   whose input is focused.** Gate the hotkey block behind a `blockingModal` flag (new-customer
   / table / customer-picker / shortcuts / quick-type / history / low-stock). When a blocking
   modal is open, still `preventDefault()` the reserved keys (F1–F8, Ctrl/Cmd+S/E) so the
   native browser action is suppressed, but run NO app action; let every other key fall
   through so modal typing + Esc keep working.
3. **A "capturing" modal must capture whenever it is OPEN, not only when it has rows.** The
   held-orders handler gated on `heldOrders.length > 0`, so an *empty* held modal let F4/F8
   act behind it. Capture on `showHeldOrders` alone; close on Esc first; nest the row actions
   (Arrow/Enter/P/D) under the length check so they can't deref `undefined`.

**Why:** these are not visible by reading the final code — they are the three distinct
ordering traps that each looked fine in isolation. **How to apply:** keep restaurant's
`handleKey` ordering = capturing-modals(return) → T-exceptions → `if(blockingModal){suppress
reserved}else{hotkeys}` → qty gate → `if(isInput)return` → Escape → routing, identical to
`universal.blade.php`. The whole guided layer is gated on `pos_guided_flow_enabled`; the
hotkey hoist + pay-modal `Enter && !e.repeat` are the only UNGATED (always-on) changes.

**How to apply (original inventory-OFF note):** `quickCreateProduct` must advance flowStep off 'customer'; the inline
price input's Enter handler must call `saveQuickPrice(index, true)` and `saveQuickPrice`
must, when `refocusSearch && guidedFlow`, refocus the search box. CAUTION: `saveQuickPrice`
runs in the cart x-for ROW scope, so `this.$refs.searchInput` is `undefined` there — a
`$nextTick`/`$refs` refocus silently no-ops. Refocus via
`document.querySelector('input[name="pos_product_search_nofill"]').focus()` (see
alpine-xfor-refs-scope.md), scheduled on `$nextTick` + `setTimeout(0)`/`setTimeout(60)`
to beat the x-if teardown blur. The @blur handler passes false so clicking away never
steals focus. Always test the inventory-OFF chain end to end, not just inventory-ON.

## Guided flow ORDER-TYPE step — restaurant UNCONDITIONAL, universal FEATURE-GATED

BOTH registers now have the order-type step (customer → items → **type** → cart → finish).
Empty-search Enter (cart non-empty) opens an arrow-navigable order-TYPE picker; Enter on the
chosen type drops into the cart ("double Enter": empty-search Enter leaves items → type, then
Enter on the type → cart).

- **Restaurant** (`pos/restaurant/pos.blade.php`): UNCONDITIONAL 3 types (dine in / takeaway /
  delivery). Owner explicitly required it; do NOT "simplify" it back to a 4-step shape.
- **Universal** (`pos/universal.blade.php`): FEATURE-GATED. A @php block builds `$guidedTypes`
  from PosFeatureService flags — dine_in only if `$features->tables`, takeaway always, delivery
  only if `$features->delivery` — and `$hasTypeStep = count > 1`. The whole step (overlay,
  coach-strip "Type", handleKey block) exists only when 2+ types; single-type retail takes the
  original straight-to-cart path and is byte-identical. JS mirror: `guidedOrderTypes().length > 1`.
  E.g. company 11 (pharmacy, delivery ON, tables OFF) = Takeaway + Delivery (no Dine In until
  Tables enabled). Feature resolution uses `business_category`/`restaurant_mode` + `feature_flags`
  JSON override, NOT `pos_type`.

**Why:** universal used to have NO type step; it was added feature-gated so retail with one type
is unaffected while delivery/restaurant-style companies get the picker.

**How to apply:** the type step is a capturing state, NOT a step-strip-only indicator. Its
handler lives in `handleKey` placed right AFTER the `showManagerPinModal` capture block (so
it owns Arrow/Enter/Esc and swallows F1–F8 / Ctrl+S+E while active) and `return`s for every
key. `enterTypeStep()` seeds the highlight from the current `orderType` and blurs the search
box; `confirmGuidedType()` commits the type **through `setOrderType()`** then enters cart mode.
Gate the empty-search→type transition on `cart.length > 0` (can't bill empty). The overlay +
all of this is gated on `guidedFlow`, so plain mode is byte-identical.

## Dine In in the type step MUST open the table picker (owner REVERSAL, mid-Jul 2026)
The original spec said "type → cart directly, table stays optional" — the owner REVERSED this:
choosing Dine In (guided type step OR the Dine In pill) must open the Select Table picker when
no table is selected. Do NOT flip it back. The working shape:
- `confirmGuidedType()` routes through `setOrderType(picked)` (never assigns `orderType`
  directly — direct assignment silently skips the picker AND skips table release on switch-away).
  It skips `enterCartMode` when the picker opened; `selectTable()` resumes the chain
  (`guidedFlow && flowStep==='cart' && !cartMode → enterCartMode('last')`).
- The picker is a full capturing keyboard state (same pattern as the type step): a
  `showTablePicker` branch in `handleKey` right after the type branch — arrows move a highlight
  across `tablePickerFlat()` (flattened floors), Enter (!e.repeat) reserves, Esc closes,
  everything else swallowed. `openTablePicker()` seeds index 0 + blurs the active element.
  The search input's Enter (`.prevent.stop`) must FORWARD to the picker (like the type-step
  forwarding) and `addHighlightedItem`'s guided empty-Enter branch is gated `!showTablePicker`
  — without both, Enter re-opens the type overlay UNDER the picker (z-stack mess = the owner's
  "flow toot raha").
- `clearCart()` resets `orderType='takeaway'` — a finished dine-in sale otherwise leaves
  `orderType='dine_in'`, so the next sale's type step seeds on Dine In and a natural fast
  Enter-Enter pops the picker "out of nowhere" (the owner's "table pehle show ho raha").
**Why:** three separate root causes produced one vague symptom ("Dine In par table nahi aa
raha / flow toot raha"); every new modal on this screen must own the keyboard like the type
step or the document-level handleKey + `.stop`ped input handlers will fight it.

**Universal placement specifics:** the type block sits at the VERY TOP of `handleKey` (before the
global F-key hotkeys) so it fully owns the keyboard while active. Its Enter-confirm MUST carry
`!e.repeat` (`if (e.key === 'Enter' && !e.repeat)`) — otherwise a HELD Enter on the empty search
box opens the step (first keydown, `.prevent.stop`), `enterTypeStep()` blurs the input, and the
auto-repeat keydowns land on the document handler and instantly `confirmGuidedType()`, silently
SKIPPING the owner's step. The search input's Enter is `.prevent.stop` (see the double-fire section
below).

## The OPENING side has the same x-for-refs trap — fix BOTH ends

`saveQuickPrice` (closing the price editor + refocusing search) was the first half fixed,
but the OPENING half had the identical bug and was missed for a long time: `openQuickPrice`
focused the inline price input via `this.$refs.quickPriceInput`. That input's `x-ref` lives
inside the cart x-for ROW, and `openQuickPrice` is called from `quickCreateProduct` in
COMPONENT-ROOT scope, where a row ref is unreachable → the focus silently no-op'd. The price
box appeared but never took focus, so the cashier's typed price went into the search box and
the chain died at the FIRST item — the exact "keyboard flow nahi ban raha" the owner kept
reporting even after the saveQuickPrice fix.

**Rule:** Both ends of an inline-editor focus handoff (open AND save) that touch an element
inside an x-for must resolve the live node by attribute, never `this.$refs`. The price input
carries `data-quick-price-input`; `openQuickPrice` focuses via
`document.querySelector('[data-quick-price-input]')` on `$nextTick` + `setTimeout(0)`/`(60)`.
**Why:** any x-for-row element targeted from root scope is invisible to `this.$refs`. When you
fix one focus hop in this pattern, audit the sibling hop immediately — they almost always come
in pairs (open/close, enter/exit).

## An input's `@keydown.enter.prevent` that mutates state then blurs DOUBLE-FIRES via the document handleKey

`handleKey` is attached at the DOCUMENT level (`document.addEventListener('keydown', …)`). If an
input's `@keydown.enter.prevent="x()"` mutates a state flag (e.g. `flowStep='type'`) and blurs the
input, the SAME Enter keydown keeps bubbling to the document listener — which now reads the just-set
flag and acts on it. In the restaurant flow this made the empty-search Enter open the order-TYPE
overlay AND, in the same keypress, the document handler's `flowStep==='type'` block instantly ran
`confirmGuidedType()` → cart. The overlay flashed and closed; owner saw "type screen skipped, went
straight to cart."

**Rule:** any input Enter handler that toggles a flag the document `handleKey` also routes on MUST
use `.prevent.stop` (stopPropagation), not just `.prevent`. `.prevent` only blocks the native action;
it does NOT stop the bubble to a document-level keydown listener.
**Why:** `blur()` does not stop an in-flight event's propagation — the event already started bubbling.
**How to apply:** subsequent Enters are fine because the handler blurred the input, so they target
`<body>` and only `handleKey` fires (proper "double-Enter": 1st opens the step, 2nd confirms).

## Restaurant "0-9 Set Qty" needs the FOCUSED-qty cart model (universal's), not the blurred model

Both registers' cart hint says "0-9 Set Qty / +/− Qty / Del Remove", but neither `handleCartKeys`
has a digit handler. It works in UNIVERSAL only because universal keeps the active row's
`[data-qty-input]` FOCUSED+selected throughout cart mode (`enterCartMode`/`moveCartSelection` →
`focusActiveQty()`), so digits/dot/backspace type straight into the native input and the
`isQtyInput` gate in `handleKey` intercepts the cart shortcuts (arrows/Tab/Enter/+/-/T/Del/Esc).
The restaurant originally used a BLURRED model (`enterCartMode` did `document.activeElement?.blur()`,
gate handled only Arrow/Enter) so digits hit `handleCartKeys` → no handler → nothing typed.

**Rule:** to support direct qty typing, mirror universal's focused-qty model wholesale — do NOT
half-switch. Focus+select the qty box on enter/move, and EXPAND the qty gate to handle +/-/=, T,
Delete, Escape, Tab, AND a letter→search escape hatch, letting digits/dot/backspace fall through.
**Why:** with the box focused the qty gate becomes the primary cart router; any shortcut you forget
to add there silently dies (e.g. +/- get sanitized away by `onQtyInput`). Two gotchas: (1) while a
qty box is focused, `x-effect` skips model→input sync, so +/- must write `e.target.value` manually
after `updateQty`; (2) `removeFromCart` splices after a 250ms exit animation, so refocus the next
row via `setTimeout(…, 280)`, not an immediate `$nextTick`. Also point EVERY enter-cart path at
`enterCartMode()` (the F6 keydown branch had its own inline blurred copy) so qty focus is consistent.

## E2E-VERIFIED working in dev — recurring "still not right" = DEPLOY GAP, not a code bug
Playwright drove the full inventory-OFF chain on company 11 and confirmed every focus hop:
load→customer box focused; Enter→product search; type name+Enter→inline price box focused, 1 cart row;
type price+Enter→focus RETURNS to product search (does NOT jump to cart); 2nd item→2 rows; empty-search
Enter→cart qty input; Enter→Pay modal (Cash/Card, TOTAL shown). So the chain is correct in current code.
**Rule:** if the owner again reports the guided flow "jumps direct to cart" / "still not right," first suspect
a DEPLOY GAP (live behind origin/main) per deploy-fix-but-stale-cache.md — re-confirm with the e2e drive
before touching code. Do NOT re-investigate by reading; reading already looked correct every prior round.

## Enter on an async-search field must SETTLE the search, never dead-end on the in-flight guard
The customer-phone lookup is DEBOUNCED (~300ms). `onCustomerPhoneEnter()`'s guided branch began
with `if (this.customerSearching) return;` — so pressing Enter WHILE the debounced fetch was still
in flight was a silent no-op. A fast cashier who typed a number and immediately hit Enter fell into
that window every time, so keyboard add-customer "didn't work" and they reached for the mouse.

**Rule:** an Enter handler on a debounced-search field must not bail while a fetch is pending — it must
SETTLE the search first, then act deterministically. Pattern used here: `searchCustomerByPhone()` stores
its in-flight fetch in `this._custSearchPromise` (own `.catch` swallows errors so it never rejects) and
returns it; the Enter handler clears the debounce timer, `await`s that same promise if one is running (no
duplicate fetch) else runs a fresh search when results are empty, THEN attaches `results[0]` or opens the
inline new-customer form. Enter is now never a no-op — even a failed/offline search resolves and falls
through to opening the form.
**Why:** `blur()`/return-early feels harmless but the pending-fetch window is exactly when an eager
keyboard user presses Enter. **How to apply:** any "type-then-Enter" field backed by a debounced async
lookup (customer, product, HS) needs this settle-then-act shape, not a `if(searching)return` guard.
Focus handoff into the opened inline form needs `$nextTick` + a `setTimeout(~80ms)` fallback (guarded by
the still-open flag) to beat the x-show/x-transition paint race. Pre-existing non-blocking gap: if the
cashier pauses mid-number (stale prefix results present) then finishes typing and Enters, the non-empty
stale results short-circuit the fresh search and can attach the wrong customer — fix only if reported.
