# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform for tax and invoice management in Pakistan, ensuring compliance with FBR regulations. It provides Smart Invoicing, configurable governance, enterprise API, PDF generation, and a demo mode. The "Heavy Enterprise" version includes a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced security. The project targets a high-volume market with competitive pricing and a 14-day free trial.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo/internal) - NTN: 3620291786117, Owner: ZIA UR REHMAN (Digital Invoice ONLY, NO POS data)
- NestPOS Enterprise Store (company_id 11) is the dedicated POS company — completely separate from Digital Invoice
- POS Admin: posadmin@taxnest.com / Admin@12345 (company_id 11, NestPOS Enterprise Store)
- Digital Invoice and POS data are FULLY ISOLATED — no cross-contamination
- Login supports: Email, Phone, Username, CNIC, NTN
- CNIC/NTN login maps to company_admin user of matching company

## System Architecture
TaxNest is built on Laravel 12 with PHP 8.4, utilizing Breeze for authentication. The frontend employs Tailwind CSS, Alpine.js, and Chart.js. PostgreSQL is the chosen database.

**Core Architectural Patterns and Decisions:**
- **Multi-tenancy:** Achieved via `company_id` and `CompanyIsolation` middleware.
- **Role-Based Access Control (RBAC):** `RoleMiddleware` manages permissions.
- **Dual Invoice Numbering:** Separate internal and FBR invoice numbers.
- **Dynamic Validation Engine:** `ScheduleEngine` resolves FBR compliance rules.
- **Immutable Audit Logging:** Critical events are logged with SHA256 hashes.
- **Queue-based Processing:** Background jobs use a database queue.
- **Company Approval Workflow:** Manages company status and lifecycle.
- **Customer Ledger System:** Automates debit entries and allows manual adjustments.
- **Multi-Branch System:** Supports multiple operational branches per company.
- **FBR Token Health Monitoring:** Tracks FBR token status and connectivity.
- **Enterprise Analytics:** Provides KPIs and compliance metrics through dashboards.
- **Security Hardening:** Implements `ForceHttps` and subscription-based access controls.
- **Dynamic FBR Compliance:** Supports FBR Excel template alignment, PRAL API integration, per-item FBR fields, and pre-submission payload validation including a validate-only sandbox mode.
- **CRITICAL 3rd Schedule Rule (Verified Feb 2026):** For 3rd Schedule items, `fixedNotifiedValueOrRetailPrice` MUST be TOTAL (MRP × quantity), NOT per-unit. `salesTaxApplicable = round(retailPriceTotal × rate / 100, 2)`. Rate format MUST be string ending with "%" (e.g., "5%"). Duplicate submission guard: blocks if fbr_invoice_number exists, status is locked/pending_verification, or fbr_logs has prior success.
- **Enterprise Scoped Idempotency Shield (6-Phase):** (1) Duplicate checks scoped to invoice_id ONLY — no cross-invoice blocking. (2) Service-level Exception for locked/pending_verification/fbr_invoice_number/success_log. (3) `DB::transaction` + `lockForUpdate()` for atomic submission with race-condition double-check. (4) Submission hash = SHA256(invoice_id + invoiceRefNo), stored per-invoice (no global uniqueness). (5) invoiceRefNo is deterministic: CompanyNTN + "DI" + internal_invoice_number. (6) Global payload hash blocking removed — different invoices with identical data always allowed.
- **Enterprise UX Simplification (Feb 2026):** Invoice lifecycle has 4 states: `draft`, `failed`, `locked`, `pending_verification`. The `submitted` status is removed. On FBR submit: status stays `draft` with `is_fbr_processing=true` flag. On FBR success: `status=locked, fbr_status=production`. On validation error: `status=failed, fbr_status=validation_failed`. On FBR 500/ambiguous: `status=pending_verification`. On FBR failure: `status=failed, fbr_status=failed`. `pending_verification` must NOT auto-revert to draft. Failed invoices can be edited and resubmitted.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, and dynamic validation for different tax schedules.
- **Advanced HS Intelligence Engine:** Utilizes `hs_intelligence_logs` and `hs_rejection_history` for weighted suggestions and a 6-factor model.
- **Live Rejection Learning Engine:** Feeds FBR rejection data into intelligence for confidence adjustments.
- **HS Usage Patterns Learning Engine:** Tracks HS code patterns with confidence scoring based on FBR success/rejection.
- **Smart Invoice Memory Suggestions:** Provides community pattern suggestions for HS codes on invoice creation.
- **HS Code Mapping Engine:** Admin-managed `hs_code_mappings` table with sale type, SRO, serial number, tax rate, MRP, buyer type, and priority per HS code. Multiple mappings per HS code. Real-time suggestions on invoice create page (company can accept or enter custom values). Response tracking in `hs_mapping_responses` table for analytics.

- **Admin Announcement System:** Admin can create/manage announcements (info, warning, urgent, success) targeting all or specific companies. Dismissable banners on company dashboard.
- **Collapsible Sidebar Navigation:** Alpine.js collapse plugin for collapsible Business, Reports, Management, Inventory, Admin sections with localStorage persistence.
- **Admin Revenue Dashboard:** Top companies by revenue, monthly revenue chart (Chart.js), expiring trial warnings, today's activity metrics, activity feed timeline from audit logs.
- **Admin Invoice Override:** Super admin can search invoices and manually lock/unlock or update FBR status with full audit logging.
- **Dashboard Quick Actions:** Shortcut grid on company dashboard for common tasks (New Invoice, View Invoices, Add Product, Add Customer, Reports, FBR Status).
- **NestPOS Module (Fully Separated):** Complete Point of Sale system with PRA (Punjab Revenue Authority) integration. **Completely isolated from FBR Digital Invoicing** — separate auth guard (`pos`), separate layout (`pos-app.blade.php`), separate sidebar (`pos-navigation.blade.php`), separate login/register routes (`/pos/login`, `/pos/register`), and dedicated landing page (`/pos`). POS section removed from Digital Invoice sidebar. Auth handled by `PosAuthController` with `PosAuth` middleware. Layout component: `<x-pos-layout>`. Purple theme (#7c3aed) vs Digital Invoice emerald (#059669). Includes POS billing screen, discount system (percentage/amount), payment-method-based tax calculation (Cash 16%, Card 5%), PRA reporting toggle, receipt printing, services management, transaction history, and POS reports. PRA API v1.2 compliant (PRAL IMS component). **POS Data Isolation:** POS Products (`pos_products` table, `PosProduct` model) and POS Customers (`pos_customers` table, `PosCustomer` model) are completely separate from Digital Invoice `products` and `customer_profiles` tables. Full CRUD: add/edit/delete/toggle for both. Company-scoped validation on invoice item_id ensures cross-company isolation. POS invoice creation loads only PosProduct/PosCustomer data. Tables: pos_terminals, pos_services, pos_products, pos_customers, pos_transactions, pos_transaction_items, pos_payments, pos_tax_rules, pra_logs. PRA settings per company: pra_reporting_enabled, pra_environment (sandbox/production), pra_pos_id, pra_production_token. **PRA Invoice Enhancements:** Dual invoice numbering (POS Invoice # USIN + PRA Fiscal Invoice #). POS invoice format: `POS-YYYY-NNNNN` (per company, yearly sequence). PRA statuses: pending/submitted/failed/offline/local. PRA retry from Transaction Detail page. Duplicate submission protection via `submission_hash` and `pra_invoice_number` check. Invoice saved locally even if PRA fails. **Offline Billing + Auto Sync:** When PRA API/internet unavailable, invoices saved with `pra_status=offline` and auto-sync every 2 minutes via `SyncPosOfflineInvoicesJob`. When `pra_reporting_enabled=false`, invoices saved as `pra_status=local` — permanently local, never synced. Receipts show: PRA FISCAL INVOICE with QR (submitted), OFFLINE INVOICE (offline), LOCAL INVOICE - Not reported to PRA (local). **Tax Reporting System (CSV + PDF Export):** Dedicated `/pos/tax-reports` page with comprehensive filters (date range, weekly/monthly/today period, customer, payment method, tax rate 5%/16%/all). Report table with 13 columns (POS Invoice #, PRA Fiscal #, Date, Customer, Payment Method, Subtotal, Discount, Taxable Amount, Tax %, Tax Amount, Total, Terminal, PRA Status). Summary KPI cards and footer totals matching filtered dataset. CSV export (Excel-compatible with BOM) and PDF export (dompdf, landscape A4, professional table layout with company name, report title, date range, totals). Routes: `pos.tax-reports`, `pos.tax-reports.csv`, `pos.tax-reports.pdf`.

**Key Features:**
- **Smart Invoice Builder:** Guided invoice creation with auto-calculations and pre-submission compliance scoring.
- **FBR-compliant PDF Generation:** Includes QR data and watermarks.
- **Admin Company Deep View:** Comprehensive oversight for super administrators.
- **Risk Intelligence Engine:** Pre-submission risk detection and anomaly logging.
- **SRO Suggestion System:** Non-mandatory autofill with confidence-based suggestions.
- **Enhanced Compliance Scoring:** Formula-based scoring incorporating various risk factors. Post-FBR validation clears false-positive structural flags (RATE_MISMATCH, BUYER_RISK, STRUCTURE_ERROR) when invoice is locked/production with FBR invoice number. UI shows "FBR VALIDATED — Structural Compliance Confirmed" badge.
- **Custom Billing Plan Builder:** Admin-only dynamic pricing calculator for subscriptions.
- **Customer Registered/Unregistered Toggle:** Manages customer registration types with conditional form fields.
- **Products Page Upgrade:** Enhanced product management with `serial_number`, `mrp`, and dynamic tax calculation previews based on schedule types.
- **FBR API v1.12 Compliance:** Ensures adherence to FBR API specifications, including per-unit item values, specific formatting for SROs and invoice numbers, and robust pre-submission validation.
- **FBR Real-Time & Profile Session:** Synchronous FBR submission with immediate responses and comprehensive post-processing.
- **Enterprise Simplified Premium Mode (9-Phase UI Overhaul):** Focuses on a clean, solid UI with minimal glass effects, refined KPI cards, zebra-striped tables, and improved PWA functionality.

**UI/UX Design:**
- **Layout:** Responsive sidebar (fixed on desktop, slide-in on mobile) and a single scrollable content area.
- **Styling:** Consistent use of `bg-white dark:bg-gray-900` for cards and headers, `bg-gray-50 dark:bg-gray-950` for main content.
- **Components:** Standardized card and table patterns, minimal animations, and specific color palette (emerald-600 primary).
- **PWA Enhancements:** Install popup, offline badge, update banner, and manifest shortcuts.
- **Mobile Responsiveness:** Hamburger menu for sidebar, `overflow-x-auto` for tables, and sticky invoice summary.
- **Enterprise UX Engine:** Includes toast notifications, loading spinners, page fade transitions, auto-scroll to errors, and auto-loading on form submissions.
- **Keyboard Shortcuts:** Implemented for invoice creation (Ctrl+S, Ctrl+Enter, ESC, Enter).
- **Intelligent Autofocus:** Automatically advances input fields on invoice creation.
- **Sticky Bottom Summary:** Displays financial totals and FBR environment badge.

- **SaaS Management Layer (Fully Separated):** Complete admin and franchise management system. **Completely isolated from FBR Digital Invoicing and NestPOS** — separate auth guards (`admin`, `franchise`), separate layout components (`<x-admin-layout>`, `<x-franchise-layout>`), separate login routes (`/admin/login`, `/franchise/login`). Tables: `admin_users`, `franchises`, `admin_audit_logs`, `company_usage_stats`, `system_controls`, `subscription_invoices`, `subscription_payments`. Companies table extended with `status` (approved/pending/suspended/rejected) and `franchise_id` FK. Pricing plans extended with `max_terminals`, `max_users`, `max_products`, `inventory_enabled`, `reports_enabled`. **Super Admin Panel (`/admin/*`):** Dark indigo theme, KPI dashboard (companies, subscriptions, POS revenue, today's activity), company management with approval/reject/suspend/activate workflow, subscription plan builder, subscription assignment, franchise CRUD, company usage monitoring, system control toggle panel, admin audit log viewer. Controllers in `app/Http/Controllers/SaasAdmin/`. Admin credentials: admin@taxnest.com (seeded in migration). **Franchise Portal (`/franchise/*`):** Teal theme, franchise dashboard with KPIs, company list (franchise-scoped), subscription viewer, revenue analytics with Chart.js and commission calculation. Controllers in `app/Http/Controllers/Franchise/`. **Middleware:** `AdminAuth` (admin guard), `FranchiseAuth` (franchise guard), `CheckPlanLimit` (resource limits by plan), `CheckCompanyApproval` (blocks suspended/rejected companies). Company approval middleware wired into main company routes. **Models:** `AdminUser` (Authenticatable), `Franchise` (Authenticatable), `AdminAuditLog`, `CompanyUsageStat`, `SystemControl` (cached), `SubscriptionInvoice`, `SubscriptionPayment`.

## External Dependencies
- **PostgreSQL:** Main relational database.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** For user authentication scaffolding.
- **Tailwind CSS:** For styling and responsive design.
- **Alpine.js:** For interactive frontend components.
- **Chart.js:** For data visualization in dashboards.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.