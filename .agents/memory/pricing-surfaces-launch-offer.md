---
name: Pricing surfaces & launch-offer display
description: The six pricing views that must stay in sync, the compare_at_price 50% OFF badge convention, and a pre-existing pos landing/billing price-semantics mismatch.
---

# Pricing surfaces & launch-offer display

There are SIX customer-facing pricing surfaces that must stay in sync when plan pricing or
discount display changes:
- **3 billing pages** (logged-in): `billing/plans` (DI, emerald, Alpine `calcMonthly/calcPrice`
  with a cycle toggle), `pos/billing` (PHP, annual-only ×12×0.94), `fbr-pos/billing`
  (per-card Alpine `basePrice` + getters).
- **3 landing pages** (public): `di-landing`, `pos/landing`, `fbr-pos/landing`.

## Launch-offer / compare-at convention
`pricing_plans.compare_at_price` (nullable) holds the OLD (pre-discount) price. Show the offer
only when `!empty($plan->compare_at_price) && $plan->compare_at_price > $plan->price`
(`$hasOffer` in PHP views, `hasOffer` getter in Alpine cards). Display = a solid **rose** pill
badge "50% OFF · Launch Offer" + a strikethrough compare price. Rose is used everywhere
(rose ≠ blue, solid, no glow) so it also honors the POS owner's no-blue / clean-solid /
no-colored-glow rules. In Alpine cards run the compare price through the SAME cycle formula as
the live price (e.g. `compareTotal` mirrors `totalPrice`) so the 2× ratio holds at every cycle.
`text-[11px]` + `bg-rose-*` are arbitrary/rare classes — run `npm run build` or they render
invisible (see vite-arbitrary-classes memory).

## Admin management surface
The SaaS-admin Plan Builder (`AdminPlanController` store/update + `saas-admin/plans.blade.php`
create form + `saas-admin/partials/plan-card.blade.php` edit form & card) is the 7th surface
and the ONLY place to set/clear `compare_at_price`. Rules: store/update use an EXPLICIT field
list (NOT `$request->except(...)`) so fillable-but-unposted columns like `is_trial` can't be
injected; a BLANK compare-at input → null → offer cleared (relies on ConvertEmptyStringsToNull).
The card view-mode shows a rose strikethrough + `round(100 - price/compare*100)`% OFF, guarded by
the same `compare > price` `$hasOffer` check as the customer views.

## GOTCHA: pos landing vs billing price semantics (pre-existing, NOT introduced by offer work)
`pos/landing` renders `$plan->price` labelled **"/year"** (e.g. 4,999/year) while `pos/billing`
treats the same `price` as MONTHLY and shows `price×12×0.94` (≈ 56,389/year). That is a ~12×
customer-facing contradiction for the identical plan. The strikethroughs follow each view's own
convention so the offer ratio is preserved, but the underlying mismatch remains — resolve which
view is wrong before relying on POS landing prices.
