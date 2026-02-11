# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS tax/invoice management system for Pakistan with FBR (Federal Board of Revenue) compliance integration. Heavy Enterprise adds Company Approval System, Customer Ledger, Multi-Branch, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and Security Hardening on top of Smart Invoicing, product master, preview/validate flows, dual PRAL submission modes, QR lock, MIS reporting, trend analytics, configurable governance, enterprise API endpoints, PDF download, social sharing, and demo mode.

## Test Accounts
- **Super Admin**: admin@test.com / admin123
- **Company Admin**: company_admin@test.com / admin123
- **Employee**: jawad@test.com / jawad123
- **Demo User**: demo@taxnest.pk / password123

## Recent Changes
- 2026-02-12: 30-Day Launch Mode Features
  - Internal Company Bypass: is_internal_account flag bypasses invoice/subscription/payment limits; admin toggle in Company Deep View; excluded from trial expiry
  - Onboarding Wizard: 4-step guided setup (Branch → FBR Token → Product → Invoice) with progress tracking; onboarding_completed flag; automatic redirect for new users; skip option; internal accounts bypass
  - Invoice Flow Polish: auto-focus first field, Enter key navigation between fields, scroll-to-error on validation failure, branch display in preview
  - PDF Reliability: draft watermark, FBR header with invoice ID on locked, QR data section, WHT line, proper filename (invoice-{number}.pdf)
  - Dashboard Cleanup: role-based views — Retail (KPIs + recent invoices), Business (adds analytics/insights), Enterprise (full analytics + risk + branch stats); internal accounts get Enterprise view
- 2026-02-12: Dual Invoice Number System
  - internal_invoice_number: generated on creation, never overwritten
  - fbr_invoice_number: stored on successful FBR submission, nullable
  - fbr_submission_date: timestamp of FBR submission
  - Display: FBR number shown prominently when available, internal number as reference
  - Search: by internal #, FBR #, customer name, NTN
  - PDF: shows both numbers, filename uses FBR # when available
  - SendInvoiceToFbrJob: no longer overwrites invoice_number
- 2026-02-12: High-Volume Market Capture Pricing
  - Aggressive pricing: Retail (999), Business (2999), Industrial (6999), Enterprise (15000)
  - Discount engine: Monthly 0%, Quarterly 1%, Semi-Annual 3%, Annual 6%
  - 14-day free trial with 20 invoice limit on registration
  - Plan limits: invoice_limit, user_limit, branch_limit enforced server-side
  - PlanLimitService: canCreateInvoice, canAddUser, canAddBranch checks
  - CheckTrialExpiryJob: auto-downgrade expired trials
  - Landing page: pricing display, billing calculator, feature comparison, trust badges, WhatsApp CTA
  - Billing page: cycle selector, dynamic pricing, usage dashboard
- 2026-02-12: Heavy Enterprise 8-Phase Upgrade
  - Phase 1: Company Approval System — company_status (pending/active/suspended/rejected), self-registration creates pending, admin approve/reject, middleware blocks non-active
  - Phase 2: Customer Ledger System — customer_ledgers table, auto debit on invoice lock, manual payment/adjustment entry, running balance
  - Phase 3: Multi-Branch System — branches table, branch_id on invoices, branch CRUD, branch dropdown in invoice create, branch-level reporting
  - Phase 4: FBR Token Health Monitor — token_expiry_date, last_successful_submission, fbr_connection_status, test connection button, daily expiry notification job
  - Phase 5: Advanced Admin View — company show with Overview/Financial/Compliance/Activity tabs, branch stats, tax summary, outstanding balance, VIEW ONLY
  - Phase 6: Audit Log System — immutable audit_logs with SHA256, log all events (invoice/user/FBR/branch), signed CSV export
  - Phase 7: Enterprise Analytics — top 5 customers, branch comparison, compliance %, avg invoice value, rejection rate KPIs
  - Phase 8: Security Hardening — ForceHttps middleware, subscription expired FBR block, company suspended/pending banners
- 2026-02-12: Company Governance + FBR Settings
- 2026-02-12: Hybrid Product + Schedule + Manual Control Model
- 2026-02-12: UI + Admin + PDF Upgrade
- 2026-02-12: Demo + PDF + Share + Mock
- 2026-02-12: Enterprise V3 — Product Master, Smart Invoice Builder, Preview/Validate flow, Smart+Direct MIS submission modes
- 2026-02-12: Regulatory AI Hybrid Model
- 2026-02-12: Enterprise V2
- 2026-02-11: Phase 1-7 — Invoice hardening, FBR intelligence, compliance risk engine, executive dashboard, billing, security, system health

## Database Tables
- **companies** — name, ntn, email, phone, address, fbr_token, token_expires_at, compliance_score, fbr_environment, fbr_sandbox_token (encrypted), fbr_production_token (encrypted), fbr_registration_no, fbr_business_name, suspended_at, company_status (pending/active/suspended/rejected), token_expiry_date, last_successful_submission, fbr_connection_status
- **invoices** — company_id, branch_id, invoice_number, internal_invoice_number, fbr_invoice_number (nullable), fbr_submission_date (nullable), status, integrity_hash, buyer_name, buyer_ntn, total_amount, override_reason, override_by, submission_mode, fbr_invoice_id, qr_data, share_uuid
- **invoice_items** — invoice_id, hs_code, schedule_type, pct_code, tax_rate, sro_schedule_no, serial_no, mrp, description, quantity, price, tax
- **invoice_activity_logs** — invoice_id, company_id, user_id, action, changes_json, ip_address
- **users** — name, email, password, company_id (nullable), role (super_admin/company_admin/employee/viewer), is_active
- **products** — company_id, name, hs_code, pct_code, default_tax_rate, uom, schedule_type, sro_reference, default_price, is_active
- **branches** — company_id, name, address, is_head_office
- **customer_ledgers** — company_id, customer_name, customer_ntn, invoice_id, debit, credit, balance_after, type (invoice/payment/adjustment), notes
- **audit_logs** — company_id, user_id, action, entity_type, entity_id, old_values (JSON), new_values (JSON), ip_address, sha256_hash, created_at (immutable, no updated_at)
- **system_settings** — key (unique), value, description
- **override_logs** — invoice_id, company_id, user_id, action, reason, metadata, ip_address
- **fbr_logs** — invoice_id, request_payload, response_payload, status, failure_type, response_time_ms, retry_count
- **security_logs** — user_id, action, ip_address, user_agent, metadata
- **pricing_plans** — name, invoice_limit, price
- **subscriptions** — company_id, pricing_plan_id, start_date, end_date, trial_ends_at, active
- **notifications** — company_id, user_id, type, title, message, read, metadata
- **compliance_scores** — company_id, score, success_rate, retry_ratio, draft_aging, failure_ratio, category, calculated_date
- **anomaly_logs** — company_id, type, severity, description, metadata, resolved
- **compliance_reports** — company_id, invoice_id, rule_flags, anomaly_flags, final_score, risk_level
- **vendor_risk_profiles** — company_id, vendor_ntn, vendor_name, vendor_score, total_invoices, rejected_invoices, tax_mismatches, anomaly_count, last_flagged_at

## Authentication
- **Laravel Breeze** — Blade + Tailwind CSS based authentication

## Company Approval Flow
- Self-registration creates company with company_status='pending'
- User NOT logged in after registration, redirected to login with pending message
- Super admin can approve (sets active), reject, suspend, unsuspend
- CompanyIsolation middleware blocks login for non-active companies with specific messages

## Middleware
- **company** — CompanyIsolation enforces company_id scoping, blocks pending/suspended/rejected companies
- **role** — RoleMiddleware enforces role-based access
- **rate_limit_company** — RateLimitByCompany enforces 200 req/min per company
- **ForceHttps** — Redirects to HTTPS in production

## Routes — Public
- `/` — VIP landing page (redirects to /dashboard if authenticated)
- `/share/invoice/{uuid}` — Public shareable invoice view

## Routes — Company Users
- `/dashboard` — Dashboard with KPIs, top 5 customers, branch comparison, compliance %, avg invoice value, rejection rate
- `/invoices` — Invoice list with branch column
- `/invoice/create` — Smart Invoice Builder with branch dropdown, product dropdown, auto-calc
- `/invoice/{id}` — Invoice detail with branch info
- `/invoice/{id}/edit` — Edit draft invoice
- `/invoice/{id}/preview` — Preview with tax breakdown, risk score, QR
- `/invoice/{id}/validate` — Run HybridComplianceScorer
- `/invoice/{id}/submit` — Submit to PRAL (Smart or Direct MIS)
- `/invoice/{id}/verify` — Verify SHA256 integrity
- `/invoice/{id}/pdf` — PDF with QR data
- `/invoice/{id}/download` — PDF download
- `/products` — Product master list
- `/products/create` — Create product
- `/products/{id}/edit` — Edit product
- `/customers` — Customer list with totals
- `/customers/{ntn}/ledger` — Customer ledger with running balance
- `/branches` — Branch management (company_admin only)
- `/branches/create` — Create branch
- `/branches/{id}/edit` — Edit branch
- `/mis` — MIS Reports dashboard
- `/mis/export` — CSV export
- `/billing/plans` — Pricing plans
- `/compliance/certificate` — Monthly compliance certificate
- `/compliance/risk-report` — Risk Explanation Report

## Routes — Company Admin Settings
- `/company/users` — Team management
- `/company/profile` — Edit company profile
- `/company/fbr-settings` — FBR Integration Settings with token health, test connection
- `/company/test-connection` — AJAX endpoint for FBR connection test

## Routes — Super Admin
- `/admin/dashboard` — Super admin overview with pending approvals widget
- `/admin/companies` — Company management with status badges
- `/admin/companies/pending` — Pending companies list
- `/admin/companies/create` — Create company with optional admin
- `/admin/company/{id}` — Company deep view (Overview/Financial/Compliance/Activity), VIEW ONLY
- `/admin/company/{id}/approve` — Approve pending company
- `/admin/company/{id}/reject` — Reject company
- `/admin/company/{id}/suspend` — Toggle suspend/unsuspend
- `/admin/company/{id}/change-plan` — Change pricing plan
- `/admin/users` — User management
- `/admin/fbr-logs` — FBR submission logs
- `/admin/system-health` — System health monitor
- `/admin/security-logs` — Security event logs
- `/admin/audit-logs` — Immutable audit log viewer
- `/admin/audit/export` — Signed audit CSV export with SHA256
- `/admin/anomalies` — Anomaly detection logs
- `/admin/risk-settings` — Configurable risk thresholds
- `/admin/override-logs` — Override audit trail

## Customer Ledger System
- Auto debit entry when invoice is locked (via SendInvoiceToFbrJob)
- Manual payment entry (credit) by company_admin/employee
- Manual adjustment entry (debit or credit)
- Running balance calculated per customer (by company_id + customer_ntn)
- WHT shown separately but NOT deducted in FBR payload

## Multi-Branch System
- Branch CRUD for company_admin
- branch_id on invoices (optional)
- Branch dropdown during invoice creation
- Branch-level invoice reporting on dashboard
- Admin can see branch count in company deep view

## FBR Token Health Monitor
- token_expiry_date (editable in FBR settings)
- last_successful_submission (auto-updated on successful FBR submission)
- fbr_connection_status (green/red/unknown indicator)
- Test Connection button (AJAX)
- Daily CheckTokenExpiryJob notifies company_admin if expiring in 48 hours

## Audit Log System
- Immutable audit_logs table (no updated_at)
- SHA256 hash on each entry for tamper detection
- Logs: invoice create/edit/submit, user creation/role change, FBR settings, branch CRUD, company approve/reject/suspend
- AuditLogService::log() static method
- Admin audit log viewer with pagination and filtering
- Signed CSV export with per-row SHA256 and file verification hash

## Project Architecture
- **Framework**: Laravel 12 with Breeze
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Replit-managed)
- **Frontend**: Tailwind CSS + Alpine.js + Chart.js
- **Server**: `php artisan serve` on port 5000
- **Queue**: Database driver with SendInvoiceToFbrJob, ComplianceScoringJob

## Key Directories
- `app/Http/Controllers/` — DashboardController, InvoiceController, ProductController, MISController, BillingController, AdminController, ComplianceCertificateController, RiskReportController, ShareController, CompanyUserController, CompanySettingsController, CustomerLedgerController, BranchController
- `app/Http/Middleware/` — CompanyIsolation, RoleMiddleware, RateLimitByCompany, ForceHttps
- `app/Models/` — User, Company, Invoice, InvoiceItem, Product, Branch, CustomerLedger, AuditLog, SystemSetting, OverrideLog, FbrLog, InvoiceActivityLog, SecurityLog, PricingPlan, Subscription, Notification, ComplianceScore, AnomalyLog, ComplianceReport, VendorRiskProfile
- `app/Jobs/` — SendInvoiceToFbrJob, NightlyComplianceCronJob, CheckFbrTokenExpiryJob, ComplianceScoringJob
- `app/Services/` — ComplianceEngine, AnomalyEngine, HybridComplianceScorer, VendorRiskEngine, AuditDefenseService, AuditLogService, FbrService, ComplianceRiskService, AnomalyDetectionService, SmartInsightsService, ComplianceCertificateService, InvoiceActivityService, IntegrityHashService, SecurityLogService, ScheduleEngine
- `database/seeders/` — DatabaseSeeder, PricingPlanSeeder, SystemSettingsSeeder, TestUsersSeeder, DemoSeeder

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
