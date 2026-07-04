---
name: Dynamic sale campaigns
description: How admin-controlled sales work (sale_campaigns + PricingPlan sale_* accessors) and the rules every price surface/charge path must follow.
---

# Dynamic sale campaigns (replaced static compare_at "Launch Offer")

Admin-controlled, auto-expiring discounts. Base price (`pricing_plans.price`) NEVER changes — the discount is computed on the fly.

## Resolution rules
- Sales live in `sale_campaigns` (name, scope ∈ [all,di,pos,fbrpos], discount_percent, starts_at, ends_at, is_active).
- Resolve via `PricingPlan` accessors ONLY: `sale_price` (= `round(price*(1-pct/100))`), `sale_percent`, `sale_ends_in_days`, `sale_badge` ("30% OFF · ends in 5 days"). Never read a campaign row directly from a view.
- Scope precedence: product-specific (di/pos/fbrpos) beats `all`; tie → highest %.
- Active = `is_active` AND now within `[starts_at, ends_at]`. `ends_at` stored `endOfDay` (final day inclusive). **Auto-expires by pure date compare — NO cron.**
- `SaleCampaign::currentlyActive()` is memoized per-request (1 query + one `Schema::hasTable` guard). Every admin sale write calls `SaleCampaign::clearActiveCache()`.

## Rules for future work
- EVERY price surface AND charge path must read `sale_price`, never `price`. Charge paths: `BillingController::subscribe` + `calculatePrice`, `SubscriptionAssignmentService::assign`. The 6 display surfaces: pos/billing, pos/landing, di-landing, billing/plans, fbr-pos/billing, fbr-pos/landing (+ admin plan-card).
- FBR Alpine cards: inject `basePrice = {{ $plan->sale_price }}`, `compareBase = {{ $plan->price }}`; `hasOffer = compareBase > basePrice`. Badge text is static `{{ $plan->sale_badge }}` (sale doesn't change with cycle).
- `subscribeCustomPlan` (custom-plan builder) INTENTIONALLY ignores sales — it's formula-based; sales apply only to preset `pricing_plans`.
- `final_price` is a SNAPSHOT taken at assignment time. If a sale expires between customer-pay and admin payment-proof approval, the recorded price is the then-current one. Accepted drift.
- `compare_at_price` COLUMN is kept (still in fillable/casts + PricingPlanSeeder writes it) but is DEAD — all blade reads and admin form inputs were removed. Do NOT re-add compare_at reads; use the `sale_*` accessors.

**Why:** owner wanted admin-set sales (any %, any days, scope all/di/pos/fbrpos) that auto-expire by date without hiking the base price; the old static `compare_at_price` "50% OFF · Launch Offer" was hardcoded and inflexible.

## Related trap
- POS `pricing_plans.price` is ALREADY annual (6% baked in — see replit.md). `pos/billing.blade.php` must NOT `×12` or re-apply 6%; use `round($plan->sale_price)`. (An old `price*12*(1-6%)` was the ×12 over-charge bug this feature fixed.)
