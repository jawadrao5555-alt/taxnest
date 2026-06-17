---
name: POS guided keyboard flow — Enter must always advance
description: In the opt-in PRA POS guided billing flow, every step's Enter must move the chain forward and never be blocked by a modal/forced sub-form.
---

# POS guided keyboard flow — Enter must always advance

The universal PRA POS sale screen has an opt-in "Guided Keyboard Billing" flow
(per-company toggle) that chains steps customer → items → cart → finish, driven
entirely by Enter.

**Rule:** In guided mode, every step's Enter must move the chain FORWARD. No guided
step may pop a modal or forced sub-form on Enter. The customer step is OPTIONAL: Enter
advances to item search whether or not the typed phone matches a customer (unmatched =
walk-in). Explicit actions like creating a new customer stay available via click, not as
a blocking Enter side-effect.

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
