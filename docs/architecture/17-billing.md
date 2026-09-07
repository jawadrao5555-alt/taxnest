# 17 — Billing

## Catalog

**FACT:** `pricing_plans` with `product_type` in `di|pos|fbrpos|erps` (+ legacy `standalone` noted in `replit.md`).

Limits: invoice/user/branch/max_* ; feature flags; Nest ERPS `erps_vertical` + `health_modules`.

`-1` = unlimited pattern.

Sale campaigns adjust display `sale_price` (DERIVED from campaign).

## Subscriptions

**FACT:** `subscriptions` link company→plan with:
- `active`, date window, `trial_ends_at`
- Overrides: `lifetime`, `usage_free`, `temporary`, `grace`
- `distributor_quote_snapshot` SNAPSHOT
- Free invoice caps / override_until

**UI rule (`replit.md`):** Override grants Lifetime + Temporary ONLY; grace + usage_free retired in UI but legacy rows honored.

## Access authority

**FACT:** `SubscriptionAccessService::hasAccess()` order:
1. lifetime
2. usage_free until billable count hits cap
3. temporary/grace while `override_until` future
4. else active + dates + trial rules

**billableCount:** product-specific (POS txns, FBR POS txns, DI counted invoices, ERPS vertical callable).

## Quotas

**FACT:** `PlanLimitService` enforces monthly bills, team seats, branches. NestPOS provisionals free until promoted (`replit.md`).

## Payment proofs

Customer uploads proof → admin approve/reject → may grant temporary access (`auto_access_until`). Reminders/prunes scheduled.

## Product billing differences

| Product | Cadence note |
|---------|--------------|
| DI | Monthly↔Annual toggles with discounts |
| NestPOS | Annual-only packages |
| FBR POS | Annual-style packages |
| Nest ERPS | Plans via `erps` + vertical |

## Enforcement surfaces

Middleware / billing pages / feature services (`PosFeatureService`, `DiFeatureService`) / registration gates.
