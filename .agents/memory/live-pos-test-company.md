---
name: Live POS test company
description: Live POS test-company pattern — STANDING test company id 35 (RETAINED for QA until owner asks to clean it up); safe create/delete flow, burned identifiers, and the false "Pro-plan restaurant drift" alarm to not repeat.
---

# Live NestPOS test company pattern

## STANDING test company: id 35 "QA Full Audit Restaurant" (created 19 Jul 2026, RETAINED — currently the live QA company; delete only when the owner asks)
- Pro plan (live plan id 11), PRA reporting OFF whole life (only L-series local bills — nothing ever went to PRA). Owner-approved full-audit company.
- Logins: admin qa.fullaudit@taxnest.com.pk; team qa.mgr/qa.cash/qa.kit/qa.wtr .audit35@taxnest.com.pk; local viewer qa.lview.audit35@taxnest.com.pk (passwords NOT stored here; 22 Jul 2026: admin password reset to the SAME standard password as the dev posadmin account — see replit.md dev creds. Reset method: tiny bootstrap-Laravel PHP script over SSH updating the bcrypt hash — tinker is disabled on live too). Identifiers NTN 7899001 / 03427899001 will BURN on delete.
- Artifacts to purge with it: EVERYTHING under company_id 35 — products, deals, riders, customers, ALL L-series bills, day-close reports, day openings (`pos_day_openings`), held orders. Keep this list COARSE; exact row ids drift between QA sessions (e.g. day-close #6 was deleted and re-created as #8 on 20 Jul 2026).
- POS login POST field is `login` (not `email`) — posting `email` silently bounces back to /pos/login.

## Earlier deletions (companies 29, 31 & 34 hard-deleted via bin flow — CLEANUP COMPLETE)
- Company 29 (the 17 Jul 2026 temporary POS test company) was fully hard-deleted with its test artifacts. Verified on live DB 20 Jul 2026: no `companies` row for 29 (not even soft-deleted), 0 `pos_transactions`, 0 `users` with company_id 29. Nothing left to clean.
- Registration trial-abuse guard rejects REUSED ntn/phone/email ("already been used to create an account") — deleted test companies' identifiers stay burned; always pick fresh ones. Burned so far: qa.mrf.test@… (NTN 7654321 / 03457654321), qa.excel.import@… (7822334 / 03467822334), qa.scale.test@… (7833445 / 03467833445), qa.bulkhide.test@… (7866778 / 03497866778) + qa.bulkhide.cashier@….
- Register POST needs `pricing_plan_id` of a REAL non-trial POS plan (live ids: 9 Starter, 10 Business, …) — Starter's team quota is admin-only, so pick Business+ when the test needs a cashier login.
- `storeProduct` treats `show_on_sale` as a CHECKBOX (`$request->has()`): a curl POST without the field creates the product HIDDEN from the sale screen — not a bug; include `show_on_sale=1` when scripting product creation.
- Welcome form gotcha: `pos_ui_density` must be simple|standard|premium (anything else = silent validation fail → redirect loop back to ?welcome=1; no error visible to curl).

- Apart from company 35 above, ALL other live `product_type='pos'` companies are REAL customer restaurants (never test in those without explicit owner approval; PRA bills are real fiscal records). Earlier temporary test companies (31, 34) were fully deleted after use.
- Safe pattern for live testing: register via public `/pos/register`, admin-approve, keep PRA reporting OFF the whole time (only L-series local bills, nothing submitted to PRA), delete when done. Admin delete flow: POST `/admin/companies/{id}/delete` (bin), then POST `_method=DELETE` to `/admin/bin/{id}/destroy` (forceDelete now also purges non-FK tables — see company-hard-delete-purge.md).

## FALSE ALARM: "Pro-plan restaurant drift" (corrected 17 Jul 2026)
An earlier session concluded live `pricing_plans` Pro had `restaurant_enabled=0` (data drift) and granted the then-test-company a temporary override. **That conclusion was WRONG** — verified 17 Jul 2026: with the override removed and an active Pro Annual sub, the features page rendered `restaurantLocked: false` and `/pos/restaurant/tables` returned 200 → live Pro plan grants Restaurant with NO override. The reassert migrations DID stick. **Why the false alarm:** the lock banner's TEXT is ALWAYS in the rendered HTML (it's `x-show="restaurantLocked"` hidden markup) — a text-scan of the page "found" the lock message and concluded blocked. **How to apply:** when scraping live pages, read the bound Alpine state (e.g. `restaurantLocked: @json(...)` value) or check x-show conditions — never conclude from banner text presence alone.

## Handy live-test facts
- Super admin creds work on the ONE shared `/login` form (auto-detects admin → /admin/dashboard).
- Admin "Manage as Company" (mode=full) enters the company panel; guard follows the company's product_type — a DI company impersonation lands in `/dashboard`, NOT `/pos/*`.
- `pos/*` endpoints are CSRF-exempt → curl JSON POSTs to `/pos/invoice/store`, `/pos/restaurant/orders/hold|{id}/pay`, `/pos/api/provisional-bills/{id}/promote` work with just the session cookie.
- Fresh POS company flow: register → admin approve → first `/pos/dashboard` hit redirects to `/pos/features?welcome=1` until the welcome setup form is POSTed.
