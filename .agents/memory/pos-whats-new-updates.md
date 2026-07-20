---
name: POS What's New updates system
description: Rules for the PRA POS "What's New" popup + bell notifications and the after-deploy announcement step
---

# POS What's New (popup + bell)

- Tables: `app_updates` (title, `points` JSON array, `audience` default 'pos', is_published) + `app_update_seens` (unique app_update_id+user_id). Models `AppUpdate` / `AppUpdateSeen` (relation is `appUpdate()` — naming it `update()` fatals against `Model::update()`).
- Master switch: SystemSetting `pos_whats_new_enabled` ('1' default ON). Admin UI: `/admin/app-updates` (create/edit/publish/delete + on/off).
- POS side lives entirely in `pos-app.blade.php` layout @php (hasTable + try/catch guarded, prod-drift safe). Popup shows ONLY the newest unseen update; dismissing (or opening the bell) marks ALL published pos updates seen via POST `/pos/whats-new/seen`.
- **Confined roles (`pos_kitchen`/`pos_waiter`/`pos_rider`) and pending companies MUST be skipped in the layout** — PosAuth path whitelists / approval middleware block the seen POST, so showing them the popup = infinite dismiss loop. Any future notification surface in this layout needs the same skip.
- **Why:** architect caught the dismiss loop after the initial build; kitchen displays are hands-off screens.
- **How to apply (deploy runbook):** owner wants system corrections auto-announced — after each POS deploy, create a published `AppUpdate` row (audience 'pos', Roman Urdu bullet points) so users get the popup + bell entry. Editing an update does NOT reset seen rows (already-dismissed users won't re-see it); create a NEW update for that.
