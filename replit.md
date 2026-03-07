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
- POS login page has "SaaS Admin Login" button for easy admin access
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
- **NestPOS Module:** A completely isolated Point of Sale system with PRA integration, separate authentication, layouts, and data models. Supports offline billing with auto-sync, dual invoice numbering (POS and PRA Fiscal), comprehensive tax reporting with CSV/PDF export, business profile management with logo upload (printed on receipts), and user profile with password change.
- **SaaS Management Layer:** A fully separated admin and franchise management system with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
- **Dynamic Landing Pages:** Three separate landing pages with product-isolated login:
  - `/` — Main TaxNest landing (product overview, features, pricing overview, FAQ, contact — NO login buttons in nav)
  - `/digital-invoice` — Dedicated DI landing page (FBR features, billing cycles, pricing plans, login modal, sign up)
  - `/pos` — Dedicated NestPOS landing page (PRA features, annual pricing, login modal, POS sign up)
  - `/di` — Redirects to `/digital-invoice`
  - `/login` — Renders DI landing with login modal auto-open (Laravel auth redirect target)
  - Navigation flow: Main landing nav has flat links (Digital Invoice→/digital-invoice, PRA POS→/pos, Pricing, Features, Contact); each product page has its own login modal + sign up + "< Home" back link
- **Admin Plan Management:** SaaS admin can edit all plan details inline (name, price, limits, features) at `/admin/plans`. Changes auto-reflect on all landing and billing pages since they read from `pricing_plans` table.
- **Product-Type Plan Separation:** `pricing_plans` table has a `product_type` column (`di` or `pos`). DI landing (`/digital-invoice`) shows only `product_type='di'` plans (Retail, Business, Industrial, Enterprise with monthly prices). POS landing (`/pos`) shows only `product_type='pos'` plans (Starter Rs 9,999/yr, Business Rs 14,999/yr, Pro Rs 24,999/yr with annual prices stored directly in `price` field).

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
- **Design System:** Unified SaaS-grade design: Cards use `rounded-xl shadow-md` with `hover:-translate-y-1 hover:shadow-xl transition-all duration-300`. Buttons use `rounded-lg font-semibold` with gradient fills. Section padding `py-24 lg:py-28` minimum.
- **Product Visual Separation:** Digital Invoice uses emerald theme (`from-emerald-500 to-emerald-700`), NestPOS uses purple theme (`from-purple-500 to-purple-700`). Gradient CTA buttons with `shadow-md hover:shadow-lg`.
- **Navigation:** All landing pages use visible wrapping nav (NO hamburger menu). Nav items wrap into multiple lines on mobile using `flex-wrap`. Text scales down on smallest screens (`text-[10px] sm:text-[13px]`).
- **PWA Enhancements:** Install prompts, offline badges, update banners, and manifest shortcuts.
- **Mobile Responsiveness:** Fully responsive at 320px+. `overflow-x-hidden` on `<html>` and `<body>` prevents horizontal scroll. Landing pages stack cards vertically on mobile. POS sidebar uses slide-out drawer. All data tables use progressive column hiding (`hidden sm/md/lg:table-cell`) for essential-first display. Inline edit forms use responsive grids (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-4`) instead of fixed widths. All tables have `overflow-x-auto`. Header rows stack on mobile with `flex-col sm:flex-row`. Buttons use `w-full sm:w-auto` for full-width on mobile. Section padding uses `px-3 sm:px-5 md:px-8`.
- **Enterprise UX Engine:** Includes toast notifications, loading spinners, page transitions, and auto-scrolling to errors.
- **Keyboard Shortcuts:** Implemented for invoice creation.
- **Intelligent Autofocus:** Automatically advances input fields.
- **Sticky Bottom Summary:** Displays financial totals and FBR environment badge.
- **CSS Build:** Tailwind CSS is pre-compiled via Vite. After any blade template changes that use new Tailwind classes, must run `npx vite build` to regenerate `public/build/assets/app-*.css`.

## External Dependencies
- **PostgreSQL:** Primary database.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Frontend styling.
- **Alpine.js:** Interactive frontend components.
- **Chart.js:** Data visualization.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.