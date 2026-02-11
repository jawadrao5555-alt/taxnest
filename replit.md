# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS tax and invoice management system for Pakistan, designed to ensure compliance with FBR (Federal Board of Revenue) regulations. It offers robust features including Smart Invoicing, a product master, preview/validation flows, dual PRAL submission modes, QR locking, MIS reporting, trend analytics, configurable governance, enterprise API endpoints, PDF generation, and a demo mode.

The "Heavy Enterprise" version extends these capabilities with a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced Security Hardening. The project aims to capture a high-volume market with aggressive pricing tiers (Retail, Business, Industrial, Enterprise) and a 14-day free trial.

## User Preferences
N/A

## System Architecture
TaxNest is built on **Laravel 12** with **Breeze** for authentication, using **PHP 8.4**. The frontend utilizes **Tailwind CSS**, **Alpine.js**, and **Chart.js** for a modern UI/UX. **PostgreSQL** serves as the database.

**Core Architectural Patterns and Decisions:**
- **Multi-tenancy:** Implemented through a `company_id` on most tables and `CompanyIsolation` middleware to ensure data segregation and access control for different companies.
- **Role-Based Access Control (RBAC):** `RoleMiddleware` enforces permissions for `super_admin`, `company_admin`, `employee`, and `viewer` roles.
- **Dual Invoice Numbering:** `internal_invoice_number` for system tracking and `fbr_invoice_number` for FBR submissions, supporting independent management and prominent display of FBR numbers when available.
- **Dynamic Validation Engine:** A `ScheduleEngine` resolves validation rules based on `scheduleType` and `taxRate` for FBR compliance, dynamically adjusting frontend fields and blocking submissions if requirements are not met.
- **Immutable Audit Logging:** Critical system events are logged in an `audit_logs` table with SHA256 hashes for tamper detection, ensuring data integrity and providing a verifiable audit trail.
- **Queue-based Processing:** Background jobs (e.g., `SendInvoiceToFbrJob`, `ComplianceScoringJob`) are handled via a database queue driver for asynchronous operations and improved performance.
- **Company Approval Workflow:** A `company_status` field (pending/active/suspended/rejected) and associated middleware manage company lifecycle from self-registration to admin approval.
- **Customer Ledger System:** Automates debit entries upon invoice locking and allows manual payment/adjustment entries, maintaining running balances per customer.
- **Multi-Branch System:** Supports multiple branches per company, enabling branch-specific invoice creation, reporting, and management.
- **FBR Token Health Monitoring:** Tracks `token_expiry_date`, `last_successful_submission`, and `fbr_connection_status`, with automated notifications for expiring tokens.
- **Enterprise Analytics:** Dashboards provide KPIs like top 5 customers, branch comparison, compliance percentage, average invoice value, and rejection rates, offering role-based views (Retail, Business, Enterprise).
- **Security Hardening:** Includes `ForceHttps` middleware, FBR submission blocking for expired subscriptions, and status banners for suspended/pending companies.

**Key Features:**
- **Smart Invoice Builder:** Guided invoice creation with product dropdowns, auto-calculations, and branch selection.
- **Hybrid Compliance Scorer:** Validates invoices against FBR rules before submission, generating risk scores.
- **PDF Generation:** Generates FBR-compliant PDFs with QR data, draft watermarks, and proper filenames.
- **Admin Company Deep View:** Comprehensive overview for super admins with financial, compliance, and activity tabs (view-only).

## External Dependencies
- **PostgreSQL:** Primary database managed by Replit.
- **FBR (Federal Board of Revenue) Pakistan:** Integration for tax and invoice submission compliance.
- **Laravel Breeze:** Provides basic authentication scaffolding.
- **Tailwind CSS:** Utility-first CSS framework for styling.
- **Alpine.js:** Lightweight JavaScript framework for interactive UI components.
- **Chart.js:** JavaScript charting library for data visualization in analytics dashboards.