# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform for tax and invoice management in Pakistan, ensuring compliance with FBR regulations. It provides Smart Invoicing, configurable governance, enterprise API, PDF generation, and a demo mode. The "Heavy Enterprise" version includes a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced security. The project targets a high-volume market with competitive pricing and a 14-day free trial.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo/internal) - NTN: 3620291786117, Owner: ZIA UR REHMAN
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
- **NestPOS Module:** Complete Point of Sale system with PRA (Punjab Revenue Authority) integration. Isolated from FBR Digital Invoicing. Includes POS billing screen, discount system (percentage/amount), payment-method-based tax calculation (Cash 16%, Card 5%), PRA reporting toggle, receipt printing, services management, transaction history, and POS reports. PRA API v1.2 compliant (PRAL IMS component). Tables: pos_terminals, pos_services, pos_transactions, pos_transaction_items, pos_payments, pos_tax_rules, pra_logs. PRA settings per company: pra_reporting_enabled, pra_environment (sandbox/production), pra_pos_id, pra_production_token.

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

## External Dependencies
- **PostgreSQL:** Main relational database.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** For user authentication scaffolding.
- **Tailwind CSS:** For styling and responsive design.
- **Alpine.js:** For interactive frontend components.
- **Chart.js:** For data visualization in dashboards.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.