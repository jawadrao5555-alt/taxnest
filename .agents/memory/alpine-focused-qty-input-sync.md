---
name: Alpine focused qty-input display sync
description: Why keyboard qty changes can show a stale digit on the POS cart qty input, and the rule for fixing it.
---

# Alpine focused qty-input display sync

The POS cart qty input keeps its displayed value in sync with the model via an
`x-effect` that is **guarded to skip the focused element**
(`if (document.activeElement !== $el) { $el.value = item.quantity }`). This guard
exists so user typing is never clobbered mid-edit.

**Consequence / rule:** any keyboard handler that mutates the cart quantity *model*
(`updateQty`/`setQty`) **while the qty input has focus** must ALSO write
`e.target.value = this.cart[ci].quantity` itself — otherwise the model updates but
the focused input visibly stays on the old digit until it loses focus.

**Why this is only a display bug, never a billing bug:** the model is the source of
truth — line totals (`getItemTotal`) and `onQtyBlur` both read
`this.cart[index].quantity`, not the input's `.value`. So a stale digit never
reaches the bill. But it looks broken to the cashier (and to automated tests).

**How to apply:**
- Keyboard `+`/`-`/digit shortcuts dispatched while the qty input is focused (the
  `isQtyInput` branch of `handleKey`) → write `e.target.value` after the model update.
- The same shortcuts handled while focus is on the cart *body* (e.g. `handleCartKeys`,
  focus not on the input) do NOT need it — the `x-effect` syncs them because the
  input is not the active element there.
- Writing `e.target.value` programmatically does NOT fire an `input` event, so it
  will not re-trigger `onQtyInput` (no reactivity loop).
