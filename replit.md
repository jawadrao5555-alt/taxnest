# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform designed for comprehensive tax and invoice management in Pakistan, ensuring strict compliance with the Federal Board of Revenue (FBR). Key capabilities include smart invoicing, configurable governance, an enterprise API, and robust PDF generation. The "Heavy Enterprise" version expands on this with features like a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, and Enterprise Analytics. The project aims to capture a significant market share in Pakistan by prioritizing compliance, scalability, and an exceptional user experience.

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
TaxNest is built on Laravel 12 with PHP 8.4, utilizing Breeze for authentication. The frontend employs Tailwind CSS, Alpine.js, and Chart.js.

**Core Architectural Patterns and Decisions:**
- **Multi-tenancy:** Implemented with `company_id` and `CompanyIsolation` middleware for data segregation.
- **Role-Based Access Control (RBAC):** Permissions are managed via `RoleMiddleware`.
- **Dual Invoice Numbering:** Supports separate internal and regulatory (FBR/PRA) invoice numbers.
- **Dynamic Validation Engine:** `ScheduleEngine` for FBR compliance rules.
- **Immutable Audit Logging:** Critical events are logged with SHA256 hashes for data integrity.
- **Queue-based Processing:** Background tasks utilize a database queue for asynchronous operations.
- **Company Approval Workflow:** Manages the lifecycle and status of companies.
- **Customer Ledger System:** Automates debit entries and allows manual adjustments.
- **Multi-Branch System:** Supports multiple operational branches per company.
- **FBR Token Health Monitoring:** Tracks FBR token status and connectivity.
- **Enterprise Analytics:** Provides KPIs and compliance metrics through dashboards.
- **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
- **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, pre-submission payload validation with sandbox mode, and submission idempotency.
- **Enterprise UX Simplification:** Streamlined invoice lifecycle with four states (`draft`, `failed`, `locked`, `pending_verification`) and a simplified FBR submission flow.
- **DI Invoice PDF Default:** Pure 100% B&W PDF is the standard output for invoices.
- **PRA POS Cart Per-Item Tax Toggle:** Prominent "NO TAX / TAX" toggle button next to Notes input for on-the-fly tax exemption marking on individual cart lines.
- **Market-Launch Receipt Hardening:** Thermal receipt templates are optimized for cheap thermal printers with specific styling adjustments for legibility.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, dynamic validation for tax schedules, and weighted suggestions.
- **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
- **Admin Announcement System:** Allows administrators to create targeted, dismissible announcements.
- **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
- **Product-Type Plan Separation:** `pricing_plans` table distinguishes between `di` and `pos` product types.
- **Subscription Override + Usage Limit System:** Admin-only override functionality on `subscriptions` table for `lifetime`, `temporary`, `grace`, or `usage_free` access.
- **NestPOS Module:** Isolated POS system with its own authentication, layouts, and data models, supporting PRA integration with offline billing and auto-sync. Includes a full Restaurant POS Module with table management, KDS, KOT, inventory, and CRM. Features an Enterprise Cart UI, seamless keyboard flow, and a unified top navigation layout.
- **FBR POS Module:** Isolated FBR-integrated POS with direct FBR API submission, product tax configuration, FBR reporting toggle, and a confidential PIN system. Features a refined professional UI palette. Enhanced cashier UX for quantity calculation and a provisional-bill / mandatory payment-confirm flow. Includes a Unified Smart Input for barcode/scan, and a full keyboard-only cart flow for high-volume scanning.
- **TaxNest PRA Sync Agent (Desktop Companion App):** Electron-based desktop application for polling the server, submitting invoices to PRA from a local IP, and reporting results.
- **UI/UX Design:** Responsive sidebar layout with consistent dark/light modes, emerald-600 primary color. Features a unified SaaS-grade design system, enhanced mobile responsiveness, and an Enterprise UX Engine for notifications, spinners, and transitions. DI Dashboard has premium upgrades with gradient banners and KPI cards.
- **PWA / Mall-Grade "exe-look" Suite:** Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos) with branded icons, Service Workers for offline capabilities, push notifications, and Blade components for PWA features. Includes an iOS Install Modal, POS offline pre-caching, and PWA diagnostics page, with auto-update and refresh control.
- **Hardening Batch — Phase 1:** Feature-flagged implementations for Failed Invoice Recovery System, HS Code Mapping Manager, and Reduced Rate Support.
- **FBR POS Edit & Retry (Failed Bills):** Allows cashiers to fix FBR-rejected bills and resubmit without regenerating the invoice.
- **PDF A4 Margin Hardening (Print Corner-Cut Fix):** All A4 PDF templates use safe `15mm 15mm 18mm 15mm` page margins to accommodate consumer printers.
- **Universal Mobile / PWA Polish:** Global CSS (`public/css/mobile.css`) applied across all layouts and standalone views to enhance mobile experience, including iOS tuning, safe-area insets, touch target sizing, responsive tables, mobile-first typography, and horizontal overflow prevention.
- **Universal Mobile / PWA Polish — Phase 2 Surgical Fixes:** Built on top of Phase 1. (a) Added `.dense-input { font-size: inherit !important; }` opt-out class so the global iOS-no-zoom 16px input rule respects intentionally-tiny POS cart fields (4 inputs in `pos/universal.blade.php` + `pos/restaurant/pos.blade.php` Notes/Discount). (b) Added `class="pos-edit-cart-floating-btn"` to the inline-styled fixed-position "Edit Cart" floating buttons in both POS files (they had `right:400px` which pushed them off-screen on <400px-wide phones); `mobile.css` now hides them on phones (`display:none !important`) so phone users use the dedicated bottom Cart bar instead. (c) Wrapped 2 dashboard.blade.php tables (Top 5 Customers, Branch Comparison) in `<div class="overflow-x-auto">`. (d) Confirmed POS Universal + Restaurant both already implement a sophisticated `mobileView === 'menu'/'cart'` Alpine state-machine with dedicated mobile Cart toggle + cart-screen Back-to-Menu button — no additional drawer work needed. Bumped CSS query string to `?v=1.2` across all 21 layout/standalone references for forced cache-bust. **Code review verdict: production-safe — additive only, !important-gated, mobile-breakpoint-scoped, zero risk to desktop UX.**
- **POS Cart Phase 3 — Keyboard Nav + Round-Off + Mobile Stacking:** Fixed 4 user-reported bugs in PRA POS (`pos/universal.blade.php`) AND Restaurant POS (`pos/restaurant/pos.blade.php`). (a) **Cart row navigation skip bug (1→3→5 instead of 1→2→3)**: Root cause was browser's native `<input type="number">` arrow-step behavior racing with Alpine's `@keydown.arrow-down.prevent.stop`. Fix: changed qty input from `type="number"` to `type="text" inputmode="decimal"`, removed per-input arrow handlers, centralized cart navigation in `handleKey()` via `[data-qty-input]` selector check (single source of truth — arrow keys ALWAYS call `moveCartSelection(±1)` when qty input is focused). Restaurant POS bonus fix: removed `@keydown.stop @keypress.stop @keyup.stop` triple-stop that was previously blocking ALL keyboard events from the qty input. (b) **Quantity cannot be typed**: replaced one-way `:value` + `@input.stop` raw-string assignment with regex digit/decimal sanitizer that allows typing freely while blocking non-numeric chars; `@blur` normalizes to integer/3dp. (c) **Round-off display**: added `roundedTotal` (= `Math.round(totalAmount)`) and `roundOff` (= rounded − actual) computed getters; cart totals + payment modal + mobile bottom bar now display rounded integer total with an explicit "Round Off + Rs. X.XX" line shown when |roundOff| > 0.001. (d) **Mobile cart cramped on phones**: added `@media (max-width: 480px)` + `(max-width: 360px)` rules in `mobile.css` that shrink qty stepper buttons (36→32px) and qty input (56→48px), shrink total column min-width (60→50px), and on ultra-narrow phones (≤360px) flex-wrap the row so product name takes its own full-width line. Bumped `mobile.css?v=1.2 → ?v=1.3` across all 21 layout/standalone references. **Note:** round-off is UI-display-only — backend still computes from line items; receipt PDF will reflect backend total (not the rounded display total) until backend round-off persistence is added in a future phase.

## External Dependencies
- **MySQL:** Primary production database.
- **PostgreSQL:** Used for Replit development.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Frontend styling.
- **Alpine.js:** Interactive frontend components.
- **Chart.js:** Data visualization.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.
- **Unsplash / Picsum:** (Fallback) for `ProductImageService`.