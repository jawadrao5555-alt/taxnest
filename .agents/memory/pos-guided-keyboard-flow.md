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

**How to apply:** `quickCreateProduct` must advance flowStep off 'customer'; the inline
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
