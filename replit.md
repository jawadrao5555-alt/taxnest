# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS tax and invoice management system for Pakistan, designed to ensure compliance with FBR (Federal Board of Revenue) regulations. It offers robust features including Smart Invoicing, configurable governance, enterprise API endpoints, PDF generation, and a demo mode. The "Heavy Enterprise" version extends these capabilities with a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced Security Hardening. The project aims to capture a high-volume market with aggressive pricing tiers and a 14-day free trial.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo/internal) - NTN: 3620291786117, Owner: ZIA UR REHMAN
- Login supports: Email, Phone, Username, CNIC, NTN
- CNIC/NTN login maps to company_admin user of matching company

## System Architecture
TaxNest is built on Laravel 12 with PHP 8.4, using Breeze for authentication. The frontend utilizes Tailwind CSS, Alpine.js, and Chart.js for a modern UI/UX. PostgreSQL serves as the database.

**Core Architectural Patterns and Decisions:**
- **Multi-tenancy:** Implemented through `company_id` and `CompanyIsolation` middleware.
- **Role-Based Access Control (RBAC):** `RoleMiddleware` enforces permissions for various user roles.
- **Dual Invoice Numbering:** Separate `internal_invoice_number` and `fbr_invoice_number`.
- **Dynamic Validation Engine:** `ScheduleEngine` resolves FBR compliance rules based on `scheduleType` and `taxRate`.
- **Immutable Audit Logging:** Critical events logged with SHA256 hashes for tamper detection.
- **Queue-based Processing:** Background jobs handled via a database queue.
- **Company Approval Workflow:** Manages company lifecycle with `company_status`.
- **Customer Ledger System:** Automates debit entries and allows manual adjustments.
- **Multi-Branch System:** Supports multiple branches per company.
- **FBR Token Health Monitoring:** Tracks token expiry, submission status, and connection.
- **Enterprise Analytics:** Dashboards provide key performance indicators (KPIs) and compliance metrics.
- **Security Hardening:** Includes `ForceHttps` middleware and subscription-based access controls.

**Key Features:**
- **Smart Invoice Builder:** Guided invoice creation with auto-calculations.
- **Hybrid Compliance Scorer:** Validates invoices against FBR rules pre-submission.
- **PDF Generation:** FBR-compliant PDF generation with QR data and watermarks.
- **Admin Company Deep View:** Comprehensive overview for super admins.
- **Risk Intelligence Engine:** Pre-submission risk detection, blocking, and anomaly logging.
- **SRO Suggestion System:** Non-mandatory autofill with confidence-based suggestions and HS lookup.
- **Enhanced Compliance Scoring:** Formula-based scoring incorporating anomalies, vendor risk, and stability.
- **Intelligence Processing:** Asynchronous queue-based processing for vendor risk and compliance.
- **Dashboard Intelligence Panels:** Audit probability, compliance formula breakdown, and company-wide risk summary.
- **Multi-Layer Tax Intelligence:** 5-table override architecture with priority: manual > customer > province > sector > global.
- **Tax Override Management:** Full CRUD admin panel with role-based access.
- **FBR Excel Template + PRAL API Alignment:** Supports Sale, Credit, Debit Notes, continuous numbering, province mapping, WHT calculations, buyer registration type, per-item FBR fields, and status tracking.
- **FBR Compliance Hardening:** Corrected FBR payload math, dynamic company fields, UOM enforcement, sale type mapping, encrypted FBR tokens, pre-submission payload validation, and a validate-only sandbox mode.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, unmapped HS tracking, `GlobalHsService` for HS resolution, admin panel for CRUD and quick-mapping, dynamic validation for 3rd Schedule, Exempt, and Zero Rated items, sector mapping layer, and smart SRO suggestions.
- **Advanced HS Intelligence Engine:** `hs_intelligence_logs` for weighted suggestions, `hs_rejection_history` for tracking admin rejections, `HsIntelligenceService` with a 6-factor model for suggestions, and an upgraded admin panel for managing unmapped HS codes.
- **Live Rejection Learning Engine:** Captures FBR rejection data for HS codes, feeds into intelligence for confidence deduction, and provides admin alerts for top rejected HS codes.
- **UI/UX Improvements:** FBR settings redesign with sandbox/production selection, customer profile management, invoice customer/product autocomplete, invoice list tabs, products page upgrade with search and HS mapping, dashboard compactness, separated client and super admin dashboards, premium dashboard UI overhaul, and an SRO & Serial Number Reference System with seeded rules and search functionality.
- **QR Code FBR Compliance:** Server-side QR data encoding with 4 FBR-required fields (sellerNTNCNIC, fbr_invoice_number, invoiceDate, totalValues). QR displayed in both PDF templates when fbr_invoice_number exists.
- **Custom Billing Plan Builder:** Admin-only dynamic pricing calculator with sliders for invoice limit, user count, branch count. Pricing formula: invoiceFactor=2.5, userFactor=500, branchFactor=1000 per month. Cycle discounts: quarterly 1%, semi-annual 3%, annual 6%. Creates PricingPlan and Subscription records.
- **HS Usage Patterns Learning Engine:** `hs_usage_patterns` table tracks HS code patterns with confidence scoring (success*5 - rejection*10, cap 95%). Auto-records on FBR success/rejection via SendInvoiceToFbrJob. Only surfaces suggestions with admin_status='approved' and confidence >= 60%.
- **Smart Invoice Memory Suggestions:** Community Pattern Suggestion panel on invoice create page. Fetches suggestions via `/api/hs-usage-suggestions/{hsCode}` when HS code entered. One-click apply for schedule_type, tax_rate, SRO, serial_no. Never exposes internal confidence metrics.
- **Enterprise Simplified Premium Mode (9-Phase UI Overhaul):**
  - Clean solid backgrounds on all internal pages (no glass on tables/forms/settings)
  - Glass effects retained ONLY for: sidebar, landing page, login/register pages
  - KPI cards with simple border-t-2 color accents, gray icon containers
  - Zebra-striped tables, clean white cards with shadow-sm
  - PWA install popup shows once per user (localStorage check)
  - PDF: tri-color gradient header, zebra tables, gradient QR badge

## UI Architecture Notes
- **Layout**: Responsive sidebar — fixed visible on desktop (lg+), slide-in drawer on mobile (<lg). Body uses `h-screen overflow-hidden` for single scroll container.
- **Sidebar**: `fixed left-0 top-0 w-64 h-full`, uses `-translate-x-full lg:translate-x-0` for responsive behavior. Close button has `lg:hidden`. Solid `bg-white dark:bg-gray-900`.
- **Content Wrapper**: `lg:ml-64 flex flex-col h-full w-full` — offset on desktop, full width on mobile.
- **Header**: `sticky top-0 z-30 bg-white dark:bg-gray-900 border-b`. Solid background, no backdrop-blur. Hamburger button has `lg:hidden`.
- **Main Content**: `flex-1 overflow-y-auto p-4 sm:p-6 bg-gray-50 dark:bg-gray-950` — only scrollable area.
- **Standard Card Pattern**: `bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800`
- **Standard Table**: `bg-gray-50 dark:bg-gray-800` header, `even:bg-gray-50/50` zebra rows, `hover:bg-gray-100` hover
- **Design Tokens**: `--card-radius: 12px`, `--card-shadow: subtle`. Minimal animations (fadeIn 4px, premium-hover translateY -1px).
- **Colors**: Primary emerald-600, Accent indigo-500, Danger red-500, Warning amber-500. No multi-gradient headers.
- **PWA**: Install popup (bottom-right, shows once via localStorage), offline badge (bottom-left small pill), update banner (full-width top bar).
- **Mobile**: Hamburger opens sidebar drawer. Overlay click closes it. Tables have overflow-x-auto. Invoice sticky summary functional on all sizes.

## Recent Changes (Feb 14, 2026)
**Customer Registered/Unregistered Toggle:**
- Customer create/edit forms now have explicit Registered/Unregistered card-style toggle
- Registered: NTN, CNIC required + phone, email, address shown
- Unregistered: Only name & address needed, other fields hidden
- registration_type stored directly from user selection (not auto-detected from NTN digits)
- Invoice create/edit: buyer_registration_type passed via hidden field, customer selection auto-sets registration type
- Customer search dropdown shows registration type badge (green/amber)
- Customer index badges updated to emerald (FBR Registered) and amber (Unregistered)
- InvoiceController uses explicit buyer_registration_type from form, falls back to NTN auto-detect for manual entries
- Profile page consolidated with business profile, FBR Settings remains separate

**Products Page Upgrade:**
- Added `serial_number` (varchar 100) and `mrp` (decimal 14,2) columns to products table
- Alpine.js dynamic form: MRP field appears for 3rd Schedule, SRO+Serial Number for reduced/exempt schedule types
- ScheduleEngine rules drive both frontend (Alpine.js) and backend (ProductController) validation consistently
- Tax calculation preview on create/edit: shows Price, Tax Rate, Tax Amount, Total (incl. Tax) per unit
- Products index shows Tax Amount, Total, MRP, and SRO+Serial columns
- ScheduleEngine::$scheduleTypes['3rd_schedule'] requires_mrp set to true for consistency
- Search API uses resolveValidationRules (not getScheduleConfig) for accurate field requirements

**FBR Real-Time & Profile Session:**
- FBR submission changed from async queue to synchronous real-time response (submitToFbrSync helper)
- Submit, Retry both now return immediate FBR result with execution time
- submitToFbrSync includes full post-processing: activity logging, audit logs, ledger entries, HS patterns, compliance recalculation, error handling with try/catch
- SendInvoiceToFbrJob retained as fallback but no longer called from main submit/retry flow
- Manual confirm (pending_verification) now accepts FBR invoice number input, saves it with QR data
- Confirmation dialogs removed from Submit to FBR, Retry, Resubmit buttons (production flow)
- Company Profile: registration_no, mobile, city, website fields added
- Invoice PDF: Email added to both templates, all fields conditional (show only when filled)
- Original PDF fields (Name, NTN, Address, Phone) always displayed as before

**Enterprise Upgrade Session:**
- Phase 1: Production Safety - exponential backoff (tries=3, backoff=[30,90,180]), lockForUpdate on FBR submission
- Phase 3: Security Hardening - SecurityHeaders middleware (CSP, X-Frame-Options, X-Content-Type-Options)
- Phase 4: Simplified Dashboard - Alpine.js "Advanced Insights" toggle, essential KPIs always visible
- Phase 5+6: UX Engine - toast notifications (Alpine.js, XSS-safe), btn-loading spinner, page-fade (150ms), auto-scroll to errors, auto-loading on form submit
- Phase 7: Performance - Tailwind CDN removed from landing/share pages, Vite-compiled assets
- Phase 8: PWA Enhancement - manifest shortcuts (Create Invoice, Dashboard, Customers), offline splash page (sw.js v5)
- Phase 10: Landing Page - "Why TaxNest" competitive comparison section (6 cards + comparison table), x-collapse replaced with x-transition, pricing x-data fix
- Stripe/payment gateway integration DEFERRED by user for later

## Changes (Feb 13, 2026)
**Phase A - Production Stability:**
- Database hardening: composite indexes on invoices (company_id+status+date), invoice_items (hs_code+invoice_id), fbr_logs (invoice_id+status)
- N+1 query elimination: DashboardController and AdminController use aggregated SELECT with conditional sums
- Queue safety: SendInvoiceToFbrJob with execution timing, cache-based duplicate dispatch prevention (fbr_dispatch_lock), failure categorization
- FBR observability: admin system-health page shows avg/min/max submission latency, success/failure/retry ratios (30-day window), failure category breakdown
- Safe caching: CacheService with TTL-based caching for pricing plans (5min), HS lookups (10min), provinces (1hr), dashboard counters (1min)
- Slow query logging: >300ms threshold with route tracking in AppServiceProvider
- fbr_logs extended: environment_used, failure_category, submission_latency_ms columns

**Phase B - UX & Speed:**
- B1: Keyboard mode on invoice create - Ctrl+S and Ctrl+Enter save, ESC closes modals, Enter advances fields
- B2: Duplicate Invoice button on show page - copies header+items, resets FBR fields, new internal number, audit trail
- B3: CSV Bulk Import - CsvImportController with template download, row validation preview, batch draft creation grouped by buyer
- B4: Quick Product Create - inline modal on invoice create + API endpoint POST /api/products/quick-create
- B5: Intelligent Autofocus - HS lookup completion auto-focuses quantity field via data-field attributes
- B6: Sticky Bottom Summary - Subtotal, Tax, WHT, Grand Total, FBR environment badge (sandbox/production), keyboard shortcut hints

## External Dependencies
- **PostgreSQL:** Primary database.
- **FBR (Federal Board of Revenue) Pakistan:** Integration for tax and invoice submission compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Utility-first CSS framework.
- **Alpine.js:** Lightweight JavaScript framework.
- **Chart.js:** JavaScript charting library.