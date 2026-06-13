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
must, when `refocusSearch && guidedFlow`, `$nextTick`-focus the search box (the @blur
handler passes false so clicking away never steals focus). Always test the inventory-OFF
chain end to end, not just inventory-ON.
