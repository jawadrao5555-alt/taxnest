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
- **Premium Enterprise Visual Standard (10-Phase UI Overhaul):**
  - Phase 1+2: Global Design System with CSS variables (--primary-gradient, --card-radius, --glass-bg), glass effects (backdrop-blur-xl), fadeIn/scaleIn animations, premium-hover utilities, sidebar active state with gradient background and 3px emerald left border
  - Phase 3: Dashboard KPI cards with gradient top borders (blue, emerald, purple, indigo, orange), gradient icon containers with shadow, premium-hover on all interactive cards
  - Phase 4: Invoice sticky bottom summary bar (backdrop-blur-xl, Subtotal/Tax/WHT/Grand Total), section cards with animate-fade-in, input focus ring opacity
  - Phase 5: PDF tri-color gradient header line, zebra striped tables, gradient QR badge, "Powered by TaxNest" footer
  - Phase 6: PWA with cache-first service worker, install popup (auto-appears after 2s), offline badge, update banner
  - Phase 7: Landing Page FAQ accordion (6 items, Alpine.js x-collapse), Custom Plan CTA button, FAQ nav link
  - Phase 8-10: Billing pages premium card styling with gradient top accents, rounded-2xl, animate-fade-in

## UI Architecture Notes
- **Layout**: Fixed sidebar (w-64, z-30, overflow-y-auto), sticky header (z-20), h-screen overflow-hidden body, flex h-full, ml-64 content
- **Glass Effects**: bg-white/80 backdrop-blur-xl on sidebar/header, border opacity /60 and /50
- **Design Tokens**: --primary-gradient (sky-indigo), --card-radius (16px), --card-shadow, --glass-bg, --hover-lift (translateY(-2px))
- **Animations**: fadeIn (translateY 6px), scaleIn (scale 0.97), premium-hover (lift+shadow), btn-premium (scale hover/active)
- **Sticky Summary**: Fixed bottom (lg:left-64), backdrop-blur-xl, financial summary + submit
- **PDF Premium**: Gradient header (green-indigo-blue), zebra stripes, gradient QR badge
- **PWA**: Install popup (bottom-right), offline badge (top-center amber), update banner (top-center indigo)

## External Dependencies
- **PostgreSQL:** Primary database.
- **FBR (Federal Board of Revenue) Pakistan:** Integration for tax and invoice submission compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Utility-first CSS framework.
- **Alpine.js:** Lightweight JavaScript framework.
- **Chart.js:** JavaScript charting library.