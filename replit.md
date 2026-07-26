# TaxNest - Heavy Enterprise Product

## Overview
Multi-company SaaS for tax + invoice management in Pakistan (FBR/PRA compliant): smart invoicing (DI), two POS products (PRA POS "NestPOS" + FBR POS), SaaS admin layer, PDF generation, enterprise analytics, immutable audit logs. Laravel 12 / PHP 8.4, Breeze auth, Tailwind + Alpine.js + Chart.js. MySQL in production (owner's cPanel), MySQL staging in Replit dev.

Deep module invariants live in `.agents/memory/` topic files — this file is the short map. ALWAYS read the matching memory topic before editing a subsystem.

## User Preferences & Business Rules
- **CURRENT FOCUS (owner, 18 Jul 2026): work ONLY on NestPOS PRA (PRA POS) for now** — DI, FBR POS, admin/SaaS surfaces sirf tab touch karo jab owner kahe.
- ZIA CORPORATION is a REAL production account (not demo) — NTN 3620291786117, Digital Invoice ONLY (no POS data).
- NestPOS Enterprise Store (company_id 11) = dedicated POS test company (posadmin@taxnest.com / Admin@12345). Test Trading Company (company_id 12, test@testtrading.pk / Admin@12345) = admin-approval-workflow testing.
- DI and POS data are FULLY ISOLATED — no cross-contamination, ever.
- **UI language rule (owner, 20 Jul 2026)**: admin panel UI text = ENGLISH only; customer-facing POS surfaces may use Roman Urdu; customer-submitted content shown as-is.
- Billing: POS = ANNUAL-ONLY (6% discount baked in, no cycle toggle). DI = full toggle: Monthly / Quarterly(-1%) / Semi-Annual(-3%) / Annual(-6%).
- Auth: guards isolated per panel (DI=`web`, PRA POS=`pos`, FBR POS=`fbrpos`); no cross-login; only ADMIN creds auto-detect on any form; identifiers = Email/Phone/Username/CNIC/NTN; pending companies view-only until approved. Full rules + login-page styling → memory `auth-guards-login.md`.
- PRA and Local POS receipts each have their OWN full display set on /pos/receipt-settings (PRA = prefs['pos'] + pos_receipt_show_tax; Local = prefs['pos_local']). "Show Tax" OFF hides Subtotal + Tax (incl. PRA fiscal) — grand TOTAL only, line items keep ORIGINAL ex-tax prices, never grossed-up; tax ALWAYS submitted to PRA in full. Do NOT re-add a fiscal-always-show override or the gross-up. Full rules → memory `pos-provisional-and-receipt-rules.md`.

## Design Rules (owner, Jul 2026)
- NO BLUE on POS dashboard cards/tiles/banners — teal brand family (teal-600/800, #0A4D5C). Theme engine only remaps purple-X, so blue never follows themes. Icon-box gradients exempt.
- Cards/banners CLEAN & SOLID — no corner blobs, white glows, or top washes (check CSS `<style>` rgba too). EXEMPT: modal backdrops, login/landing/guest backgrounds, hover underline bars, icon-box gradients.
- Buttons & pills CLEAN & SOLID — no colored glow shadows (Tailwind `shadow-<color>-N/NN` or CSS rgba box-shadow); neutral `shadow-sm` or none; keep focus rings / selected-states. Prefer solid `bg-<color>-600` over 2-hue gradients. → memory `pos-button-glow.md`
- Theme engine: `pos-app.blade.php` "UNIVERSAL THEME OVERRIDE" remaps ~50 purple-X utilities to `hsl(var(--accent-*))` when `body[data-theme] != purple`. New theme = define `--accent-h/-s/-l` + `--nav-bg` only. NEVER replicate overrides per-view. (FBR port remaps blue-X.)

## Core Platform
- Multi-tenancy via `company_id` + `CompanyIsolation`; RBAC via `RoleMiddleware`; company approval workflow; multi-branch; customer ledger; immutable audit logs (SHA256); database queue; `ForceHttps` + subscription access control; admin announcements; dual invoice numbering (internal + regulatory).
- SaaS layer: separate admin + franchise panels, plan builders, usage monitoring. `pricing_plans.product_type` = `di`/`pos`/`fbrpos`/`standalone`. Override grants = Lifetime + Temporary ONLY (grace + standalone usage_free RETIRED — legacy rows honored; do NOT re-add buttons).

## Digital Invoice (DI)
- FBR compliance: `ScheduleEngine` validation, PRAL API, per-item FBR fields, sandbox pre-validation, submission idempotency, token health monitor. Simplified 4-state lifecycle; PDFs default pure B&W.
- Global HS Intelligence: `global_hs_master`, HS resolution + tax-schedule validation, admin-managed mapping engine with real-time suggestions.

## PRA POS (NestPOS)
Isolated POS (own auth/layouts/models); PRA integration with offline billing + auto-sync (transport failures queue 'offline' and auto-retry, never 'failed'); Restaurant module; unified top-nav (nav edits in `pos-app.blade.php`).
- **After each POS deploy**: create an AppUpdate row (What's New popup + bell, admin-managed /admin/app-updates) AND update Madadgar KB `resources/madadgar/knowledge-pos.md` (Roman Urdu) in the same deploy → memory `pos-whats-new-updates.md`.
- Feature Suggestion box + "Zyada Demand" grouping + owner's "3 customer rule" → memory `pos-feature-suggestions.md`. What's New + Suggestions = ADMIN/MANAGER-ONLY (owner, 20 Jul 2026).
- Desktop Sync Agent (Electron) polls server, submits to PRA; v1.3.0+ self-update via GitHub Releases → memory `agent-release-distribution.md`. v1.4.0 "NestPOS Desktop" opt-in POS window, exe name FROZEN "TaxNest PRA Agent.exe" → memory `pos-desktop-shell.md`.
- Madadgar AI support bot: floating bubble ALL POS pages/roles; escalation only via "Haan" confirm → memory `pos-madadgar-bot.md`.
- Every POS page needs an in-page back affordance (customer rule, Jul 2026): own "Back to X" link or `@include('pos.partials.back-link')` at top of container. Inventory nav always visible to non-cashiers (grey OFF badge; disabled pages redirect to POS Features, not 403). Anonymous Blade components via `Blade::anonymousComponentPath(resource_path('views'))`.
- **Dashboard styles**: per-company `companies.pos_dashboard_style` — 7 styles incl. owner-approved `saaf` (also skins the sale screen). Global compaction rules, customer-box-first rule, global search rule, discount-limit rule, style picker ONLY at /pos/customize#style, new-style checklist → memory `pos-dashboard-styles.md` + `pos-user-grid-prefs.md`.

### Invariants (detail in memory topics)
- **Reporting-OFF Finals (PRA & FBR)**: reporting OFF = regulator mode + NULL status (`'pra'`+NULL / `'fbr'`+NULL) — NEVER `'local'`. Three-branch on EVERY write path; FBR-off also skips the Rs 1 fee. → `pos-provisional-and-receipt-rules.md`
- **PRA serial split**: `POS-YYYY-NNNNN` fiscal serials ONLY for bills actually reported to PRA; provisionals AND reporting-OFF finals draw L-series. Generators order by NUMERIC serial via DbCompat + bypass `hide_archived`. → `pos-local-bills-dayclose.md`
- **Per-cashier PRA Reporting toggle**: `users.pra_reporting_enabled` (NULL = inherit company). Reads via `User::praReportingEnabled($company)` / `Company::praReportingActive()`. → `pos-local-bills-dayclose.md`
- **Tax rates**: global `pos_tax_rules` (cash 16%, card/digital 8%) + per-company overrides; ALL reads via `PosTaxRule::getRateForMethod()`/`effectiveRules()`; receipts/payloads read the stored `tax_rate` snapshot; report rate-filters DYNAMIC, never hardcode.
- **Tax Pricing = THREE modes** (`pos_tax_pricing_mode` via `Company::posTaxPricingMode()`): `exclusive` / `inclusive` / `inclusive_card_save`. Bills snapshot `tax_inclusive` + `tax_menu_rate` — edits/promote/payload follow the SNAPSHOT. All math via `PosTaxMath`. → `pos-tax-inclusive-pricing.md`
- **Rounding**: PRA POS bill total = whole rupee on ALL write paths; lines stay 2dp. (FBR POS & DI keep decimals.) → `pos-rounding-convention.md`
- **PRA Connection Mode**: 'cloud' | 'fiscal_device' (new PRA IDs hit Code 112 on cloud — need local IMS via Desktop Agent; server never direct-submits in fiscal_device mode). → `pra-code-112-fiscal-device.md`
- **Packages (annual-only)**: Starter 9,999 (1 acct / 500 bills/mo), Business 14,999 (5 / 2,000), Pro 24,999 (10 / 3,000, 2 branches, restaurant), Unlimited 39,999. Enforced via `PlanLimitService`; provisionals FREE until promoted. → `pos-package-limits.md`
- **Roles**: `pos_manager` = admin-equivalent (counts vs `user_limit`); `pos_kitchen`/`pos_waiter`/`pos_delivery`/`pos_rider` limit-EXEMPT + confined.
- **Standalone POS Edition RETIRED**: all companies forced `pos_integration_mode='pra'`; do NOT re-add standalone surfaces.

### Sale screen, local billing, restaurant (memory topics = source of truth)
- **Sale screen** = `pos/universal.blade.php` — the ONLY live sale screen (legacy screens dead). Two-row action bar, 24 Jul 2026 redesign (nav utility pills, Akhri Bills strip, one-tap CASH/CARD), manual-cart type mapping, receipt popup auto-close, Guided Keyboard Flow, F10/F11 modals, boot splash. → `pos-universal-screen-features.md`, `pos-sale-screen-product-loaders.md`, `pos-guided-keyboard-flow.md`, `pos-plain-letter-shortcuts.md`
- **Opening Cash**: day-start drawer entry, today-only, locks once day closed; both close paths auto-fill `opening_float`. → `pos-opening-cash.md`
- **Day-close** (manual + 6AM auto): Z-report analytics, cash recon, A4 PDF + 80mm thermal; washes that day's local bills per standing policy (non-NULL pra_status / fiscal number NEVER touched; deleted finals still consume quota); Local Bills Portal + promote rules. → `pos-local-bills-dayclose.md`
- **Delivery Riders**: rider logins + khata + settlement board + day-close recon; assignment BOARD-ONLY post-payment. → `pos-delivery-riders.md`
- **Restaurant module**: Pro/Unlimited gating via `PosFeatureService` (`restaurant_enabled` COLUMN, never plan IDs); tables/KOT stations/KDS/waiters/deals/QR menu; order-type flow rules; KOT Full Mode; Table Shift (empty-target-only, timer carries, no KOT reprint). → `pos-restaurant-module.md`, `silent-printer-routing.md`, `pos-deals-feature.md`
- **Staff Hazri** (26 Jul 2026): `pos_user_sessions` attendance — admin/manager-only /pos/reports/hazri + day-close Z-report section; login listener MUST stay pos-guard-gated. → `pos-staff-hazri.md`

## FBR POS
- Isolated FBR-integrated POS (`fbrpos` guard): FBR IMS submission, provisional + payment-confirm flow, PIN system, Edit & Retry. → `fbr-pos-ims-fiscalization.md`
- **Universal sale screen (opt-in port of PRA screen, keep diffable; FROZEN pre-redesign)**: per-company `fbr_universal_enabled`; SHIM `@php` pins — do NOT delete. → `fbr-universal-sale-screen.md`

## UI / PWA
- Responsive sidebar layout, dark/light modes, emerald-600 primary; DI dashboard premium gradient banners + KPI cards.
- Three PWAs sharing ONE `/sw.js` (bump CACHE_VERSION on relevant deploys); update toast + `window.tnPwaUpdateHold` defers reload mid-sale; offline pre-caching; push notifications. → `sw-skiplist-sale-screens.md`
- Global mobile polish (iOS tuning, safe-area, touch targets). All A4 PDFs use `15mm 15mm 18mm 15mm` margins.
- Live static-asset caching: `.htaccess` caches css/js 30d — ANY loose public asset edit MUST bump its `?v=` in blade refs. → `static-asset-caching.md`

## External Dependencies
- **MySQL** — production DB (owner's cPanel); PostgreSQL exists in Replit env but dev uses MySQL staging.
- **FBR (Pakistan)** — DI + FBR POS tax compliance APIs. **PRA / PRAL IMS API v1.2** — POS fiscal integration (cloud + local fiscal-device).
- **Laravel Breeze**, **Tailwind CSS**, **Alpine.js**, **Chart.js**. **Unsplash / Picsum** — `ProductImageService` fallback.
- **cPanel SMTP (noreply@taxnest.com.pk)** — ALL outgoing email; admin SMTP override + MailHealth banner. → memory `mail-noreply-smtp.md`
