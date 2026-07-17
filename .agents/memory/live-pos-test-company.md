---
name: Live POS test company
description: A dedicated NestPOS test company exists on LIVE (cPanel prod) for safe live billing tests — which one it is, what state it's in, and the live Pro-plan restaurant drift found while creating it.
---

# Live NestPOS test company (created 17 Jul 2026)

- LIVE has NO seeded POS test company — all live `product_type='pos'` companies are REAL customer restaurants (never test in those without explicit owner approval; PRA bills are real fiscal records).
- So one was registered via public `/pos/register`: **"NestPOS Live Test Company" (company_id 29)** — find it in Admin > Companies; owner has the login (standard test-account pattern). Pro Annual sub, admin-approved, restaurant preset, universal sale screen ON.
- It has ONLY local (L-series) test bills — PRA reporting OFF, nothing submitted to PRA. Safe for repeat testing. Owner may delete it later (follow-up proposed).

## Live data drift found (important)
Live `pricing_plans` Pro (id 11, pos) had `restaurant_enabled=0` even though the Jul 2026 reassert migrations set it to 1 — a Pro company was blocked from `/pos/restaurant/*`. **Why:** classic prod data drift (see `prod-schema-drift-selfheal.md`); migrations marked Ran but the data assert didn't stick on live. **How to apply:** don't trust live plan feature columns match dev; fix via a fresh idempotent migration, and for testing use a temporary admin override (company 29 has one until 31 Jul 2026 — that's why restaurant works there).

## Handy live-test facts
- Super admin creds work on the ONE shared `/login` form (auto-detects admin → /admin/dashboard).
- Admin "Manage as Company" (mode=full) enters the company panel; guard follows the company's product_type — a DI company impersonation lands in `/dashboard`, NOT `/pos/*`.
- `pos/*` endpoints are CSRF-exempt → curl JSON POSTs to `/pos/invoice/store`, `/pos/restaurant/orders/hold|{id}/pay`, `/pos/api/provisional-bills/{id}/promote` work with just the session cookie.
- Fresh POS company flow: register → admin approve → first `/pos/dashboard` hit redirects to `/pos/features?welcome=1` until the welcome setup form is POSTed.
