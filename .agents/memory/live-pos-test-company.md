---
name: Live POS test company
description: Live POS test-company pattern — the temporary test company was DELETED 17 Jul 2026; how to safely create/delete one for live tests, plus the false "Pro-plan restaurant drift" alarm to not repeat.
---

# Live NestPOS test company (created + DELETED 17 Jul 2026)

- LIVE has NO POS test company — all live `product_type='pos'` companies are REAL customer restaurants (never test in those without explicit owner approval; PRA bills are real fiscal records).
- A temporary one ("NestPOS Live Test Company", company_id 29) was registered via public `/pos/register` for live testing, kept PRA reporting OFF (only L-series local bills, nothing submitted to PRA), and was **permanently deleted on owner instruction 17 Jul 2026** (Admin soft-delete → bin → force-delete; verified gone from companies list and bin).
- To repeat live testing later: same pattern — register via `/pos/register`, admin-approve, keep PRA reporting OFF, delete when done. Admin delete flow: POST `/admin/companies/{id}/delete` (bin), then POST `_method=DELETE` to `/admin/bin/{id}/destroy`.

## FALSE ALARM: "Pro-plan restaurant drift" (corrected 17 Jul 2026)
An earlier session concluded live `pricing_plans` Pro had `restaurant_enabled=0` (data drift) and granted company 29 a temporary override. **That conclusion was WRONG** — verified 17 Jul 2026: override removed, active Pro Annual sub, features page rendered `restaurantLocked: false`, `/pos/restaurant/tables` 200 → live Pro plan grants Restaurant with NO override. The reassert migrations DID stick (the Unlimited features copy unique to `2026_07_15_140000` is live — proof it ran). **Why the false alarm:** the lock banner's TEXT is ALWAYS in the rendered HTML (it's `x-show="restaurantLocked"` hidden markup) — a text-scan of the page "found" the lock message and concluded blocked. **How to apply:** when scraping live pages, read the bound Alpine state (e.g. `restaurantLocked: @json(...)` value) or check x-show conditions — never conclude from banner text presence alone. Company 29's override was removed 17 Jul 2026; it runs on plain plan access now.

## Handy live-test facts
- Super admin creds work on the ONE shared `/login` form (auto-detects admin → /admin/dashboard).
- Admin "Manage as Company" (mode=full) enters the company panel; guard follows the company's product_type — a DI company impersonation lands in `/dashboard`, NOT `/pos/*`.
- `pos/*` endpoints are CSRF-exempt → curl JSON POSTs to `/pos/invoice/store`, `/pos/restaurant/orders/hold|{id}/pay`, `/pos/api/provisional-bills/{id}/promote` work with just the session cookie.
- Fresh POS company flow: register → admin approve → first `/pos/dashboard` hit redirects to `/pos/features?welcome=1` until the welcome setup form is POSTed.
