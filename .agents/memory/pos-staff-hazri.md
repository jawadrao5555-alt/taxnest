---
name: POS Staff Hazri (attendance)
description: pos_user_sessions attendance tracking — login listener guard rules, heartbeat throttle, business-day report, day-close inclusion
---

# Staff Hazri (PRA POS attendance, 26 Jul 2026)

- Table `pos_user_sessions` (company_id, user_id, login_at, logout_at, last_activity_at, ip). Model `PosUserSession`. Migration idempotent (hasTable guard).
- **Row creation**: Login event listener in AppServiceProvider — MUST stay gated on `$event->guard === 'pos'` + instanceof POS User + company_id present, and SKIP when an admin_users session is active (impersonation would stamp fake attendance). try/catch wrapped so web/fbrpos/admin/franchise logins can never break even if the table is missing on prod.
- **Logout**: PosAuthController::logout stamps `logout_at` on ALL open rows (not just latest) — belt-and-braces for orphan rows.
- **Heartbeat**: PosAuth middleware updates `last_activity_at` max once per 5 min per user (cache key `pos_hazri_beat_{id}`), single-row UPDATE (orderByDesc limit 1), try/catch — never blocks a request. Staff who never press logout (browser close / power cut) show last_activity_at with a `*` marker on the report.
- **Report**: GET /pos/reports/hazri — ADMIN/MANAGER ONLY (`isPosAdmin()` gate, cashier/waiter 403; teal button on /pos/reports also admin-gated). Window = business day 6AM→6AM (same convention as day-close), date picker for past days. Rows = sessions grouped per user (First In / Last Out / login count) + bill count and first/last sale via `withoutGlobalScope('hide_archived')`. ON DUTY badge = open session with recent activity.
- **Day-close**: `buildHazriRows()` output passed into BOTH day-close A4 PDF and 80mm thermal ("STAFF ATTENDANCE (HAZRI)" section). It returns `[]` on ANY error (missing table = prod-drift safe) and blades guard `@if(!empty($hazri))` — other render paths without the variable stay safe.
- **Why**: owner wants shop-staff attendance visible without separate HR tooling; report tied to business day so night shifts count with their sales day.
- Gotcha from e2e: dev-seeded cashier had role `company_admin` (wrong); prod convention for cashiers = role `'employee'` — 403 test needs a real employee-role user.
