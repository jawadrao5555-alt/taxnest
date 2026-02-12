# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS tax and invoice management system for Pakistan, designed to ensure compliance with FBR (Federal Board of Revenue) regulations. It offers robust features including Smart Invoicing, product master, preview/validation flows, dual PRAL submission modes, QR locking, MIS reporting, trend analytics, configurable governance, enterprise API endpoints, PDF generation, and a demo mode.

The "Heavy Enterprise" version extends these capabilities with a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced Security Hardening. The project aims to capture a high-volume market with aggressive pricing tiers (Retail, Business, Industrial, Enterprise) and a 14-day free trial.

## User Preferences
N/A

## System Architecture
TaxNest is built on Laravel 12 with Breeze for authentication, using PHP 8.4. The frontend utilizes Tailwind CSS, Alpine.js, and Chart.js for a modern UI/UX. PostgreSQL serves as the database.

**Core Architectural Patterns and Decisions:**
- **Multi-tenancy:** Implemented through a `company_id` on most tables and `CompanyIsolation` middleware for data segregation.
- **Role-Based Access Control (RBAC):** `RoleMiddleware` enforces permissions for `super_admin`, `company_admin`, `employee`, and `viewer` roles.
- **Dual Invoice Numbering:** `internal_invoice_number` for system tracking and `fbr_invoice_number` for FBR submissions.
- **Dynamic Validation Engine:** A `ScheduleEngine` resolves validation rules based on `scheduleType` and `taxRate` for FBR compliance.
- **Immutable Audit Logging:** Critical system events are logged in an `audit_logs` table with SHA256 hashes for tamper detection.
- **Queue-based Processing:** Background jobs (e.g., `SendInvoiceToFbrJob`, `ComplianceScoringJob`) are handled via a database queue.
- **Company Approval Workflow:** A `company_status` field (pending/active/suspended/rejected) manages company lifecycle.
- **Customer Ledger System:** Automates debit entries upon invoice locking and allows manual payment/adjustment entries.
- **Multi-Branch System:** Supports multiple branches per company for invoice creation and reporting.
- **FBR Token Health Monitoring:** Tracks `token_expiry_date`, `last_successful_submission`, and `fbr_connection_status`.
- **Enterprise Analytics:** Dashboards provide KPIs like top 5 customers, branch comparison, compliance percentage, average invoice value, and rejection rates.
- **Security Hardening:** Includes `ForceHttps` middleware, FBR submission blocking for expired subscriptions, and status banners.

**Key Features:**
- **Smart Invoice Builder:** Guided invoice creation with product dropdowns, auto-calculations, and branch selection.
- **Hybrid Compliance Scorer:** Validates invoices against FBR rules before submission, generating risk scores (0-100).
- **PDF Generation:** Generates FBR-compliant PDFs with QR data, draft watermarks, and proper filenames.
- **Admin Company Deep View:** Comprehensive overview for super admins with financial, compliance, and activity tabs.
- **Risk Intelligence Engine:** Pre-submission risk detection with 6 types, critical-level submission blocking, and idempotent anomaly logging.
- **SRO Suggestion System:** Non-mandatory autofill with confidence-based suggestions (high/medium/low), integrated with HS lookup.
- **Enhanced Compliance Scoring:** Formula: `finalScore = base - anomalyWeight - vendorWeight + stabilityBonus`.
- **Intelligence Processing:** Async queue-based processing via `IntelligenceProcessingJob` for vendor risk tracking and compliance recalculation.
- **Dashboard Intelligence Panels:** Audit probability with 6 factors, compliance formula breakdown, and company-wide risk summary.
- **Multi-Layer Tax Intelligence:** 5-table override architecture (sector_tax_rules, province_tax_rules, customer_tax_rules, special_sro_rules, override_usage_logs) with priority: manual > customer > province > sector > global. Dynamic `standard_tax_rate` per company.
- **Tax Override Management:** Full CRUD admin panel (`/tax-overrides`) with tabbed interface and role-based access.
- **Override Analytics:** Admin dashboard includes Tax Intelligence stats and enhanced override-logs page.

**FBR Excel Template + PRAL API Alignment:**
- **Document Types:** Support for Sale Invoice, Credit Note, and Debit Note with reference invoice tracking.
- **Company-Wise Continuous Numbering:** `InvoiceNumberingService` generates `COMPANY_PREFIX-000001` format using DB-level `lockForUpdate()`.
- **Province Mapping:** `supplier_province` (auto) and `destination_province` (user-selected) for 8 Pakistan provinces/territories.
- **WHT Calculations:** Manual `wht_rate` selection, `wht_amount`, and `net_receivable` displayed.
- **Buyer Registration Type:** Auto-detected from NTN format (7+ digits = Registered, else Unregistered).
- **Per-Item FBR Fields:** `st_withheld_at_source` (boolean) and `petroleum_levy` (decimal) on `invoice_items`.
- **FBR Status Tracking:** Separate `fbr_status` field (pending/submitted/validated/failed) alongside existing `status`.
- **Enhanced FBR Payload:** Includes `document_type`, `referenceInvoiceNo`, `st_withheld_at_source`, `petroleum_levy`, and province info.
- **Sandbox Test Panel:** 6 testing tools on FBR Settings page (Ping Endpoint, Validate Token, Test Payload Structure, Check Company Config, Dry Run Invoice, Province Mapping).
- **Production Confirmation:** Requires typing "CONFIRM" to switch to Production environment.
- **PDF Template:** Shows document type, WHT deduction, net receivable, SHA256 hash, buyer registration type, and province info.

**FBR Compliance Hardening:**
- **Corrected FBR Payload Math:** Accurate calculation for `valueSalesExcludingST` and `salesTaxApplicable`.
- **Dynamic Company Fields in Payload:** Seller province, business name, NTN, buyer registration type sourced from company settings.
- **UOM Enforcement:** `default_uom` and `sale_type` added to `invoice_items` table with dropdown selection.
- **SaleType Mapping:** `ScheduleEngine::mapSaleType()` maps schedule types to FBR sale type strings.
- **Exempt Schedule Fix:** Exempt items require SRO Schedule No only (no serial_no).
- **FBR Token from Company Settings:** Reads encrypted tokens from company's `fbr_sandbox_token` or `fbr_production_token`.
- **Pre-submission Payload Validator:** `ScheduleEngine::validateFbrPayload()` checks all required FBR fields.
- **Validate-Only Sandbox Mode:** `FbrService::validateOnly()` tests payload against FBR sandbox validation endpoint without submitting.
- **HS Code Search:** Invoice index search includes HS code matching across invoice items.
- **Manual Override Audit Logging:** Tax rate, SRO, and MRP overrides are logged with user attribution.
- **FBR Settings UI Enhancement:** Added "Sandbox Test Mode" info and environment badge on Token Health panel.
- **FBR Value Breakdown:** Invoice create/edit forms show "FBR Value" and "FBR Tax" per item.

**Global HS Intelligence Control System:**
- **`global_hs_master` table:** Centralized HS code repository with `hsCode`, `description`, `pctCode`, `scheduleType`, `taxRate`, `defaultUom`, SRO requirements, `mrpRequired`, `sectorTag`, `riskWeight`, and `mappingStatus`.
- **`hs_unmapped_log` table:** Tracks unmapped HS codes per company with `frequency_count`.
- **`GlobalHsService`:** Resolves HS codes from `global_hs_master` first, then ScheduleEngine fallback; logs unmapped codes; provides SRO suggestions.
- **Invoice Flow Integration:** `InvoiceController` calls `GlobalHsService::resolveForInvoiceItem()` for auto-mapping.
- **Admin Panel (`/admin/hs-master`):** Three-tab interface (All HS, Unmapped HS, Intelligence Insights) for CRUD, inline editing, and quick-mapping.
- **Validation Engine Upgrade:** Dynamic validation for 3rd Schedule, Exempt, and Zero Rated items based on tax rate and SRO/MRP requirements.
- **Sector Mapping Layer:** `sectorTag` on `global_hs_master` for tax rule priority.
- **Smart SRO Suggestion:** Non-destructive confidence scoring from `GlobalHsService::suggestSro()`.
- **Search Upgrade:** API at `/api/hs-search` supports search by HS code, description, schedule, sector, SRO, `taxRate`, with optional usage frequency.

**Advanced HS Intelligence Engine:**
- **`hs_intelligence_logs` table:** Stores weighted suggestion outputs per HS code with `suggested_schedule_type`, `suggested_tax_rate`, required flags, `confidence_score`, `weight_breakdown` JSON, `based_on_records_count`, `rejection_factor`, `industry_factor`.
- **`hs_rejection_history` table:** Tracks admin rejections per HS code.
- **`HsIntelligenceService`:** Weighted suggestion engine using a 6-factor model.
- **Admin Panel Upgrade:** Unmapped HS queue shows intelligence suggestion box with confidence %, risk flag, weight breakdown, and rejection history. Admins can accept, manually override, reject, or regenerate suggestions.
- **Company Data Isolation:** Companies cannot see confidence %, suggestion source, weight breakdown, or risk level.
- **Safety:** No auto-application of suggestions; admin approval required for all mappings.

**Live Rejection Learning Engine (Phase 4B - Feb 2026):**
- **Rejection Capture Engine:** `SendInvoiceToFbrJob` now extracts per-item HS codes on FBR failure and stores rejection data in `hs_rejection_history` with `error_code`, `error_message`, `last_rejected_at`, and `environment` (sandbox/production). Does not affect invoice status logic.
- **Intelligence Feedback Loop:** `HsIntelligenceService` enhanced with FBR rejection-based confidence deduction. rejection_count >3 = -15%, >5 = -25%, >10 = cap at LOW. Weighted output logged in `hs_intelligence_logs`. No auto-fix; admin approval always required.
- **Admin Alert Widget:** Admin dashboard shows "Top Rejected HS Codes (Last 30 Days)" with rejection count, risk badge, last rejected date, and quick link to mapping page. Super Admin only.
- **Confidence Scale Standardization:** 4-tier system: LOW (0-40, red), MEDIUM (41-70, yellow/amber), HIGH (71-89, blue), VERIFIED (90-100, green). Badge colors applied across all admin views.
- **Top 500 HS Master Seeder:** `TopHsMasterSeeder` seeds 612 high-frequency Pakistan commercial HS codes covering cement (2523), iron/steel (7213-7308), electrical cables (8544), petroleum (2709-2713), FMCG food (02-21), plastics (39), machinery (84-85), vehicles (87), chemicals (28-38), textiles (52-63), rubber/tyres (40), furniture (94), leather (41-42), solar (8541), paper (47-48), ceramics/glass (69-70), aluminum (76), copper (74), wood (44), footwear (64), precious metals (71), tools (82-83), instruments (90), beverages (22), tobacco (24), ores (26), pharma (30). All seeded with confidence_score=90, last_source="seed". Upsert only if not exists.

**UI/UX Improvements (Feb 2026):**
- **FBR Settings Redesign:** Separate Sandbox and Production sections with configurable endpoint URLs (`fbr_sandbox_url`, `fbr_production_url`) and tokens. Removed expiry date requirement.
- **Invoice Submission Environment Selection:** Radio buttons in submit modal allow choosing Sandbox or Production environment per-submission, passed to `SendInvoiceToFbrJob`.
- **Customer Profile Management:** Standalone `customer_profiles` table with CRUD at `/customer-profiles`. Search API for autocomplete in invoice forms.
- **Invoice Customer/Product Autocomplete:** Invoice create/edit forms have customer lookup (auto-fills buyer fields) and product search (auto-fills item fields). Fields remain editable after selection.
- **Invoice List Tabs:** Split into "Drafted" (draft/submitted) and "Completed" (locked/failed) tabs with counts and tab-preserving pagination.
- **Products Page Upgrade:** Search functionality, schedule type color badges, HS code mapping sections in create/edit forms.
- **Dashboard Compactness:** Reduced chart heights, compressed spacing (mb-8→mb-6, gap-6→gap-4, py-8→py-6).
- **Dashboard Separation (Feb 2026):** Client dashboard simplified - removed admin-level panels (Audit Probability, Vendor Risk, Industry Benchmark, HS Risk, Risk Heatmap, Tax Intelligence, Compliance Formula, Anomalies). Added client-friendly sections: Quick Actions row (5 shortcuts), Simple Compliance Status badge, Payment Summary cards. Admin-level analytics moved to Super Admin Dashboard with "Platform Risk Intelligence" section (Platform Health cards, Companies at Risk table, Compliance Leaderboard). DashboardController optimized by removing unused queries.

## External Dependencies
- **PostgreSQL:** Primary database.
- **FBR (Federal Board of Revenue) Pakistan:** Integration for tax and invoice submission compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Utility-first CSS framework.
- **Alpine.js:** Lightweight JavaScript framework.
- **Chart.js:** JavaScript charting library.