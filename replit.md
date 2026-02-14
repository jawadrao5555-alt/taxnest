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
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, and dynamic validation for different tax schedules.
- **Advanced HS Intelligence Engine:** Utilizes `hs_intelligence_logs` and `hs_rejection_history` for weighted suggestions and a 6-factor model.
- **Live Rejection Learning Engine:** Feeds FBR rejection data into intelligence for confidence adjustments.
- **HS Usage Patterns Learning Engine:** Tracks HS code patterns with confidence scoring based on FBR success/rejection.
- **Smart Invoice Memory Suggestions:** Provides community pattern suggestions for HS codes on invoice creation.

**Key Features:**
- **Smart Invoice Builder:** Guided invoice creation with auto-calculations and pre-submission compliance scoring.
- **FBR-compliant PDF Generation:** Includes QR data and watermarks.
- **Admin Company Deep View:** Comprehensive oversight for super administrators.
- **Risk Intelligence Engine:** Pre-submission risk detection and anomaly logging.
- **SRO Suggestion System:** Non-mandatory autofill with confidence-based suggestions.
- **Enhanced Compliance Scoring:** Formula-based scoring incorporating various risk factors.
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