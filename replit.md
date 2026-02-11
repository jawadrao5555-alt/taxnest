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
- **Risk Intelligence Engine:** Pre-submission risk detection with 6 detection types (HS/tax mismatch, reduced 3rd Schedule without SRO, missing MRP, zero-rated domestic anomaly, invoice spikes >3x average, price deviation >40%). Risk scoring 0-100 with Safe/Review/High/Critical levels. Critical-level submission blocking with internal company bypass. Idempotent anomaly logging only on submit/job paths (not on read-only views).
- **SRO Suggestion System:** Non-mandatory autofill with confidence-based suggestions (high/medium/low), integrated with HS lookup for 3rd Schedule, Exempt, and Zero Rated items via `/api/sro-suggest`.
- **Enhanced Compliance Scoring:** Formula: `finalScore = base - anomalyWeight - vendorWeight + stabilityBonus`. Anomaly weight from unresolved anomaly logs (max 30), vendor weight from risky vendor profiles (max 20), stability bonus from consistent performance (max 10).
- **Intelligence Processing:** Async queue-based processing via `IntelligenceProcessingJob`, vendor risk tracking, and compliance recalculation.
- **Dashboard Intelligence Panels:** Audit probability with 6 factors (compliance score, anomalies, critical/high risk reports, risky vendors, severity bonuses), compliance formula breakdown, and company-wide risk summary with severity breakdown.
- **Multi-Layer Tax Intelligence:** 5-table override architecture (sector_tax_rules, province_tax_rules, customer_tax_rules, special_sro_rules, override_usage_logs) with priority: manual > customer > province > sector > global. TaxResolutionService resolves overrides with logging. Dynamic standard_tax_rate per company (no hardcoded rates).
- **Tax Override Management:** Full CRUD admin panel (`/tax-overrides`) with tabbed interface for sector, province, customer, and SRO rules. Role-based access: super_admin manages all rule types, company_admin manages customer-specific rules only. Company isolation enforced on all customer rule operations.
- **Override Analytics:** Admin dashboard includes Tax Intelligence stats (active rules count, total/monthly usage). Enhanced override-logs page with dual-tab view for MIS overrides and tax intelligence usage with layer distribution breakdown.

**FBR Excel Template + PRAL API Alignment (Feb 2026):**
- **Document Types:** Support for Sale Invoice (default), Credit Note, and Debit Note with reference invoice tracking for CN/DN.
- **Company-Wise Continuous Numbering:** `InvoiceNumberingService` generates `COMPANY_PREFIX-000001` format numbers using DB-level `lockForUpdate()` in transactions. No yearly/branch/environment resets. Fields: `invoice_number_prefix`, `next_invoice_number` on companies table.
- **Province Mapping:** `supplier_province` (auto from branch/company), `destination_province` (user-selected). 8 Pakistan provinces/territories.
- **WHT Calculations:** Manual `wht_rate` selection, `wht_amount = total_value_excluding_st × (wht_rate/100)`, `net_receivable = total_amount - wht_amount`. All displayed in create/edit/show/PDF views.
- **Buyer Registration Type:** Auto-detected from NTN format (7+ digits = Registered, else Unregistered). Stored per invoice.
- **Per-Item FBR Fields:** `st_withheld_at_source` (boolean), `petroleum_levy` (decimal) on invoice_items. Included in FBR payload.
- **FBR Status Tracking:** Separate `fbr_status` field (pending/submitted/validated/failed) alongside existing `status` (Draft/Locked).
- **Enhanced FBR Payload:** `document_type` mapping, `referenceInvoiceNo` for CN/DN, `st_withheld_at_source` and `petroleum_levy` per item, invoice-level supplier/destination provinces.
- **Sandbox Test Panel:** 6 testing tools on FBR Settings page (Ping Endpoint, Validate Token, Test Payload Structure, Check Company Config, Dry Run Invoice, Province Mapping). Available only in Sandbox environment.
- **Production Confirmation:** Switching to Production environment requires typing "CONFIRM" to prevent accidental switches.
- **PDF Template:** Shows document type, WHT deduction, net receivable, SHA256 hash, buyer registration type, and province info.

**FBR Compliance Hardening (Feb 2026):**
- **Corrected FBR Payload Math:** `valueSalesExcludingST = quantity × unit_price`, `salesTaxApplicable = valueSalesExcludingST × (taxRate/100)`, `totalValues = valueSalesExcludingST + salesTaxApplicable`. Previously used unit_price only.
- **Dynamic Company Fields in Payload:** Seller province, business name, NTN, buyer registration type all sourced from company settings (no more hardcoded "Sindh"/"Karachi"). Buyer registration type auto-detected from NTN format.
- **UOM Enforcement:** `default_uom` and `sale_type` columns added to `invoice_items` table. UOM dropdown in create/edit forms with 10 options. UOM sent in FBR payload per item.
- **SaleType Mapping:** `ScheduleEngine::mapSaleType()` maps schedule types to FBR sale type strings (e.g., "Goods at standard rate", "Exempt goods", etc.).
- **Exempt Schedule Fix:** Exempt items now require SRO Schedule No only (serial_no not required per FBR spec). Updated in ScheduleEngine backend and Alpine.js frontend.
- **FBR Token from Company Settings:** Removed hardcoded "YOUR_SANDBOX_TOKEN". FbrService now reads encrypted tokens from company's `fbr_sandbox_token` or `fbr_production_token` based on `fbr_environment` setting.
- **Pre-submission Payload Validator:** `ScheduleEngine::validateFbrPayload()` checks all required FBR fields (seller/buyer data, item fields, quantity/value constraints, exempt tax consistency) before sending to FBR.
- **Validate-Only Sandbox Mode:** `FbrService::validateOnly()` tests payload against FBR sandbox validation endpoint without submitting. Available only in sandbox environment. Accessible via "Validate FBR Payload" button on invoice detail page.
- **HS Code Search:** Invoice index search now includes HS code matching across invoice items via `orWhereHas`.
- **Manual Override Audit Logging:** Tax rate, SRO, and MRP overrides are logged via `AuditLogService` during invoice creation/update with user attribution.
- **FBR Settings UI Enhancement:** Added "Sandbox Test Mode" info section and environment badge (amber/red) on Token Health panel.
- **FBR Value Breakdown:** Invoice create/edit forms show "FBR Value (qty × price)" and "FBR Tax (value × rate%)" per item for transparency.

**Global HS Intelligence Control System (Feb 2026):**
- **global_hs_master table:** Centralized HS code repository with hsCode (unique), description, pctCode, scheduleType, taxRate, defaultUom, sroRequired, sroNumber, sroItemSerialNo, mrpRequired, sectorTag, riskWeight, mappingStatus (Mapped/Partial/Unmapped), created_by, updated_by, timestamps. Seeded from ScheduleEngine, products, SRO rules, and invoice_items.
- **hs_unmapped_log table:** Tracks unmapped HS codes per company with frequency_count, first_seen_at, last_seen_at. Auto-populated when HS codes not found in global master during invoice creation/update.
- **GlobalHsService:** Resolves HS codes first from global_hs_master, then ScheduleEngine fallback. Logs unmapped codes automatically. Provides SRO suggestions with confidence scoring (non-destructive). Seed utility aggregates from all existing data sources.
- **Invoice Flow Integration:** InvoiceController store/update methods call GlobalHsService::resolveForInvoiceItem() for auto-mapping of pct_code and default_uom. Unmapped HS codes logged transparently without blocking.
- **Admin Panel (/admin/hs-master):** Three-tab interface (All HS, Unmapped HS, Intelligence Insights). Full CRUD for HS codes, inline editing, quick-map for unmapped codes, sync-from-sources button. Super_admin only.
- **Validation Engine Upgrade:** 3rd Schedule <18% requires SRO+Serial+MRP; =18% requires MRP only; Exempt requires SRO; Zero Rated requires nothing. Dynamic 0-18 tax rate support.
- **Sector Mapping Layer:** sectorTag on global_hs_master. Priority: Manual > Customer > Province > Sector > Global (via TaxResolutionService).
- **Smart SRO Suggestion:** Non-destructive confidence scoring from GlobalHsService::suggestSro(). Sources: global_hs_master first, SroSuggestionService fallback. No auto-override.
- **Search Upgrade:** API at /api/hs-search supports search by HS code, description, schedule, sector, SRO, taxRate, with optional usage frequency. Admin panel has multi-filter search.

## External Dependencies
- **PostgreSQL:** Primary database managed by Replit.
- **FBR (Federal Board of Revenue) Pakistan:** Integration for tax and invoice submission compliance.
- **Laravel Breeze:** Provides basic authentication scaffolding.
- **Tailwind CSS:** Utility-first CSS framework for styling.
- **Alpine.js:** Lightweight JavaScript framework for interactive UI components.
- **Chart.js:** JavaScript charting library for data visualization in analytics dashboards.