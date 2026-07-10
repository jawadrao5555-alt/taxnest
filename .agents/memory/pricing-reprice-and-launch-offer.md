---
name: Pricing reprice & the "launch offer" mechanism
description: How plan prices are stored/discounted, the single hardcoded price surface, and how to reprice safely for prod
---

# Repricing TaxNest plans

## Where prices live / how the "launch offer" works
- All plan prices are rows in `pricing_plans` (`price` = the live charge; for pos/standalone `price` is the ANNUAL amount, `price_monthly` NULL; di/fbrpos store MONTHLY).
- The "50% Launch Offer" was NOT a sale campaign — it is baked into the base data by a migration: it stored the ORIGINAL price in `compare_at_price` and halved `price`. So a struck-through "was" price on a landing card = `compare_at_price`.
- **To END a launch offer for a product** (charge full price again): set `price = compare_at_price` and clear `compare_at_price` to NULL. That is exactly what an "increase POS price to the old full amount" request means.
- Dynamic sale discounts are a SEPARATE system (`sale_campaigns` + `PricingPlan->sale_price` accessor); `sale_price == price` when no active campaign. Surfaces read `sale_price`.

## The ONE hardcoded price surface
- `resources/views/landing.blade.php` — the product comparison table ("Starting at" row) hardcodes each product's cheapest price as literal text (e.g. `PKR 4,999 / year` for NestPOS/PRA).
- **Every other pricing surface is DB-dynamic** (`/pos` #editions, `/digital-invoice`, `/fbr-pos-landing`, registration, billing all read `PricingPlan`). So on ANY reprice, grep the views for the literal price and hand-edit ONLY landing.blade.php; the rest auto-update.

## How to reprice safely (dev + prod)
- Prod (owner's cPanel) runs `migrate --force`, NOT `db:seed`. So a reprice must be a NEW idempotent migration, not a seeder edit (PricingPlanSeeder only seeds DI + fresh installs).
- Make it admin-safe/idempotent like the launch-offer migration: match `product_type + name + price == expected_CURRENT` before updating, so a re-run (price already new) or an admin-repriced row is skipped, never clobbered. Provide a matching `down()` inverse.
- **Reference reprice (Jul 2026):** PRA POS ended launch offer → Starter 9,999 / Business 14,999 / Pro 24,999 (compare_at cleared). Standalone ~2x → 5,999 / 9,999 / 17,999. Trials stay 0.
