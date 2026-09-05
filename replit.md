# TaxNest - Heavy Enterprise Product

## Overview
Multi-company SaaS for tax + invoice management in Pakistan (FBR/PRA compliant): smart invoicing (DI), two POS products (PRA POS "NestPOS" + FBR POS), SaaS admin layer, PDF generation, enterprise analytics, immutable audit logs. Laravel 12 / PHP 8.4, Breeze auth, Tailwind + Alpine.js + Chart.js. Production runs on the Nayatel Islamabad VPS with MariaDB; Replit dev uses MySQL staging.

Deep module invariants live in `.agents/memory/` topic files — this file is the short map. ALWAYS read the matching memory topic before editing a subsystem.

## User Preferences & Business Rules
- **Issue-log workflow (owner, 28 Jul 2026)**: jab owner issues bataye, sirf NOTE karo (`.local/issue-log.md` mein) — build/fix mat karo. "Summary do" kahe to logged issues ki summary do; "kaam karo" kahe tab hi implement karo.
- **Generic fix + strict feature-update isolation (owner, confirmed 2 Sep 2026)**: kisi feature/bug ka root-cause fix us maslay ki had tak HAR applicable company ko code/logic level par mile — company-specific patch nahi. Magar sirf usi feature ka required behavior/data badlay; existing company settings, per-branch values, staff permissions, feature toggles, saved preferences aur legacy data kabhi silently overwrite/reset na hon. Nayi value sirf missing/unset state ko safe default de; pehle se chosen value preserve ho. Har change se pehle relevant before-state capture, OFF/ON + old-data regression tests, targeted/idempotent migration checks, aur live post-deploy verification lazmi hai.
- **CURRENT FOCUS (owner, 18 Jul 2026): work ONLY on NestPOS PRA (PRA POS) for now** — DI, FBR POS, admin/SaaS surfaces sirf tab touch karo jab owner kahe.
- ZIA CORPORATION is a REAL production account (not demo) — NTN 3620291786117, Digital Invoice ONLY (no POS data).
- NestPOS Enterprise Store (company_id 11) = dedicated POS test company; Test Trading Company (company_id 12) = admin-approval-workflow testing. All dev/QA logins live in untracked `.local/qa-creds.env` — repo is PUBLIC, never write passwords into tracked files.
- DI and POS data are FULLY ISOLATED — no cross-contamination, ever.
- **UI language rule (owner, 20 Jul 2026)**: admin panel UI text = ENGLISH only; customer-facing POS surfaces may use Roman Urdu; customer-submitted content shown as-is. **UPDATE (28 Jul 2026): printed receipts/bills = ENGLISH only — no Roman Urdu lines on any printed ticket.**
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
- Tax Consultant Console (`/consultant`, DI web guard only): consent-based client linking (invite code or NTN request + client approval), health dashboard, audited login-swap switch into clients, referral codes (`?ref=` at signup) + commission ledger from admin-recorded payments; admin oversight at `/admin/consultants` → memory `consultant-console.md`.
- Bulk import: .xlsx template (TEXT code columns) + legacy CSV; shared row-level pre-validation (`InvoiceImportService`), .xlsx error report, queued background processing with polled progress; valid rows become drafts only.
- Buyer send (Aug 2026, all plans): "Email karein" (sync `InvoiceShareMail` — B/W PDF attach + share link via `InvoicePdfService`, MailHealth-tracked) + "WhatsApp karein" (`PkPhone` → wa.me deep link) on invoice show/index + FBR-success modal; every send logged in `invoice_deliveries` (Delivery History card); missing contact captured inline → saved to `CustomerProfile`. Buyer-facing content ENGLISH only.
- White-label branding (Premium, gate `white_label`): `/company/branding` (company_admin) — logo/accent/footer lines/hide-credit stored in `companies.di_branding`, resolved fail-closed at render by `DiBrandingService`, applied to all 4 PDF templates + public share page + `emails/invoice-delivery.blade.php` (branded view; `InvoiceShareMail` still uses its own template — wiring is a proposed follow-up). FBR QR/invoice number/tax breakdown always render; non-premium output unchanged.
- AI Invoice Reader (Premium gate `ai_reader`): upload PDF/photo/xlsx/csv at /invoices/ai-reader → gpt-4o-mini extracts buyer+items → HS mapped (document → company products → flag) → prefilled DRAFT review on create form (never auto-submits). Monthly quota counts SUCCESS parses only → memory `ai-invoice-reader.md`.
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
- **Universal sale screen = the ONLY FBR sale screen (classic create screen RETIRED 7 Aug 2026)**: `/fbr-pos/create` always renders `fbr-pos/universal.blade.php` (24 Jul redesign ported); `fbr_universal_enabled` no longer consulted; `fbr-pos/create.blade.php` = DEAD file on disk; SHIM `@php` pins — do NOT delete. → `fbr-universal-sale-screen.md`

## Nest ERPS (`erps` product line — first vertical: Healthcare, `/health`, own guard)
- **Nest ERPS is the PRODUCT; each ERP inside it is a VERTICAL.** `App\Support\NestErps` is the single authority: umbrella name/discriminator/public hub path plus the `VERTICALS` registry (key, label + lang key, panel prefix, guard, layout, login/register/dashboard paths, module service, billable-count callable). `App\Support\ProductCatalog` owns every product label/colour/CTA. A NEW VERTICAL = one registry entry + its own screens — no new product type, no new billing branch, no admin allow-list edit. Stored discriminator is `erps`; the older `health` value still reads (`NestErps::PRODUCT_TYPES` / `storedTypesFor()`); the vertical lives in `companies.erps_vertical` and `pricing_plans.erps_vertical`.
- Panel prefix `/health`, guard `health` and the `/healthcare` URL are deliberately unchanged — saved links and live sessions must not break. Public hub = `/nest-erps`; it is enquiry-only, with no price grid.
- First vertical — separate healthcare panel for clinics/hospitals: modules toggled per company (`opd`, `pharmacy`, `ipd`, `lab`, `accounts`…), roles via `users.health_role` + optional `users.health_permissions` custom set; every screen goes through `HealthAccessService::can()` and `HealthScopeService` (branch + department boundary).
- **OPD core**: patients (permanent MRN, duplicate detection, consent flags, `is_confidential`), practitioners + weekly slots + fee schedule (`doctors.manage`, admin-only), appointments/walk-in tokens, the reception→doctor visit lifecycle (vitals, notes, diagnosis, procedures, private-disk attachments, follow-up), structured prescriptions with a printable patient copy, and OPD reports (doctor workload, daily summary, appointment outcomes, no-shows). → memory `health-opd-core.md`
- **Inpatient & operations** (`ipd` module, `/health/ipd` + `/health/operations`): wards→rooms→beds with inherited day rates and a live bed board; admission lifecycle (request → deposit → bed → transfer → daily care → discharge request → financial clearance → release); stay charges (recurring room/nursing via `health:ipd-daily-charges`, one-off services, medicines, consumables, procedures, concessions, reversals — never deletions) and advances/refunds; theatre scheduling, clinical teams, packages, pre-op checks, consumable usage, completion and outcomes; occupancy/LOS/procedure/doctor-activity reporting. Four-way capability split (`wards.manage`, `ipd.manage`, `ipd.charge`, `ipd.discharge`) plus `operations.view|manage`. → memory `health-ipd-operations.md`

## UI / PWA
- Responsive sidebar layout, dark/light modes, emerald-600 primary; DI dashboard premium gradient banners + KPI cards.
- Three PWAs sharing ONE `/sw.js` (bump CACHE_VERSION on relevant deploys); update toast + `window.tnPwaUpdateHold` defers reload mid-sale; offline pre-caching; push notifications. → `sw-skiplist-sale-screens.md`
- Global mobile polish (iOS tuning, safe-area, touch targets). All A4 PDFs use `15mm 15mm 18mm 15mm` margins.
- Live static-asset caching: `.htaccess` caches css/js 30d — ANY loose public asset edit MUST bump its `?v=` in blade refs. → `static-asset-caching.md`

## External Dependencies
- **MariaDB/MySQL** — production MariaDB runs on the Islamabad VPS; PostgreSQL exists in Replit env but dev uses MySQL staging. Dev startup waits for the DB: "MySQL Staging" runs `scripts/dev-mysql-serve.sh` (stale-lock cleanup + `READY` line) and "Laravel Server" gates `artisan serve` on `bash scripts/dev-mysql-ready.sh --wait 15`. Run that probe (no args) before any browser/curl check — "NOT ready" means the db-down page, not a bug. → `dev-mysql-coldstart.md`
- **FBR (Pakistan)** — DI + FBR POS tax compliance APIs. **PRA / PRAL IMS API v1.2** — POS fiscal integration (cloud + local fiscal-device).
- **Laravel Breeze**, **Tailwind CSS**, **Alpine.js**, **Chart.js**. **Unsplash / Picsum** — `ProductImageService` fallback.
- **Domain SMTP (`noreply@taxnest.pk` via `mail.taxnest.pk`)** — ALL outgoing app email; admin SMTP override + MailHealth banner. The retired website host is not the app origin. → memory `mail-noreply-smtp.md`

## User preferences
- **FBR POS aur Digital Invoice (DI) se related KOI task propose/show na karein** — dono streams mukammal band hain jab tak owner khud in par kaam start na kare. Yeh rule HAR agent par lagu hai (task agents bhi — follow-up tajaweez mein bhi FBR/DI item shamil NA karein). (Owner, 2 Aug 2026; dobara sakhti se, 16 Aug 2026)
- **Naye/advance feature tasks NA bhejein.** Sirf PRA POS ke tasks, aur wo bhi sirf "jo ban chuka usko stable karne" wale — bug fixes, test-locks, hardening. Advance kaam owner ke kehne par hi. (Owner, 2 Aug 2026)
- Jab owner FBR POS par working shuru kare, tab SAB jama-shuda FBR tajaweez ek hi baar mashware ke liye pesh karein (owner har aik par haan/na kahega). (Owner, 2 Aug 2026)

## Tutorial Videos (Aug 2026)
- Public page `/tutorials` + inside-POS page `/pos/tutorials` (pos.auth), linked from landing nav + POS profile dropdown.
- Data: `tutorial_videos` table (Model `TutorialVideo`, categories shuruat/billing/customers/products/restaurant/reports/settings). Rows seeded idempotently inside the create migration (prod runs `migrate --force`).
- Files: `public/videos/tutorials/<slug>.mp4` (committed, ~5MB each, 1080p). 15 Urdu videos live (Aug 3): intro/promo, account, sale-screen, customers, customize, PWA install, madadgar, barcode, discount, provisional, bills-history, day-opening, hazri, desktop-agent, package-billing.
- Pipeline: `tools/video-pipeline/` (scenario JSON → record.cjs Playwright capture on demo shop company `videodemo@nestpos.pk` → assemble.cjs ffmpeg + ElevenLabs Urdu TTS). Demo shop reseed: `VideoDemoShopSeeder`.
