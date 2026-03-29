# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform designed for comprehensive tax and invoice management in Pakistan, ensuring strict compliance with FBR regulations. It provides smart invoicing, configurable governance, an enterprise API, PDF generation, and a demo mode. The "Heavy Enterprise" version expands capabilities with a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced security. The project aims to capture a high-volume market with competitive pricing and a 14-day free trial, focusing on robust compliance, scalability, and an intuitive user experience for businesses in Pakistan.

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
- **Multi-tenancy:** Implemented using `company_id` and a `CompanyIsolation` middleware.
- **Role-Based Access Control (RBAC):** Permissions are managed via `RoleMiddleware`.
- **Dual Invoice Numbering:** Supports separate internal and FBR/PRA invoice numbers.
- **Dynamic Validation Engine:** `ScheduleEngine` handles FBR compliance rules.
- **Immutable Audit Logging:** Critical events are logged with SHA256 hashes.
- **Queue-based Processing:** Background tasks are managed using a database queue.
- **Company Approval Workflow:** Governs company lifecycle and status.
- **Customer Ledger System:** Automates debit entries and allows manual adjustments.
- **Multi-Branch System:** Supports multiple operational branches per company.
- **FBR Token Health Monitoring:** Tracks FBR token status and connectivity.
- **Enterprise Analytics:** Provides KPIs and compliance metrics through dashboards.
- **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
- **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, and pre-submission payload validation with a sandbox mode.
- **Enterprise Scoped Idempotency Shield:** A 6-phase system for preventing duplicate submissions, scoped per invoice.
- **Enterprise UX Simplification:** Invoice lifecycle has 4 states (`draft`, `failed`, `locked`, `pending_verification`) with specific FBR submission flow.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, and dynamic validation for tax schedules, including weighted suggestions and rejection learning.
- **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
- **Admin Announcement System:** Allows administrators to create targeted, dismissable announcements.
- **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
- **Product-Type Plan Separation:** `pricing_plans` table has a `product_type` column (`di` or `pos`) to display relevant plans on landing pages.

**NestPOS Module:**
- **Isolated POS System:** Separate from Digital Invoice, with its own authentication, layouts, and data models.
- **PRA Integration:** Supports offline billing with auto-sync and dual invoice numbering (POS and PRA Fiscal).
- **Restaurant POS Module:** Full restaurant management integrated into NestPOS at `/pos/restaurant/*`.
    - **Features:** Restaurant POS Screen, Table Management, Kitchen Display System (KDS), Kitchen Ticket Printing (KOT), Ingredient Inventory, Recipe/BOM Engine, Customer CRM.
    - **Enterprise Upgrade:** Premium UI, Product Image Auto-Fetch, Live Inventory Feedback, Customer Intelligence API, Smart Performance (lazy loading, caching), Safety Hardening.
    - **Enhancements:** Receipt improvements, Kitchen Routing, Dashboard enhancements, Per-Item Discount Engine, Performance & UX.
    - **Polish:** Role Control (cashier/manager discount limits), Profit Engine, Receipt Control, Customer Intelligence (history API), KDS Improvements, Mobile Optimization.
    - **Production Launch Prep:** DB indexes for restaurant tables, immutable audit logging (order create/pay/discount/settings), low-stock ingredient popup on POS screen, input validation hardening, DatabaseBackup artisan command.
    - **Enterprise POS Upgrade:** Direct POS login (cashier to POS, admin to dashboard), full keyboard system (F5 hold, F8 pay, 1/2 cash/card, +/-/Delete cart, auto-focus search with type-to-filter), clean premium UI (full-width search, letter fallback for no-image, Rs.0 product filter, recipe emoji badge), keyboard hints on all buttons.
    - **Customer Address System:** Lightweight customer management with address storage for fast delivery workflows. Search by phone/name, auto-create on first order, address auto-fill on repeat orders. Stats (total orders, total spent) computed from completed orders only. Customer info bar in cart shows name, phone, address, and stats. Duplicate phone detection with auto-attach.

**FBR POS Module:**
- **Isolated FBR-integrated POS:** Accessible at `/fbr-pos` with direct FBR API submission, separate from PRA POS.
- **FBR Reporting Toggle:** Admin-only toggle to control FBR reporting (ON/OFF) with corresponding invoice prefixes (`FPOS-` or `FLOCAL-`).
- **Local Tabs & Confidential PIN System:** PIN-protected access to local invoice data and details.

**UI/UX Design:**
- **Layout:** Responsive sidebar with a single scrollable content area.
- **Styling:** Consistent dark/light modes, standardized components, emerald-600 primary color palette.
- **Design System:** Unified SaaS-grade design with consistent card, button, and section styling.
- **Product Visual Separation:** DI (emerald), NestPOS (purple), FBR POS (blue) themes.
- **Mobile Responsiveness:** Fully responsive (320px+), including sticky PAY button, safe-area padding, and responsive data tables.
- **Enterprise UX Engine:** Toast notifications, loading spinners, page transitions, auto-scrolling to errors.

## External Dependencies
- **PostgreSQL:** Primary database.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Frontend styling.
- **Alpine.js:** Interactive frontend components.
- **Chart.js:** Data visualization.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.
- **Unsplash / Picsum:** (Fallback) for `ProductImageService`.