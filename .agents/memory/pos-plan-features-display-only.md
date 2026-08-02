---
name: pricing_plans.features is display-only
description: What actually gates plan features vs what the features JSON does; how plan cards must render
---

# pricing_plans.features JSON is DISPLAY-ONLY

**Rule:** No PHP code consumes `pricing_plans.features` programmatically — it is pure marketing copy rendered on plan cards. Actual enforcement lives elsewhere:
- Restaurant gate = `pricing_plans.restaurant_enabled` COLUMN OR an active (non-expired) trial subscription (owner decision Jul 2026: trials get restaurant/kitchen to evaluate; mask returns automatically on expiry) — see `PosFeatureService::restaurantAllowed()`.
- Numeric limits = `invoice_limit` / `user_limit` / `branch_limit` columns via PlanLimitService.
- Everything else (deals, offline sync, guided billing, etc.) is NOT plan-gated — listing them under higher tiers is deliberate upsell positioning, not enforcement.

**Why:** replit.md once claimed the restaurant gate read the features JSON — editing that JSON looked like a functional regression risk when it is actually safe. Verified Jul 2026: grep shows features JSON only appears in 6 views.

**How to apply:**
- Editing feature lists (marketing copy) = safe, data-only idempotent migration matching `product_type` + plan `name` (prod runs `migrate --force`, never seeds). Name-match means a renamed plan silently keeps old copy.
- Feature JSON must NOT duplicate limit columns — POS plan cards (pos/landing, pos/billing) render limits from columns via `get*LimitDisplay()` and print cumulative "Everything in <prev>, plus:" (`$plans[$loop->index - 1]`, price-ordered collection) above tiers 2+.
- Never re-add hardcoded plan-name feature branches in views (old 'Retail'/'Industrial'/'Enterprise' checks rotted silently when plans were renamed).

## UPDATE Aug 2026: real plan gates now exist (matrix)
`pricing_plans.features` JSON is STILL display-only, but premium features are now HARD-gated via boolean plan columns (PLAN_GATES in PosFeatureService + planAllows()): deals, riders, hazri, analytics, reports(exports), rider_tracking, custom_access, qr_menu. Matrix (2 Aug 2026, Pro Max added at Rs 34,999/yr between Pro & Unlimited): Starter=none, Business=deals+exports, Pro=restaurant+analytics (NO riders/hazri/QR menu), Pro Max=Pro+riders+hazri+qr_menu (5000 bills/15 team/3 branch), Unlimited=everything incl. custom_access (Unlimited-only, like rider_tracking), Trial row=0s (active-trial rule grants everything, expired trial locks). QR menu public page = plain 404 when plan lacks it (no upgrade pitch to customers); receipts/QR blocks all read `PublicProfileController::publicUrlFor()` which returns null when gated. Custom Access stored sets go INERT (customSet→null) on downgrade — nobody locked out. Non-'pos' product types keep all gate columns TRUE (fail open).
**Why:** owner (2 Aug 2026) wanted packages to actually control features, not just display.
**How to apply:** any NEW premium feature must (1) get a plan column via idempotent migration, (2) join PLAN_GATES, (3) gate EVERY entry point — page + JSON + write paths (storeInvoice deal lines rejected 422; rider_id silently dropped so offline replays never lose bills) + nav/buttons. planAllows fails OPEN if the column is missing (lagging PROD migrate). Same source order as restaurant: internal → override → plan → active trial.
