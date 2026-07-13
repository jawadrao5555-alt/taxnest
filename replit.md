# TaxNest - Heavy Enterprise Product

## Overview
Multi-company SaaS for tax + invoice management in Pakistan (FBR/PRA compliant): smart invoicing (DI), two POS products (PRA POS "NestPOS" + FBR POS), SaaS admin layer, PDF generation, enterprise analytics, immutable audit logs. Laravel 12 / PHP 8.4, Breeze auth, Tailwind + Alpine.js + Chart.js frontend. MySQL in production (owner's cPanel), MySQL staging in Replit dev.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo) — NTN 3620291786117, owner ZIA UR REHMAN, Digital Invoice ONLY (no POS data).
- NestPOS Enterprise Store (company_id 11) = dedicated POS company. POS Admin: posadmin@taxnest.com / Admin@12345.
- Test Trading Company (company_id 12, test@testtrading.pk / Admin@12345) — for testing admin approval workflow.
- DI and POS data are FULLY ISOLATED — no cross-contamination, ever.
- Billing: POS = ANNUAL-ONLY (6% discount baked in, no cycle toggle). DI = full toggle: Monthly / Quarterly(-1%) / Semi-Annual(-3%) / Annual(-6%).
- Auth guards are isolated per panel: DI=`web`, PRA POS=`pos`, FBR POS=`fbrpos`. Company users can NEVER cross-login between panels (wrong panel = "Invalid credentials", no redirect). Only ADMIN creds auto-detect on any login form → admin guard → /admin/dashboard. Rate-limited (5 attempts/key). POS admin on DI /login auto-redirects to /pos/login.
- Login identifiers: Email, Phone, Username, CNIC, NTN (CNIC/NTN maps to the company_admin of the matching company).
- Login pages: premium dark glassmorphism — POS purple, DI modal emerald, Admin indigo-navy, FBR POS deep blue. FBR POS routes: /fbr-pos/login, /fbr-pos/register, /fbr-pos/logout.
- Pending companies can VIEW all features but CANNOT act until admin approves (exception: /onboarding/* POSTs allowed so they don't deadlock).
- "Show Tax on Receipt" toggle OFF hides Subtotal + Tax on ALL POS receipts including PRA fiscal — customer copy shows grand TOTAL only, and line items show the ORIGINAL as-entered (ex-tax) prices, NOT grossed-up tax-inclusive ones (owner update Jul 2026: shelf prices must read exactly as typed; lines intentionally don't sum to total). Tax is ALWAYS submitted to PRA in full (details via Sahulat QR). Do NOT re-add a fiscal-always-show override or the tax-inclusive gross-up. Toggle lives on /pos/receipt-settings, NOT POS Features.
- NO BLUE on POS dashboard cards/tiles/banners (owner rule Jul 2026) — use teal brand family (teal-600/800, #0A4D5C). Theme engine only remaps purple-X, so blue never follows themes. Icon-box gradients exempt.
- Cards/banners CLEAN & SOLID — no decorative corner blobs, white corner glows, or top washes on KPI cards / dashboard cards / sale-screen tops / gradient banners. Also check CSS `<style>` blocks (`rgba(255,255,255…)`), not just Tailwind classes. EXEMPT: modal backdrops, login/landing/guest backgrounds, hover underline bars, icon-box gradients.
- BUTTONS & pills CLEAN & SOLID — no colored glow shadows (Tailwind `shadow-* shadow-<color>-N/NN` OR CSS `box-shadow: … rgba(<color>)`); owner reads them as edges "fading to white". Use neutral `shadow-sm` or none; strip ONLY the color token so focus rings / Alpine selected-states stay. Prefer solid `bg-<color>-600` over 2-hue gradients (theme engine only remaps purple-X, so mixed stops go muddy under other themes). Icon-box gradients exempt.

## System Architecture

### Core Platform
- Multi-tenancy via `company_id` + `CompanyIsolation` middleware; RBAC via `RoleMiddleware`; company approval workflow; multi-branch support; customer ledger (auto debits + manual adjustments); immutable audit logs (SHA256); database queue for background jobs; `ForceHttps` + subscription-based access control.
- SaaS management layer: separate admin + franchise panels (own auth/layouts), subscription plan builders, approval workflows, usage monitoring. `pricing_plans.product_type` separates `di` / `pos` / `fbrpos` / `standalone` plan sets; admin-only subscription overrides + usage limits on `subscriptions`.
- Admin announcement system (targeted, dismissible). Dual invoice numbering (internal + regulatory).

### Digital Invoice (DI)
- FBR compliance: `ScheduleEngine` dynamic validation, FBR Excel template alignment, PRAL API integration, per-item FBR fields, pre-submission payload validation with sandbox, submission idempotency, FBR token health monitor.
- Simplified invoice lifecycle (4 states) + simplified FBR submission flow. Invoice PDFs default to pure 100% B&W.
- Global HS Intelligence: `global_hs_master` table, HS resolution, tax-schedule validation, weighted suggestions; admin-managed HS mapping engine with real-time suggestions during invoice creation.

### PRA POS (NestPOS)
- Isolated POS with own auth/layouts/models; PRA integration with offline billing + auto-sync; full Restaurant module; unified top-nav layout. Desktop Sync Agent (Electron) polls server and submits to PRA.
- **Sale screen** = `resources/views/pos/universal.blade.php` (the ONLY live sale screen). Barcode/SKU exact-match search with fast-path add; customer phone filters-as-you-type; per-item NO TAX/TAX toggle; Quick Type manual entry (inventory-OFF); manual-cart bypass → `processPaymentManual` POSTs to `pos.invoice.store` with `_manual: true` (no master-product auto-create); stable `_uid` cart keys (Alpine DOM-reuse); persistent receipt popup (explicit dismiss only); order-type widget renders only when a restaurant-ish feature is on.
- **Keyboard**: Guided Keyboard Billing Flow default ON per company (`pos_guided_flow_enabled`, opt-OUT) — `flowStep` chain customer→items→cart→finish; Enter advances, `P` in Pay modal = provisional, `!e.repeat` guards. Cart-row shortcuts T/D/N: plain key fires ONLY on body/non-input focus (inside search input letters ALWAYS type — never re-add an "empty search = shortcut" branch); Alt+T/D/N anywhere; D/N gated OFF while modals open.
- **Provisional/local bills**: cashier saves "Provisional" from Pay modal (local status, no PRA submit), later promoted. F10 header "Local" modal (list/edit/delete/promote via `/pos/api/provisional-bills*`); F11 "Failed" modal (retry/edit/delete via `/pos/api/failed-bills*`, race-safe atomic claim on retry).
- **Reporting-OFF Finals Invariant (PRA & FBR)**: finals with reporting OFF = regulator mode + NULL status (`'pra'`+NULL / `'fbr'`+NULL) — NEVER `'local'` (local = deliberate provisionals only). Three-branch on every write path: provisional→local/'local'; reporting-ON final→mode/'pending'; reporting-OFF final→mode/NULL (no submission; FBR-off also skips the Rs 1 fee).
- **Tax rates (PRA Jul 2026)**: global defaults in `pos_tax_rules` (cash 16%, card/digital 8%); per-company overrides in `companies.pos_tax_rate_cash/card` (NULL = global). ALL reads via `PosTaxRule::getRateForMethod()` / `effectiveRules()` — never query the table directly. Receipts/payloads read the stored `pos_transactions.tax_rate` snapshot. Tax Reports rate-filter dropdown is DYNAMIC (never hardcode rates): distinct item-level rates in the CURRENT tab's data + configured effective rates, computed per-tab so PRA/Local rate sets stay isolated.
- **Rounding**: bill total = whole rupee on ALL write paths (backend `round()` to integer matches frontend `Math.round()`); lines stay 2dp.
- **PRA Connection Mode**: `companies.pra_connection_mode` 'cloud' | 'fiscal_device'. New PRA registrations hit Code 112 on cloud PostData — must use PRAL's local IMS Fiscal Device service (`localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel`, single-object payload) via the Desktop Sync Agent on the shop PC. fiscal_device mode: server never direct-submits (bills queue 'pending'); selecting it force-enables the agent + auto-generates API key.
- **Local Bills Portal** (`pos_role='local_viewer'`): super-admin-provisioned read-only accounts confined to `/pos/local-bills` (local-mode completed bills + CSV). Normal POS panel shows ONLY PRA/NULL bills — but LIVE local bills stay visible to cashiers via F10 until day-close archives them. Archive Portal excludes local mode (disjoint from Local Bills Portal).
- **Local Invoices report tab (Jul 2026)**: Sales Reports + Tax Reports each have an ADMIN-ONLY "Local Invoices" tab (`?tab=local`) fully isolated from the PRA tab (includes archived locals; cashiers forced to PRA server-side on all report/CSV/PDF endpoints). Per-bill "Submit to PRA" button on CURRENT-MONTH local bills only — previous months are CLOSED (view-only, server-enforced in both promote paths). Promote allots a fresh POS serial (leaves L-series), un-archives, and consumes monthly quota; archived local finals promotable by admins only.
- **Standalone POS Edition RETIRED (Jul 2026)**: `pricing_plans.product_type='standalone'` plans deleted; all companies forced `pos_integration_mode='pra'`; registration edition picker removed; `PosAuthController` forces 'pra'. Null-safe dead-gated reads (`?? 'pra'`) intentionally left in views + flip route. Do NOT re-add standalone surfaces.
- **PRA POS packages (Jul 2026)**: annual-only — Starter 9,999 (1 team account, 500 bills/month), Business 14,999 (5 accounts, 2,000 bills/month), Pro 24,999 (unlimited). Enforced via `PlanLimitService::canCreatePosBill()` (monthly FINAL-bill quota: status='completed' + invoice_mode NULL-or-≠'local', calendar month, archived rows count; trial cap separate; `invoice_limit_override` = bills/month, -1 unlimited) and `canAddPosUser()` (active pos_admin+pos_cashier vs `user_limit`; viewer portal roles exempt). Gated paths: storeInvoice finals (provisionals FREE until promoted), apiPromoteProvisional PRA branch (local-final branch exempt), retryPra ONLY when pra_status='local' (failed/offline retries not re-charged), restaurant payOrder, storeCashier + toggleCashier reactivation.
- Inventory nav always visible to non-cashiers (grey OFF badge; disabled pages redirect to POS Features, not 403). Anonymous Blade components resolve via `Blade::anonymousComponentPath(resource_path('views'))`.
- Theme engine: `pos-app.blade.php` "UNIVERSAL THEME OVERRIDE" remaps ~50 hardcoded purple-X utilities to `hsl(var(--accent-*))` when `body[data-theme] != purple`. New theme = define `--accent-h/-s/-l` + `--nav-bg` only. NEVER replicate purple-X overrides per-view.
- Market-launch thermal receipt templates hardened for cheap thermal printers.

### FBR POS
- Isolated FBR-integrated POS (`fbrpos` guard): direct FBR API submission, product tax config, FBR reporting toggle, confidential PIN system, provisional-bill + mandatory payment-confirm flow, Unified Smart Input (barcode/scan), full keyboard-only cart flow, Edit & Retry for FBR-rejected bills.
- **Universal sale screen (opt-in)**: `fbr-pos/universal.blade.php` is a PORT of the PRA universal screen (source stays untouched). Per-company `companies.fbr_universal_enabled` toggle; OFF = classic `create.blade.php`. Opens with a SHIM `@php` block pinning restaurant/inventory/tables/KOT OFF — do NOT delete pins (undefined vars = 500); PRA-only paths remain as dead gated branches for diffability. FBR-specific: per-item tax (18% default/exempt, no cash-card split), buyer NTN in Pay modal, per-row FBR drawer (hs_code/uom/tax_rate), PIN gate on F10 provisional modal, held sales via `FbrPosPhase2Controller` JSON carts (`/fbr-pos/api/hold|held/{id}/recall|DELETE`, recall deletes server-side race-safe); payment always routes processPaymentManual → `fbrpos.store`. Theme engine remaps blue-X (not purple-X). Customize page: `/fbr-pos/customize`.

### UI / PWA
- Responsive sidebar layout, dark/light modes, emerald-600 primary; unified SaaS-grade design system; DI dashboard premium gradient banners + KPI cards.
- Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos): branded icons, Service Workers (offline pre-caching for POS), push notifications, iOS install modal, PWA diagnostics page, auto-update/refresh control.
- Global mobile polish: iOS tuning, safe-area insets, touch targets, responsive tables, overflow prevention. All A4 PDFs use `15mm 15mm 18mm 15mm` page margins.

## External Dependencies
- **MySQL** — production DB (owner's cPanel); **PostgreSQL** exists in Replit env but dev uses MySQL staging.
- **FBR (Pakistan)** — DI + FBR POS tax compliance APIs.
- **PRA / PRAL IMS API v1.2** — POS fiscal integration (cloud + local fiscal-device service).
- **Laravel Breeze**, **Tailwind CSS**, **Alpine.js**, **Chart.js**.
- **Unsplash / Picsum** — fallback for `ProductImageService`.
