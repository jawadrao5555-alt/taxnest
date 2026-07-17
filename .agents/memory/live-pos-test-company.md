---
name: Live POS test company
description: A dedicated NestPOS test company exists on LIVE (cPanel prod) for safe live billing tests — which one it is, what state it's in, and the false "Pro-plan restaurant drift" alarm to not repeat.
---

# Live NestPOS test company (created 17 Jul 2026)

- LIVE has NO seeded POS test company — all live `product_type='pos'` companies are REAL customer restaurants (never test in those without explicit owner approval; PRA bills are real fiscal records).
- So one was registered via public `/pos/register`: **"NestPOS Live Test Company" (company_id 29)** — find it in Admin > Companies; owner has the login (standard test-account pattern). Pro Annual sub, admin-approved, restaurant preset, universal sale screen ON.
- It has ONLY local (L-series) test bills — PRA reporting OFF, nothing submitted to PRA. Safe for repeat testing. Owner may delete it later (follow-up proposed).

## FALSE ALARM: "Pro-plan restaurant drift" (corrected 17 Jul 2026)
An earlier session concluded live `pricing_plans` Pro had `restaurant_enabled=0` (data drift) and granted company 29 a temporary override. **That conclusion was WRONG** — verified 17 Jul 2026: override removed, active Pro Annual sub, features page rendered `restaurantLocked: false`, `/pos/restaurant/tables` 200 → live Pro plan grants Restaurant with NO override. The reassert migrations DID stick (the Unlimited features copy unique to `2026_07_15_140000` is live — proof it ran). **Why the false alarm:** the lock banner's TEXT is ALWAYS in the rendered HTML (it's `x-show="restaurantLocked"` hidden markup) — a text-scan of the page "found" the lock message and concluded blocked. **How to apply:** when scraping live pages, read the bound Alpine state (e.g. `restaurantLocked: @json(...)` value) or check x-show conditions — never conclude from banner text presence alone. Company 29's override was removed 17 Jul 2026; it runs on plain plan access now.

## Handy live-test facts
- Super admin creds work on the ONE shared `/login` form (auto-detects admin → /admin/dashboard).
- Admin "Manage as Company" (mode=full) enters the company panel; guard follows the company's product_type — a DI company impersonation lands in `/dashboard`, NOT `/pos/*`.
- `pos/*` endpoints are CSRF-exempt → curl JSON POSTs to `/pos/invoice/store`, `/pos/restaurant/orders/hold|{id}/pay`, `/pos/api/provisional-bills/{id}/promote` work with just the session cookie.
- Fresh POS company flow: register → admin approve → first `/pos/dashboard` hit redirects to `/pos/features?welcome=1` until the welcome setup form is POSTed.
