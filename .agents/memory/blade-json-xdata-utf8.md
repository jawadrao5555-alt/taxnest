---
name: Bare @json in Alpine x-data dies on invalid UTF-8
description: Why DB free-text emitted via @json into an Alpine x-data object literal can kill an entire POS sale screen, and the safe-encode fix.
---

# @json + invalid UTF-8 = dead Alpine component

A POS sale screen built as one big Alpine `x-data` object literal will fail to
initialize **entirely** (every button / cart / keyboard binding dead at once —
looks like a deploy or JS bug) if ANY DB free-text field emitted via Blade
`@json(...)` contains an invalid UTF-8 byte sequence.

**Mechanism:** `json_encode()` returns `false` on malformed UTF-8 (and on
NaN/Inf, depth overflow, resources). Blade `@json` is `echo json_encode(...)`,
so `echo false` prints an empty string → `allProducts: ,` inside the object
literal → JS syntax error → Alpine cannot evaluate the x-data expression → the
component never mounts. On a restaurant POS the tell is: order-type buttons,
cart, and guided keyboard flow are ALL unresponsive simultaneously.

**Trigger seen in the wild:** owner UN-HID a legacy product whose name carried
bad bytes (latin1/utf8 mismatch on an old import); it re-entered the sale-screen
query and took the whole screen down. LIVE was already on the latest commit — so
it was DATA, not a deploy gap. (Verify deploy currency first via a latest-commit
fingerprint before assuming data; here the panel-aware 404 redirects proved it.)

**Fix:** never emit DB free-text into an x-data literal with bare `@json`. Use a
UTF-8-safe encoder with a guaranteed-valid fallback:
`json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?: '[]'`
(`'{}'` for maps, `'null'` for optional objects). The SUBSTITUTE flag keeps the
products visible (bad bytes → U+FFFD); the `?:` fallback guarantees the literal
is always valid JS even if encoding fails for some other reason. The HEX_* flags
match Laravel's @json defaults (this repo's vendor uses them), so no escaping
regression.

**Why a JS-side `Array.isArray` guard is NOT enough:** the syntax error is in the
x-data literal itself, so no component JS ever runs. The fix MUST be server-side
— always emit syntactically valid JSON text.

**How to apply:** the two live PRA-POS sale screens
(`resources/views/pos/universal.blade.php`, `resources/views/pos/restaurant/pos.blade.php`)
each define a `$jsEnc` closure in their `@php` block and route every DB-backed
value through it (products/services/customers/ingredientCosts/lowStockAlerts/
selectedTable/heldOrders/taxRules). Bool/enum/numeric values (kitchenSettings,
scalar casts) can stay bare. The same vulnerable bare-`@json` pattern still
exists on non-sale pages (`products.blade.php`, `edit-transaction.blade.php`,
dead `create-invoice.blade.php`) — harden them the same way if they ever crash.
