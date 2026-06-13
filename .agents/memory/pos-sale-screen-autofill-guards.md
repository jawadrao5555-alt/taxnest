---
name: POS sale screen — anti-autofill guards on free-text inputs
description: Every cashier-typeable text field on the universal POS sale screen must carry the nofill guard set, or the browser/password manager autofills the cashier's login.
---

# POS sale screen — anti-autofill guards

Any free-text `<input>`/`<textarea>` the cashier types into on the universal POS sale
screen will get the saved login (the email prefix, e.g. `posadmin`) autofilled by the
browser or a password manager. It lands in the wrong places — customer-name fields and
the order-notes box — and then persists (notes are saved to localStorage), so the
cashier sees their own login as the customer / in the bill notes.

**Rule:** Guard EVERY new free-text field with the full set:
`autocomplete="one-time-code"` (use `"off"` for textareas) + a non-semantic
`name="..._nofill"` + `data-lpignore="true"` + `data-form-type="other"` + `data-1p-ignore`.
The product-search and customer-phone inputs already had this; the bug was the fields
that lacked it (order notes, new/quick customer name/phone/address, customer-picker
search, quick-type textarea, manual item name, manager PIN).

**Why:** Owner repeatedly reported their login email prefix showing as the customer name
and inside the first item's notes. Backend never defaults `customer_name` to the auth
user (it is always `$request->customer_name`), so this is purely a frontend autofill
class, not a server bug.

**How to apply:** Add the guard set whenever you introduce an input the cashier types
into. Numeric-only fields (price / qty / discount) are not credible email-autofill
targets and can be skipped. If old polluted values linger, a fresh "New Sale" clears
the notes/customer localStorage.
