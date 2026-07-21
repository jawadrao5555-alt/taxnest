---
name: POS What's New updates system
description: Rules for the PRA POS "What's New" popup + bell notifications and the after-deploy announcement step
---

# POS What's New (popup + bell)

- Tables: `app_updates` (title, `points` JSON array, `audience` default 'pos', is_published) + `app_update_seens` (unique app_update_id+user_id). Models `AppUpdate` / `AppUpdateSeen` (relation is `appUpdate()` — naming it `update()` fatals against `Model::update()`).
- Master switch: SystemSetting `pos_whats_new_enabled` ('1' default ON). Admin UI: `/admin/app-updates` (create/edit/publish/delete + on/off).
- **Image support (Jul 2026):** optional `image_path` column — admin upload (public disk `app-updates/`, 3MB jpg/png/webp, `->extension()` not client extension) with remove-image checkbox; popup + bell render via `asset('storage/'.path)` behind `@if` guards + click-to-zoom. Popup body max-height is INLINE style (62vh with image / 18rem without) — arbitrary Tailwind classes would need a Vite rebuild. When creating rows by script on live, upload the image file to `storage/app/public/app-updates/` too (storage symlink already exists).
- **Popup shows ALL unseen rows stacked (newest first) in one scrollable body** (owner, 21 Jul 2026 — was latest-only before). Header goes plural ("N Naye Updates Aye Hain!") and each update gets its own title+date row when count > 1. Body max-height = INLINE `62vh` (arbitrary Tailwind needs Vite rebuild). Creation order still matters for the bell + top-of-popup position: create the guide row FIRST and the main announcement LAST so the announcement sits on top.
- POS side lives entirely in `pos-app.blade.php` layout @php (hasTable + try/catch guarded, prod-drift safe). Dismissing (or opening the bell) marks ALL published pos updates seen via POST `/pos/whats-new/seen`.
- **Confined roles (`pos_kitchen`/`pos_waiter`/`pos_rider`) and pending companies MUST be skipped in the layout** — PosAuth path whitelists / approval middleware block the seen POST, so showing them the popup = infinite dismiss loop. Any future notification surface in this layout needs the same skip.
- **Why:** architect caught the dismiss loop after the initial build; kitchen displays are hands-off screens.
- **How to apply (deploy runbook):** owner wants system corrections auto-announced — after each POS deploy, create a published `AppUpdate` row (audience 'pos', Roman Urdu bullet points) so users get the popup + bell entry. Editing an update does NOT reset seen rows (already-dismissed users won't re-see it); create a NEW update for that.
- **Audience gating (owner rule, 20 Jul 2026):** What's New popup + bell AND the Suggestion bulb/box are ADMIN/MANAGER-ONLY — gate = `isPosAdmin()` (pos_admin/pos_manager/company_admin). Cashiers + confined roles (kitchen/waiter/rider/viewers) never see them: nav elements hidden in `pos-app.blade.php` AND `/pos/suggestions` (index+store) server-side redirects non-admins to the POS dashboard. Both layers required — nav-hide alone leaves the direct URL open.
