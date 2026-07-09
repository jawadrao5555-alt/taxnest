---
name: Pricing plan price storage differs per product_type
description: Which product lines store monthly vs annual prices in pricing_plans, and the rule for quoting prices on any surface
---

The `pricing_plans.price` / sale accessors do NOT mean the same period across product lines:

- `product_type = 'pos'` and `'standalone'` → stored price is **ANNUAL** (in-app pos/billing divides by 12 for display; annual-only billing, 6% already baked in).
- `product_type = 'di'` and `'fbrpos'` → stored price is **MONTHLY** (in-app billing pages apply a cycle toggle: Monthly / Quarterly −1% / Semi-Annual −3% / Annual −6%; annual = ×12×0.94).

**Why:** A landing-page redesign assumed "6% baked into stored price" for fbrpos and quoted ×12 (6% too high) — architect review caught prices that didn't match what checkout actually charges.

**How to apply:** Any surface quoting a price (landing pages, emails, receipts, admin) must mirror the in-app billing page math for that product_type — open the corresponding `*/billing.blade.php` and copy its formula; never assume the storage period.

Note (pre-existing conflict, unresolved as of Jul 2026): replit.md says POS billing is annual-only, but `fbr-pos/billing.blade.php` has the full DI-style cycle toggle. Owner confirmation pending; landing quotes annual ×12×0.94 to match billing's annual option.
