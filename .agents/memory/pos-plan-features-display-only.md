---
name: pricing_plans.features is display-only
description: What actually gates plan features vs what the features JSON does; how plan cards must render
---

# pricing_plans.features JSON is DISPLAY-ONLY

**Rule:** No PHP code consumes `pricing_plans.features` programmatically — it is pure marketing copy rendered on plan cards. Actual enforcement lives elsewhere:
- Restaurant gate = `pricing_plans.restaurant_enabled` COLUMN (PosFeatureService, ~line 311).
- Numeric limits = `invoice_limit` / `user_limit` / `branch_limit` columns via PlanLimitService.
- Everything else (deals, offline sync, guided billing, etc.) is NOT plan-gated — listing them under higher tiers is deliberate upsell positioning, not enforcement.

**Why:** replit.md once claimed the restaurant gate read the features JSON — editing that JSON looked like a functional regression risk when it is actually safe. Verified Jul 2026: grep shows features JSON only appears in 6 views.

**How to apply:**
- Editing feature lists (marketing copy) = safe, data-only idempotent migration matching `product_type` + plan `name` (prod runs `migrate --force`, never seeds). Name-match means a renamed plan silently keeps old copy.
- Feature JSON must NOT duplicate limit columns — POS plan cards (pos/landing, pos/billing) render limits from columns via `get*LimitDisplay()` and print cumulative "Everything in <prev>, plus:" (`$plans[$loop->index - 1]`, price-ordered collection) above tiers 2+.
- Never re-add hardcoded plan-name feature branches in views (old 'Retail'/'Industrial'/'Enterprise' checks rotted silently when plans were renamed).
