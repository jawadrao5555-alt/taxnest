---
name: FBR universal sale screen port
description: Invariants of fbr-pos/universal.blade.php (ported from PRA) — shim pins, held-sale endpoints, dead gated paths
---

# FBR POS universal sale screen (port of PRA universal)

**Rule:** `resources/views/fbr-pos/universal.blade.php` is a line-for-line PORT of `pos/universal.blade.php` (PRA source = never edit). It opens with a SHIM `@php` block pinning `$features` (tables/kot/kitchen/recipes/inventory), `$taxRules`, `$hasManagerPin` etc. OFF/empty. Never delete those pins — the PRA markup they gate stays in the file and would 500 on undefined vars.

**Why:** keeping the PRA markup in-file (dead but compiling) keeps future diffs against the PRA source reviewable; deleting sections makes every upstream port a manual re-merge.

**How to apply:**
- PRA-only endpoints (`/pos/restaurant/...`, `pos.api.toggle-auto-kot`) may legitimately remain in the file — but ONLY inside `@if($features->kot)` / `isRestaurantMode` / `lastOrderId` dead gates. Any LIVE path (hold button F5, pay, receipts, F10/F11 APIs) must hit `/fbr-pos/...` routes. Grep `'/pos/` after any re-port and check each hit's gating.
- Held sales are NOT restaurant orders in FBR: `FbrPosPhase2Controller` stores an opaque `cart_data` JSON (items incl. hs_code/uom/tax_rate + discount + customer + buyer NTN + total_amount snapshot for the F3 list). `GET /fbr-pos/api/held/{id}/recall` deletes the row via a CONDITIONAL delete (affected-rows claim; loser gets 409) — a plain read→delete is NOT race-safe, two terminals can both read before either deletes. Recall failure must reload the list. Pay-held = recall into cart → normal Pay modal → `processPaymentManual` → `fbrpos.store` (payingHeldOrderId path is dead).
- FBR tax is per-item (18% default / exempt); any payment-method tax label (PRA cash 16%/card 8%) surfacing in a ported view is a bug — show cart `taxAmount` instead.
- FBR theme engine remaps blue-X (PRA remaps purple-X) — port = translate purple-X→blue-X and re-check gradient second stops.
- Verification recipe that catches real breaks: view:cache → php -l the COMPILED view → blade-strip + node --check the biggest script → curl login (field `login`) + JSON APIs with csrf-token meta from a rendered page.
- Saaf skin (Jul 2026): PRA universal now contains `@if($isSaaf)` gated tweaks (pos-saaf.css link, Mazeed button, `data-saaf-secondary` attrs, Roman Urdu search placeholder). On any re-port to FBR, the SHIM must pin `$isSaaf = false` (FBR POS has no saaf style) — otherwise undefined var = 500.
