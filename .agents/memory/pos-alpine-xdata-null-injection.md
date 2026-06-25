---
name: POS sale-screen Alpine component death from null numeric injection
description: Why "everything is not defined" floods the POS console, and how to diagnose/fix it without assuming a regression
---

# POS x-data="restaurantPos()" full-component death

Both POS sale screens — `resources/views/pos/universal.blade.php` (the live sale screen) and `resources/views/pos/restaurant/pos.blade.php` (restaurant register) — bind `x-data="restaurantPos()"`. If that function throws or is syntactically invalid, Alpine reports EVERY binding as "X is not defined" (toast, managerPin, lastOrderId, taxRate, …) — a console flood that looks like a total crash even though the server HTML renders 200.

**Most common trigger:** a bare numeric Blade echo inside the returned object renders empty when the controller passes a null-ish value (e.g. during a DB cold-start). `taxRate: {{ $taxRate }}` → `taxRate: ,` → invalid JS → the whole object literal dies. Booleans were always safe (ternary emits literal `true`/`false`); `@json(...)` is safe (null → `null`).

**Fix pattern:** wrap every JS-context numeric injection as `{{ (float) ($x ?? 0) }}`. This handles null, empty-string, undefined-var, and non-numeric. Applied to `taxRate`, `discountLimit`, and the `manager_discount_limit` echo in `effectiveDiscountLimit`/`getMaxDiscountLimit` in BOTH blades.

**Why:** `?? 0` alone does NOT catch an empty string (only null/undefined); the `(float)` cast is what guarantees a valid numeric literal in every case.

**How to diagnose — do NOT assume a regression:**
- These floods are usually STALE in the browser console buffer from a transient dev cold-start (MySQL sleep/wake warm-up). They self-heal on reload; the LIVE site (persistent MySQL) is unaffected. Match the console error timestamp against the cold-start window before touching code.
- Verify whether the function is actually broken with a *blade-stripped* node check — raw `node --check` chokes on `@json(...)` with nested parens and `{{ }}` containing PHP `=>`. Build a stripper that removes `{{}}`/`{!! !!}`/`@dir(...)` with balanced-paren handling while preserving newlines, write the function's line range to a temp .js, then `node --check`. If it reports CLEAN, the component is fine NOW and the console errors were transient — no code fix needed beyond hardening the injections so it can't recur.
