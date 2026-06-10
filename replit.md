# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform designed for comprehensive tax and invoice management in Pakistan, ensuring compliance with the Federal Board of Revenue (FBR). It provides features such as smart invoicing, configurable governance, an enterprise API, and robust PDF generation. The "Heavy Enterprise" version extends these capabilities with a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, and Enterprise Analytics. The project aims to achieve market leadership in Pakistan by offering a compliant, scalable, and exceptional user experience.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo/internal) - NTN: 3620291786117, Owner: ZIA UR REHMAN (Digital Invoice ONLY, NO POS data)
- NestPOS Enterprise Store (company_id 11) is the dedicated POS company — completely separate from Digital Invoice
- POS Admin: posadmin@taxnest.com / Admin@12345 (company_id 11, NestPOS Enterprise Store)
- Digital Invoice and POS data are FULLY ISOLATED — no cross-contamination
- POS billing is ANNUAL-ONLY (6% discount baked in) — no billing cycle toggle
- DI billing has full cycle toggle: Monthly / Quarterly(-1%) / Semi-Annual(-3%) / Annual(-6%)
- POS admin CANNOT login through Digital Invoice /login — auto-redirected to /pos/login
- Unified Login (ADMIN-ONLY auto-detect): All login forms (POS, DI, FBR POS) auto-detect ONLY admin credentials → admin guard + redirect to /admin/dashboard. Company users CANNOT cross-login between panels — DI creds on /pos/login = "Invalid credentials" (no redirect). Each company guard (`web`/`pos`/`fbrpos`) is isolated. Rate-limited (5 attempts/key).
- Login pages use premium dark glassmorphism design: POS = deep purple gradient, DI modal = deep emerald gradient, Admin = indigo-navy gradient, FBR POS = deep blue gradient
- FBR POS uses isolated `fbrpos` guard — completely separate auth from DI (`web`) and PRA POS (`pos`). Login at `/fbr-pos/login`, register at `/fbr-pos/register`, logout at `/fbr-pos/logout`
- Test Trading Company (company_id 12, test@testtrading.pk / Admin@12345) — for testing admin approval workflow
- Pending companies can VIEW all features but CANNOT perform any actions until admin approves
- Login supports: Email, Phone, Username, CNIC, NTN
- CNIC/NTN login maps to company_admin user of matching company

## System Architecture
TaxNest is built on Laravel 12 with PHP 8.4, utilizing Breeze for authentication and a frontend stack of Tailwind CSS, Alpine.js, and Chart.js.

**Core Architectural Patterns and Decisions:**
-   **Multi-tenancy:** Implemented with `company_id` and `CompanyIsolation` middleware.
-   **Role-Based Access Control (RBAC):** Permissions managed via `RoleMiddleware`.
-   **Dual Invoice Numbering:** Supports separate internal and regulatory invoice numbers.
-   **Dynamic Validation Engine:** `ScheduleEngine` for FBR compliance rules.
-   **Immutable Audit Logging:** Critical events logged with SHA256 hashes.
-   **Queue-based Processing:** Background tasks use a database queue.
-   **Company Approval Workflow:** Manages company lifecycle.
-   **Customer Ledger System:** Automates debit entries and allows manual adjustments.
-   **Multi-Branch System:** Supports multiple operational branches per company.
-   **FBR Token Health Monitoring:** Tracks FBR token status.
-   **Enterprise Analytics:** Provides KPIs and compliance metrics via dashboards.
-   **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
-   **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, pre-submission payload validation with sandbox, and submission idempotency.
-   **Enterprise UX Simplification:** Streamlined invoice lifecycle with four states and a simplified FBR submission flow.
-   **DI Invoice PDF Default:** Pure 100% B&W PDF output for invoices.
-   **PRA POS Cart Per-Item Tax Toggle:** Prominent "NO TAX / TAX" toggle for on-the-fly tax exemption.
-   **Market-Launch Receipt Hardening:** Thermal receipt templates optimized for cheap thermal printers.
-   **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, dynamic validation for tax schedules, and weighted suggestions.
-   **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
-   **Admin Announcement System:** Allows administrators to create targeted, dismissible announcements.
-   **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
-   **Product-Type Plan Separation:** `pricing_plans` table distinguishes between `di` and `pos` product types.
-   **Subscription Override + Usage Limit System:** Admin-only override functionality on `subscriptions` table.
-   **NestPOS Module:** Isolated POS system with its own authentication, layouts, and data models, supporting PRA integration with offline billing and auto-sync. Includes a full Restaurant POS Module, an Enterprise Cart UI, seamless keyboard flow, and a unified top navigation layout.
-   **FBR POS Module:** Isolated FBR-integrated POS with direct FBR API submission, product tax configuration, FBR reporting toggle, and a confidential PIN system. Features a refined professional UI palette, enhanced cashier UX, provisional-bill/mandatory payment-confirm flow, Unified Smart Input for barcode/scan, and a full keyboard-only cart flow.
-   **TaxNest PRA Sync Agent (Desktop Companion App):** Electron-based desktop application for server polling and submitting invoices to PRA.
-   **UI/UX Design:** Responsive sidebar layout with consistent dark/light modes, emerald-600 primary color. Features a unified SaaS-grade design system, enhanced mobile responsiveness, and an Enterprise UX Engine. DI Dashboard has premium upgrades with gradient banners and KPI cards.
-   **PWA / Mall-Grade "exe-look" Suite:** Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos) with branded icons, Service Workers for offline capabilities, push notifications, and Blade components. Includes an iOS Install Modal, POS offline pre-caching, and PWA diagnostics page, with auto-update and refresh control.
-   **FBR POS Edit & Retry (Failed Bills):** Allows cashiers to fix FBR-rejected bills and resubmit.
-   **PDF A4 Margin Hardening:** All A4 PDF templates use safe `15mm 15mm 18mm 15mm` page margins.
-   **Universal Mobile / PWA Polish:** Global CSS applied across all layouts and standalone views to enhance mobile experience, including iOS tuning, safe-area insets, touch target sizing, responsive tables, mobile-first typography, and horizontal overflow prevention.
-   **POS Cart Keying Fixes:** Implemented stable `_uid` fields for cart items to resolve DOM-reuse bugs in Alpine.js across all cart-bearing POS views.
-   **PRA POS Quick Type — Manual Entry Inline (Inventory-OFF only):** Extends the Quick Type modal for manual product entry in inventory-off mode, allowing cashiers to input prices for unmatched lines directly.
-   **PRA POS Manual-Cart Billing Bypass:** Universal POS `processPayment()` detects `hasManualItems()` and routes through a dedicated path (`processPaymentManual`) that POSTs directly to `pos.invoice.store`. A `_manual: true` payload flag suppresses auto-creation of master products.
-   **Universal POS Provisional Bill Flow (Save Before Payment):** Allows cashiers to save a bill as "Provisional" (local status, no PRA submission) directly from the Pay modal, with clear UI indicators and backend routing to handle provisional creation and subsequent promotion to final status.
-   **Universal POS Persistent Receipt Popup:** The receipt success modal is now persistent, requiring explicit user action (X, Close, or New Sale buttons) to dismiss, ensuring cashiers can verify and reprint receipts as needed, with live print status indicators.
-   **PRA POS Provisional Bills Header Shortcut:** A purple "Local" button next to "New Sale" in the universal POS header (F10 hotkey) shows a count badge and opens an inline modal listing all `pra_status='local'` bills. Each row offers Edit / Delete / Make Final actions with keyboard navigation (↑↓ + E/D/Enter), eliminating the need to navigate to the transactions page. Backed by 3 new JSON endpoints: `GET /pos/api/provisional-bills`, `POST /pos/api/provisional-bills/{id}/delete`, `POST /pos/api/provisional-bills/{id}/promote`. The list auto-refreshes on page mount and after each provisional save.
-   **PRA POS Failed Bills Header Shortcut (F11):** A red "Failed" button next to "Local" with a pulsing count badge opens a modal listing bills with `pra_status IN (failed, offline, pending)` AND `pra_invoice_number IS NULL`. Each row offers Retry (re-submit to PRA) / Edit / Delete with keyboard nav (↑↓ + E/D/Enter/Esc). Race-safe atomic claim via conditional UPDATE prevents duplicate PRA submissions on double-click. Backed by `GET /pos/api/failed-bills` + `POST /pos/api/failed-bills/{id}/retry`. Auto-refreshes on mount and after every successful sale.
-   **Universal POS Theme Engine (full-app theming):** `pos-app.blade.php` `<style>` block has a "UNIVERSAL THEME OVERRIDE" layer that remaps ~50 hardcoded `purple-X` Tailwind utilities (bg / text / border / ring / hover / dark / gradient stops) to `hsl(var(--accent-h), var(--accent-s), L%)` whenever `body[data-theme]` is anything other than `purple`. Single source of truth — adding new themes only requires defining `--accent-h/-s/-l` + `--nav-bg` for that theme; cart, badges, search, modals, pills automatically follow. NEVER replicate purple-X overrides per-view.
-   **POS Bill Rounding (Whole Rupee):** Backend `PosController::storeInvoice()` and `payHeldOrderDirect()` round `$totalAmount` to nearest integer (no decimals) so backend total matches frontend `roundedTotal` getter. Frontend cart already uses `Math.round()` — backend was previously `round(..., 2)` causing 533.60 vs 534 mismatch in receipts/PDF/DB.
-   **Universal POS D / N Cart-Row Shortcuts:** `D` toggles the per-item discount panel on the active (or last) cart row and auto-focuses the discount input; `N` focuses the per-item notes input. Same smart routing as `T` — plain key works in body / empty search; `Alt+D` / `Alt+N` work anywhere. Both keys are gated OFF when any modal is open so they never conflict with modal-level keyboard semantics (held/local/failed delete shortcut, etc.).
-   **Universal POS Guided Keyboard Billing Flow (opt-in, default OFF):** Per-company `companies.pos_guided_flow_enabled` toggle (POS → Features → "Guided Keyboard Billing") drives an additive `flowStep` state machine ('customer'→'items'→'cart'→'finish') with a display-only step-indicator strip (x-show=guidedFlow). Keyboard chain: customer phone Enter → focus search; type + Enter adds items; Enter on empty search → enter cart mode; in cart `+`/`-` adjust qty and Enter opens the Pay modal; in Pay modal `P` = save Provisional, Enter = Cash final. Every guided branch is gated by `this.guidedFlow`, so OFF mode is byte-identical to before. Enter handlers carry `!e.repeat` guards to block held-key auto-charge.

## External Dependencies
-   **MySQL:** Primary production database.
-   **PostgreSQL:** Used for Replit development.
-   **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
-   **Laravel Breeze:** Authentication scaffolding.
-   **Tailwind CSS:** Frontend styling.
-   **Alpine.js:** Interactive frontend components.
-   **Chart.js:** Data visualization.
-   **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.
-   **Unsplash / Picsum:** (Fallback) for `ProductImageService`.