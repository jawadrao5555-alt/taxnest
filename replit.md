# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform designed for comprehensive tax and invoice management in Pakistan, ensuring strict compliance with the Federal Board of Revenue (FBR). It offers smart invoicing, configurable governance, an enterprise API, and robust PDF generation. The "Heavy Enterprise" version extends these capabilities with advanced features such as a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, and Enterprise Analytics. The project aims to achieve a dominant market position in Pakistan by focusing on compliance, scalability, and an exceptional user experience.

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
TaxNest is built on Laravel 12 with PHP 8.4, leveraging Breeze for authentication. The frontend uses Tailwind CSS, Alpine.js, and Chart.js.

**Core Architectural Patterns and Decisions:**
-   **Multi-tenancy:** Implemented with `company_id` and `CompanyIsolation` middleware for data segregation.
-   **Role-Based Access Control (RBAC):** Permissions are managed via `RoleMiddleware`.
-   **Dual Invoice Numbering:** Supports separate internal and regulatory invoice numbers.
-   **Dynamic Validation Engine:** `ScheduleEngine` for FBR compliance rules.
-   **Immutable Audit Logging:** Critical events are logged with SHA256 hashes.
-   **Queue-based Processing:** Background tasks use a database queue.
-   **Company Approval Workflow:** Manages company lifecycle and status.
-   **Customer Ledger System:** Automates debit entries and allows manual adjustments.
-   **Multi-Branch System:** Supports multiple operational branches per company.
-   **FBR Token Health Monitoring:** Tracks FBR token status and connectivity.
-   **Enterprise Analytics:** Provides KPIs and compliance metrics via dashboards.
-   **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
-   **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, pre-submission payload validation with sandbox mode, and submission idempotency.
-   **Enterprise UX Simplification:** Streamlined invoice lifecycle with four states and a simplified FBR submission flow.
-   **DI Invoice PDF Default:** Pure 100% B&W PDF output for invoices.
-   **PRA POS Cart Per-Item Tax Toggle:** Prominent "NO TAX / TAX" toggle for on-the-fly tax exemption marking on individual cart lines.
-   **Market-Launch Receipt Hardening:** Thermal receipt templates optimized for cheap thermal printers.
-   **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, dynamic validation for tax schedules, and weighted suggestions.
-   **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
-   **Admin Announcement System:** Allows administrators to create targeted, dismissible announcements.
-   **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
-   **Product-Type Plan Separation:** `pricing_plans` table distinguishes between `di` and `pos` product types.
-   **Subscription Override + Usage Limit System:** Admin-only override functionality on `subscriptions` table for various access types.
-   **NestPOS Module:** Isolated POS system with its own authentication, layouts, and data models, supporting PRA integration with offline billing and auto-sync. Includes a full Restaurant POS Module (table management, KDS, KOT, inventory, CRM), an Enterprise Cart UI, seamless keyboard flow, and a unified top navigation layout.
-   **FBR POS Module:** Isolated FBR-integrated POS with direct FBR API submission, product tax configuration, FBR reporting toggle, and a confidential PIN system. Features a refined professional UI palette, enhanced cashier UX, provisional-bill/mandatory payment-confirm flow, Unified Smart Input for barcode/scan, and a full keyboard-only cart flow.
-   **TaxNest PRA Sync Agent (Desktop Companion App):** Electron-based desktop application for server polling, submitting invoices to PRA from a local IP, and reporting results.
-   **UI/UX Design:** Responsive sidebar layout with consistent dark/light modes, emerald-600 primary color. Features a unified SaaS-grade design system, enhanced mobile responsiveness, and an Enterprise UX Engine for notifications, spinners, and transitions. DI Dashboard has premium upgrades with gradient banners and KPI cards.
-   **PWA / Mall-Grade "exe-look" Suite:** Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos) with branded icons, Service Workers for offline capabilities, push notifications, and Blade components. Includes an iOS Install Modal, POS offline pre-caching, and PWA diagnostics page, with auto-update and refresh control.
-   **FBR POS Edit & Retry (Failed Bills):** Allows cashiers to fix FBR-rejected bills and resubmit.
-   **PDF A4 Margin Hardening:** All A4 PDF templates use safe `15mm 15mm 18mm 15mm` page margins.
-   **Universal Mobile / PWA Polish:** Global CSS applied across all layouts and standalone views to enhance mobile experience, including iOS tuning, safe-area insets, touch target sizing, responsive tables, mobile-first typography, and horizontal overflow prevention.
-   **POS Cart Keying Fixes:** Implemented stable `_uid` fields for cart items to resolve DOM-reuse bugs in Alpine.js across all cart-bearing POS views.
-   **PRA POS Quick Type — Manual Entry Inline (Inventory-OFF only):** Extends the Quick Type modal for manual product entry in inventory-off mode, allowing cashiers to input prices for unmatched lines directly, creating synthetic manual cart lines (one line per parsed entry with the parsed quantity, e.g. "Burger 3" → 1 line of qty 3). Manual prices are preserved across re-parses by line index (duplicate-name safe).
-   **PRA POS Manual-Cart Billing Bypass:** Universal POS `processPayment()` detects `hasManualItems()` and routes through a dedicated path (`processPaymentManual`) that POSTs directly to `pos.invoice.store` (`PosController::storeInvoice`, lax per-item validation) instead of the strict `pos.restaurant.orders.hold` endpoint. Backend `storeInvoice` returns JSON when `Accept: application/json` is set. A `_manual: true` payload flag suppresses the auto-create-as-master-product branch in `resolveItemExemptions`, so ad-hoc cashier lines never pollute the product master. Fixes both the existing "+ Manual" toolbar button and the new Quick Type manual entries.
-   **PRA POS Universal v2 (Phase 1 — Foundation Polish):** Premium customization layer for the universal POS. Includes an expanded `PosFeatureService` with 9 industry presets and 14 module flags, a dedicated `/pos/features` page for customization, admin override capabilities at `/admin/company/{id}/pos-features`, and a dashboard CTA banner. Allows manual item addition (inventory-OFF only) directly to the cart, with an option to persist.
-   **POS Product Card — Text-Only Row When No Image:** Product grids in Restaurant POS and Universal POS now render a compact text-only row when no product image is available, rather than a placeholder.
-   **PRA POS Responsive Polish (Phase 1.5 — POS-Scoped Audit & Fixes):** Targeted responsive fixes for core POS pages verified across mobile and desktop viewports, ensuring no body-level horizontal overflow. Includes POS-scoped CSS rules to maintain multi-column grids on mobile for KPI tiles and action buttons, and `overflow-x-auto` for wide tables.
-   **Receipt Tax-Exempt Visibility:** Both 80mm and 58mm thermal receipt templates plus the on-screen `transaction-show` view now mark tax-exempt cart lines with a bordered `NT` badge next to the item name, plus a "NT = NO TAX" footnote when any exempt item exists. Totals tables show explicit "Tax-Exempt Items" and "Taxable Amount" rows whenever `exempt_amount > 0`, so cashiers/customers can clearly see why receipt subtotal differs from the PRA portal's reported gross (PRA submission filters out exempt items per PRAL spec — `PraIntegrationService` line 73). Restaurant receipt template already had this treatment.
-   **PRA POS Universal Print Strategy Hardening:** Universal POS print chain (`resources/views/pos/universal.blade.php`) ported from the inferior parallel-`setTimeout(200/1800)` race to the postMessage-chained engine that already works in restaurant POS. New helpers `_printViaIframe()`, `runAutoPrintChain()`, `queuePrintTimer()`, `cancelPendingPrints()` plus session-epoch invalidation. Receipt template (`pos/restaurant/receipt.blade.php`) and KOT template (`pos/restaurant/kitchen-ticket.blade.php`) already postMessage `pos_print_done` with the parent's `_signal` query param when their dialogs close — parent waits for the signal before chaining the next print. Receipt ALWAYS prints first (even when auto-print is OFF but auto-KOT is ON, receipt is forced first); KOT chains only after the receipt dialog is dismissed (Chrome blocks parent JS during print). Manual-cart bills (Quick Type / "+ Manual" toolbar) now also auto-print receipt on success — previously `processPaymentManual` skipped the auto-print branch entirely. Receipt-modal close (Esc / backdrop / auto-dismiss) fires `cancelPendingPrints()` via `x-effect` — invalidates session epoch, clears queued timers, removes registered postMessage listeners (prevents long-session listener accumulation across many sales). 30-second hard ceiling per iframe stops the chain from hanging if a print dialog never closes (exotic printer drivers).

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