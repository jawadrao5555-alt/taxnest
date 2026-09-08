# 18 — Settings

## Layers (FACT)

1. **`system_settings`** — SaaS-wide KV (SMTP override storage, payment bank, central WhatsApp, APK versions, heartbeats, feature flags)
2. **`companies` columns + JSON** — product toggles, fiscal credentials, receipt prefs, dashboard style, printer settings
3. **`branches` / `users` columns** — branch overrides, per-user PRA reporting, device uid, custom access
4. **`config/*.php` + `.env`** — `features.php`, `health.php`, `live_ops.php`, `print.php`, `mail.php`, …
5. **Plan/feature services** — runtime gates (`PosFeatureService`, …)

## Safety tooling

**FACT:** `PosSettingsSnapshot` + artisan `pos:settings-snapshot` — deny-list of volatile columns so deploys/tests do not silently reset shop prefs.

**FACT:** Partial JSON writers for `invoice_display_prefs`, `pos_printer_settings`,
`feature_flags` (single-flag toggles) and `public_profile_settings` go through
`Company::mergeJsonColumn()` — `lockForUpdate` + re-read, so two concurrent
requests that each own a different key of the same column cannot clobber each
other. Full-form feature-flag posts that rebuild the complete map still write
via the helper.

**Owner rule (`replit.md`):** generic fixes must not silently overwrite company settings/preferences; only fill missing defaults.

## Admin UI

`AdminSettingsController` — SMTP, WhatsApp, payment/support settings.

Company DI WhatsApp — `CompanySettingsController`.

## Receipt display

NestPOS: separate PRA vs Local preference sets on `/pos/receipt-settings` (`replit.md` invariants). Printed tickets ENGLISH only.
