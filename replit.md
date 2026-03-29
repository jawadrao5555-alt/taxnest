# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform designed for comprehensive tax and invoice management in Pakistan, ensuring strict compliance with FBR regulations. It offers smart invoicing, configurable governance, an enterprise API, PDF generation, and a demo mode. The "Heavy Enterprise" version extends functionality with a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced security. The project aims to capture a high-volume market with competitive pricing and a 14-day free trial, focusing on robust compliance, scalability, and an intuitive user experience for businesses operating in Pakistan.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo/internal) - NTN: 3620291786117, Owner: ZIA UR REHMAN (Digital Invoice ONLY, NO POS data)
- NestPOS Enterprise Store (company_id 11) is the dedicated POS company — completely separate from Digital Invoice
- POS Admin: posadmin@taxnest.com / Admin@12345 (company_id 11, NestPOS Enterprise Store)
- Digital Invoice and POS data are FULLY ISOLATED — no cross-contamination
- POS billing is ANNUAL-ONLY (6% discount baked in) — no billing cycle toggle
- DI billing has full cycle toggle: Monthly / Quarterly(-1%) / Semi-Annual(-3%) / Annual(-6%)
- POS admin CANNOT login through Digital Invoice /login — auto-redirected to /pos/login
- **Unified Login**: All login forms (POS, DI, POS modal) auto-detect admin vs company user — single form, no separate admin login button. Admin credentials on any login form → admin guard + redirect to /admin/dashboard. Rate-limited (5 attempts/key).
- Login pages use premium dark glassmorphism design: POS = deep purple gradient, DI modal = deep emerald gradient, Admin = indigo-navy gradient, FBR POS = deep blue gradient
- FBR POS uses isolated `fbrpos` guard — completely separate auth from DI (`web`) and PRA POS (`pos`). Login at `/fbr-pos/login`, register at `/fbr-pos/register`, logout at `/fbr-pos/logout`
- Test Trading Company (company_id 12, test@testtrading.pk / Admin@12345) — for testing admin approval workflow
- Pending companies can VIEW all features but CANNOT perform any actions until admin approves
- Login supports: Email, Phone, Username, CNIC, NTN
- CNIC/NTN login maps to company_admin user of matching company

## System Architecture
TaxNest is built on Laravel 12 with PHP 8.4, utilizing Breeze for authentication. The frontend employs Tailwind CSS, Alpine.js, and Chart.js, with PostgreSQL as the chosen database.

**Core Architectural Patterns and Decisions:**
- **Multi-tenancy:** Implemented using `company_id` and a dedicated `CompanyIsolation` middleware.
- **Role-Based Access Control (RBAC):** Permissions are managed via `RoleMiddleware`.
- **Dual Invoice Numbering:** Supports separate internal and FBR invoice numbers.
- **Dynamic Validation Engine:** `ScheduleEngine` handles FBR compliance rules.
- **Immutable Audit Logging:** Critical events are logged with SHA256 hashes for integrity.
- **Queue-based Processing:** Background tasks are managed using a database queue.
- **Company Approval Workflow:** Governs the lifecycle and status of companies.
- **Customer Ledger System:** Automates debit entries and allows manual adjustments.
- **Multi-Branch System:** Supports multiple operational branches per company.
- **FBR Token Health Monitoring:** Tracks FBR token status and connectivity.
- **Enterprise Analytics:** Provides KPIs and compliance metrics through dashboards.
- **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
- **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, and pre-submission payload validation with a sandbox mode.
- **Enterprise Scoped Idempotency Shield:** A 6-phase system for preventing duplicate submissions, scoped per invoice.
- **Enterprise UX Simplification:** Invoice lifecycle has 4 states (`draft`, `failed`, `locked`, `pending_verification`) with specific FBR submission flow and status transitions.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, and dynamic validation for tax schedules, including weighted suggestions and rejection learning.
- **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
- **Admin Announcement System:** Allows administrators to create targeted, dismissable announcements.
- **Collapsible Sidebar Navigation:** Features localStorage persistence for user preferences.
- **Admin Revenue Dashboard:** Provides financial oversight, trial warnings, and activity feeds.
- **Admin Invoice Override:** Super admin functionality to manually manage invoice status.
- **Dashboard Quick Actions:** Shortcut grid for common user tasks.
- **NestPOS Module:** A completely isolated Point of Sale system with PRA integration, separate authentication, layouts, and data models. Supports offline billing with auto-sync, dual invoice numbering (POS and PRA Fiscal), comprehensive tax reporting, business profile management, user profile, per-item tax exemption, and inventory enable/disable.
- **Restaurant POS Module:** Full restaurant management integrated into NestPOS at `/pos/restaurant/*`. Protected by `RestaurantOnly` middleware (only `pos_type='restaurant'` companies can access). Includes: Restaurant POS Screen (redesigned: horizontal category pills, 4-col product grid LEFT, cart sidebar RIGHT, localStorage cart persistence per user/company), Table Management (floors + tables with status tracking + lock enforcement), Kitchen Display System (KDS with real-time polling, order status transitions: held→preparing→ready→completed), Kitchen Ticket Printing (KOT at `/pos/restaurant/orders/{id}/kitchen-ticket`, auto-print on hold when enabled), Ingredient Inventory (raw materials with stock levels, low-stock alerts, stock adjustments), Recipe/BOM Engine (link ingredients to products, auto-deduct on payment), Customer CRM (search + quick-add AJAX). Orders flow: Hold→KDS→Pay. Inventory deducts ONLY on Pay. Order numbers: `ORD-YYMMDD-XXXXX` (timestamp+random, collision-safe). Zombie table cleanup runs every 15 min via `pos:clean-zombie-tables`. Tables: `restaurant_floors`, `restaurant_tables`, `restaurant_orders`, `restaurant_order_items`, `ingredients`, `product_recipes`. Controllers: `RestaurantPosController`, `RestaurantTableController`, `RestaurantKdsController`, `IngredientController`.
- **Restaurant POS Enterprise Upgrade (7-Phase):** Premium UI with Inter font, skeleton loaders, shimmer animations, product card hover effects (translateY + shadow), quick-add overlay buttons. Product Image Auto-Fetch via `ProductImageService` (Unsplash + Picsum fallback), integrated into product creation. Live Inventory Feedback with green/yellow/red stock dots on product cards, OUT badge, configurable `blockOutOfStock`. Customer Intelligence API (`/pos/restaurant/api/customer-lookup`) with visit count, total spent, VIP badge (5+ orders), auto-lookup on phone. Smart Performance with lazy loading (60 initial + load more), browser caching (localStorage 5min TTL). Safety Hardening with client-side double-click prevention, server-side cart-hash idempotency (5s cache TTL), sanitized error messages, image fallback handlers. Priority/Rush orders supported.
- **Restaurant POS Enterprise Enhancements:** Receipt improvements (80mm thermal layout, logo/spacing/QR, duplicate print prevention), Kitchen Routing (per-station KOT split with independent print buttons), Dashboard enhancements (hourly sales chart, peak hour indicator, tax/discount stats, 2min auto-refresh), Per-Item Discount Engine (% or Rs per item, stacks with order-level discount, correct tax recalculation, server-validated, persisted in `restaurant_order_items` and `pos_transaction_items`), Performance & UX (loading spinners on Hold/Pay/Cash/Card buttons, proportional tax distribution per line item).
- **Dual Invoice Mode (PRA POS):** PRA ON → `POS-YYYY-XXXXX` prefix, PRA OFF → `LOCAL-YYYY-XXXXX` prefix.
- **FBR POS Module:** Isolated FBR-integrated POS at `/fbr-pos` with direct FBR API submission, separate from PRA POS. Includes dashboard, invoice creation, transactions, settings, and FBR retry.
- **FBR Reporting Toggle:** Dashboard toggle (admin-only) controls FBR reporting ON/OFF. FBR ON → `FPOS-YYYY-XXXXX` prefix with FBR submission. FBR OFF → `FLOCAL-YYYY-XXXXX` prefix, saved locally without FBR submission. Same pattern as PRA Reporting toggle.
- **FBR POS Local Tabs:** Transactions page has FBR/Local mode tabs with PIN-protected Local tab (server-side enforced). Local invoice detail pages also require PIN verification.
- **Dual Invoice Mode (FBR POS):** FBR ON → `FPOS-YYYY-XXXXX` prefix, FBR OFF → `FLOCAL-YYYY-XXXXX` prefix.
- **Confidential PIN System:** Company admin sets 4-6 digit PIN (bcrypt-hashed). PIN required to access local data tabs. Single-use per visit, 5 wrong attempts = 15 min lockout. Server-side enforced.
- **Cashier Account Management:** `users.pos_role` column (`pos_admin` or `pos_cashier`). Admin manages cashiers at `/pos/team`. `PosAdminOnly` middleware protects admin-only routes.
- **Local Tabs:** Transactions, Reports, and Tax Reports pages have PRA/Local mode tabs, with Local tab being PIN-protected.
- **Role-based Sidebar Navigation:** Cashiers see limited sidebar, Admins see full sidebar.
- **Dashboard Data Isolation:** Recent Transactions on dashboard only shows PRA invoices. Local invoices are hidden and only accessible through Local tab after PIN verification. Aggregate stats include all invoices.
- **Reports Cashier Filter:** Reports page has "View Sales By" dropdown. Admin sees all team members, cashier sees "All Company Sales" and "My Sales Only".
- **Invoice Social Share:** POS invoices can be shared as PDF via WhatsApp, Email, SMS, or copy link. Uses secure share tokens.
- **Tax Exempt Items:** Products and services can be marked as tax-exempt. Server-authoritative exemption resolution. Tax reports show exempt amounts.
- **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
- **Dynamic Landing Pages:** Three separate landing pages with product-isolated login: `/` (Main TaxNest), `/digital-invoice` (Dedicated DI), `/pos` (Dedicated NestPOS).
- **Admin Plan Management:** SaaS admin can edit all plan details inline.
- **Product-Type Plan Separation:** `pricing_plans` table has a `product_type` column (`di` or `pos`) to display relevant plans on landing pages.

**Key Features:**
- **Smart Invoice Builder:** Guided invoice creation with compliance scoring.
- **FBR-compliant PDF Generation:** Includes QR data and watermarks.
- **Admin Company Deep View:** Comprehensive oversight for super administrators.
- **Risk Intelligence Engine:** Pre-submission risk detection.
- **SRO Suggestion System:** Confidence-based SRO autofill.
- **Enhanced Compliance Scoring:** Formula-based scoring with FBR validation confirmation.
- **Custom Billing Plan Builder:** Admin-only dynamic pricing for subscriptions.
- **Customer Registered/Unregistered Toggle:** Manages customer types with conditional fields.
- **Products Page Upgrade:** Enhanced product management with `serial_number`, `mrp`, and dynamic tax calculation previews.
- **FBR API v1.12 Compliance:** Adherence to FBR API specifications, including real-time synchronous submission.

**UI/UX Design:**
- **Layout:** Responsive sidebar with a single scrollable content area.
- **Styling:** Consistent use of dark/light modes, standardized components, and an emerald-600 primary color palette.
- **Design System:** Unified SaaS-grade design: Cards use `rounded-xl shadow-md` with hover effects. Buttons use `rounded-lg font-semibold` with gradient fills. Consistent section padding.
- **Product Visual Separation:** Digital Invoice uses emerald theme, NestPOS uses purple theme, FBR POS uses blue theme.
- **Navigation:** All landing pages use visible wrapping nav (NO hamburger menu).
- **PWA Enhancements:** Install prompts, offline badges, update banners, and manifest shortcuts.
- **Mobile Responsiveness:** Fully responsive at 320px+. `overflow-x-hidden` on `<html>` and `<body>`. Landing pages stack cards vertically. POS sidebar uses slide-out drawer. Data tables use progressive column hiding. Inline edit forms use responsive grids. All tables have `overflow-x-auto`. Header rows stack on mobile. Buttons use `w-full sm:w-auto`. Consistent section padding.
- **Enterprise UX Engine:** Includes toast notifications, loading spinners, page transitions, and auto-scrolling to errors.
- **Keyboard Shortcuts:** Implemented for invoice creation.
- **Intelligent Autofocus:** Automatically advances input fields.
- **Sticky Bottom Summary:** Displays financial totals and FBR environment badge.
- **CSS Build:** Tailwind CSS is pre-compiled via Vite.

## External Dependencies
- **PostgreSQL:** Primary database.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Frontend styling.
- **Alpine.js:** Interactive frontend components.
- **Chart.js:** Data visualization.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.
- **PRA Direct Connection:** `PraIntegrationService` connects directly to PRA's `ims.pral.com.pk`.
- **Cross-Database Compatibility:** `App\Helpers\DbCompat` helper class provides `like()`, `dateFormat()`, `extractYear()`, `extractMonth()`, `jsonExtract()` methods for MySQL vs PostgreSQL compatibility.