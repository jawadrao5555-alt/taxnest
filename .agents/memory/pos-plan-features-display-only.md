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
`pricing_plans.features` JSON is STILL display-only, but premium features are now HARD-gated via boolean plan columns (PLAN_GATES in PosFeatureService + planAllows()): deals, riders, hazri, analytics, reports(exports). Matrix: Starter=none, Business=deals+exports, Pro/Unlimited=all, Trial row=0s (active-trial rule grants everything, expired trial locks).
**Why:** owner (2 Aug 2026) wanted packages to actually control features, not just display.
Offline billing + Desktop App joined the gates (offline_enabled, Business+): NEW agent pairing/downloads and NEW sale-screen offline queueing are blocked, but already-paired shops are GRANDFATHERED (existing agent_api_key keeps auth/heartbeat/downloads) and offline replay (syncOfflineBills) NEVER checks the gate — queued bills are never rejected. The baked offlineAllowed flag is part of the POS boot fingerprint so plan changes refresh cached sale screens.
**How to apply:** any NEW premium feature must (1) get a plan column via idempotent migration, (2) join PLAN_GATES, (3) gate EVERY entry point — page + JSON + write paths (storeInvoice deal lines rejected 422; rider_id silently dropped so offline replays never lose bills) + nav/buttons. planAllows fails OPEN if the column is missing (lagging PROD migrate). Same source order as restaurant: internal → override → plan → active trial.
