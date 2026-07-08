---
name: Eloquent missing attribute silent null
description: Reading a non-existent model attribute returns null silently — zeroed money math in POS fixed-price guard
---

# Eloquent missing-attribute reads return silent null (money-math killer)

**Rule:** `$model->someColumn` on a column that does not exist returns `null` with NO error. Casting it (`(float)`) turns it into `0`. In money paths this silently zeroes prices/totals instead of failing loudly. Product's price column is `default_price` — there is NO `price` column.

**Why:** the FBR POS fixed-price anti-tamper guard read `$product->price` (column is `default_price`) — every sale of a fixed-price (`is_price_editable=false`) product became Rs 0 per line, total = just the Rs 1 FBR charge. Bills stored as Rs 1.00 with zero tax; no exception, no log. Found only by a realistic end-to-end journey (register fresh shop → add products via the real form endpoint → sell).

**How to apply:**
- When a computed money figure comes out absurdly small/zero with no error, suspect a wrong attribute name before suspecting the math. Verify with `SHOW COLUMNS` / the model's `$fillable`, not by eye.
- Any server-side "force value from DB" guard must reference the real column (`default_price` for Product) — and a quick tamper-test curl (crafted low price on a fixed-price product) is the cheapest way to prove the guard actually works.
- Realistic new-user journeys (fresh company via the actual register endpoint + real form posts with default/unchecked checkboxes) surface bugs that reused seasoned test companies never hit — checkbox-absent = false (`$request->boolean()`) flips flags like `is_price_editable` that older rows have as true.
