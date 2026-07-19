---
name: Live POS test company
description: Live POS test-company pattern — the temporary test company was DELETED 17 Jul 2026; how to safely create/delete one for live tests, plus the false "Pro-plan restaurant drift" alarm to not repeat.
---

# Live NestPOS test company pattern

## ACTIVE test company (18 Jul 2026, cleanup pending — follow-up task exists)
- company_id 31 "TaxNest QA Test Shop", login qa.mrf.test@taxnest.com.pk (standard dev test password), NTN 7654321, phone 03457654321, Pro plan (id 11), retail. PRA reporting OFF, pos_tax_inclusive ON. Test bills: pos_transactions 420 (L-001 cash) + 421 (L-002 card), products 214/215 auto-created. Delete via the admin bin flow below when owner confirms testing done.
- Registration trial-abuse guard rejects REUSED ntn/phone/email ("already been used to create an account") — deleted test companies' identifiers stay burned; always pick fresh ones. Burned so far: qa.mrf.test@… (NTN 7654321 / 03457654321), qa.excel.import@… (7822334 / 03467822334), qa.scale.test@… (7833445 / 03467833445).
- Welcome form gotcha: `pos_ui_density` must be simple|standard|premium (anything else = silent validation fail → redirect loop back to ?welcome=1; no error visible to curl).

- LIVE has NO standing POS test company — all live `product_type='pos'` companies are REAL customer restaurants (never test in those without explicit owner approval; PRA bills are real fiscal records). Temporary test companies used in Jul 2026 sessions were fully deleted afterward.
- Safe pattern for live testing: register via public `/pos/register`, admin-approve, keep PRA reporting OFF the whole time (only L-series local bills, nothing submitted to PRA), delete when done. Admin delete flow: POST `/admin/companies/{id}/delete` (bin), then POST `_method=DELETE` to `/admin/bin/{id}/destroy` (forceDelete now also purges non-FK tables — see company-hard-delete-purge.md).

## FALSE ALARM: "Pro-plan restaurant drift" (corrected 17 Jul 2026)
An earlier session concluded live `pricing_plans` Pro had `restaurant_enabled=0` (data drift) and granted the then-test-company a temporary override. **That conclusion was WRONG** — verified 17 Jul 2026: with the override removed and an active Pro Annual sub, the features page rendered `restaurantLocked: false` and `/pos/restaurant/tables` returned 200 → live Pro plan grants Restaurant with NO override. The reassert migrations DID stick. **Why the false alarm:** the lock banner's TEXT is ALWAYS in the rendered HTML (it's `x-show="restaurantLocked"` hidden markup) — a text-scan of the page "found" the lock message and concluded blocked. **How to apply:** when scraping live pages, read the bound Alpine state (e.g. `restaurantLocked: @json(...)` value) or check x-show conditions — never conclude from banner text presence alone.

## Handy live-test facts
- Super admin creds work on the ONE shared `/login` form (auto-detects admin → /admin/dashboard).
- Admin "Manage as Company" (mode=full) enters the company panel; guard follows the company's product_type — a DI company impersonation lands in `/dashboard`, NOT `/pos/*`.
- `pos/*` endpoints are CSRF-exempt → curl JSON POSTs to `/pos/invoice/store`, `/pos/restaurant/orders/hold|{id}/pay`, `/pos/api/provisional-bills/{id}/promote` work with just the session cookie.
- Fresh POS company flow: register → admin approve → first `/pos/dashboard` hit redirects to `/pos/features?welcome=1` until the welcome setup form is POSTed.
