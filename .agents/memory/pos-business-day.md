---
name: PRA POS Business Day (post-midnight sales)
description: business_date column rules — 00:00–05:59 pre-day-close sales count in the PREVIOUS day everywhere except PRA/tax reporting
---

# Business Day (owner request, 26 Jul 2026)

Late-night shops: sales after midnight (00:00–05:59) belong to the PREVIOUS calendar day's figures **until that day is closed** — dashboard "today", day-close/Z-report, sales reports + CSV, Akhri Bills strip, local-bills portal, archive filters, rider day views, quota add-back.

## The rule
- `pos_transactions.business_date` (DATE, indexed with company_id) is assigned at CREATE time by a `creating` hook via `PosBusinessDay::forMoment($companyId, $moment)`:
  - 06:00 or later → today.
  - 00:00–05:59 → **yesterday, UNLESS yesterday is already day-closed** (pos_day_closes row exists) → then today. Manual close and 6AM auto-close both flip the boundary immediately.
- PRA/FBR submission payloads, tax reports (`buildTaxReportQuery`), and `rider_settled_at` logic stay on REAL `created_at` — legal record never shifts.

## Traps (why these choices)
- **business_date has NO Eloquent cast** — reads are raw `'Y-m-d'` strings; string comparisons rely on this. NEVER `whereDate()` on business_date (kills the index and it's already a DATE) — compare with `=` / `between` on date strings.
- Draft/held-order guards intentionally stay on `created_at` (drafts are session-scoped, not day-figures).
- The creating hook is `hasColumn`-guarded, so a tar-deploy window before `migrate --force` is safe; the migration's idempotent backfill (`business_date = DATE(created_at)` WHERE NULL) covers rows created in that window — re-run the UPDATE after live deploys to be sure.
- Day-close wash + `AutoCloseDayPos` group by business_date (with hasColumn fallback); month quota add-back filters business_date within the month bounds (avoids month-boundary quota leak — architect catch).
- `restaurant_orders` has NO business_date (v1 limitation): restaurant analytics in RestaurantPosController still use created_at.

## How to apply
- ANY new POS surface that shows "a day's" bills/figures must filter on `business_date`, not `DATE(created_at)`.
- New write paths creating pos_transactions rows need no action (hook covers them) — but raw `DB::table(...)->insert` bypasses the hook; use the model or set business_date explicitly.
