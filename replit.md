# TaxNest - Enterprise V3 Smart Invoicing + MIS + PRAL Flow

## Overview
TaxNest is a multi-company SaaS tax/invoice management system for Pakistan with FBR (Federal Board of Revenue) compliance integration. Enterprise V3 adds Smart Invoicing with product master, preview/validate flows, dual PRAL submission modes, QR lock, MIS reporting, trend analytics, configurable governance, enterprise API endpoints, PDF download, social sharing, and demo mode.

## Test Accounts
- **Super Admin**: admin@test.com / admin123
- **Company Admin**: company_admin@test.com / admin123
- **Employee**: jawad@test.com / jawad123
- **Demo User**: demo@taxnest.pk / password123

## Recent Changes
- 2026-02-12: Demo + PDF + Share + Mock — PDF download with draft/FBR watermarks, social share links with UUID, demo user/company/products/invoices seeder, dashboard thumbnail cards, QR code generation, demo safety mode
- 2026-02-12: Enterprise V3 — Product Master, Smart Invoice Builder, Preview/Validate flow, Smart+Direct MIS submission modes, QR+Lock, MIS Reporting, Trend Analytics, Governance Panel, Enterprise API
- 2026-02-12: Regulatory AI Hybrid Model — ComplianceEngine, AnomalyEngine, HybridComplianceScorer, VendorRiskEngine, AuditDefenseService, auto-run with CRITICAL blocking
- 2026-02-12: Enterprise V2 — API rate limiting, FBR token expiry, audit CSV, nightly cron, anomaly detection, trial mode, PDF watermark, compliance certificate
- 2026-02-11: Phase 1-7 — Invoice hardening, FBR intelligence, compliance risk engine, executive dashboard, billing, security, system health

## Database Tables
- **companies** — name, ntn, email, phone, address, fbr_token, token_expires_at, compliance_score
- **invoices** — company_id, invoice_number, status, integrity_hash, buyer_name, buyer_ntn, total_amount, override_reason, override_by, submission_mode, fbr_invoice_id, qr_data, share_uuid
- **invoice_items** — invoice_id, hs_code, description, quantity, price, tax
- **invoice_activity_logs** — invoice_id, company_id, user_id, action, changes_json, ip_address
- **users** — name, email, password, company_id (nullable), role (super_admin/company_admin/employee/viewer)
- **products** — company_id, name, hs_code, pct_code, default_tax_rate, uom, schedule_type, sro_reference, default_price, is_active
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

## Middleware
- **company** — CompanyIsolation enforces company_id scoping
- **role** — RoleMiddleware enforces role-based access
- **rate_limit_company** — RateLimitByCompany enforces 200 req/min per company

## Routes — Public
- `/share/invoice/{uuid}` — Public shareable invoice view (no auth required)

## Routes — Company Users
- `/dashboard` — Dashboard with KPIs, invoice thumbnail cards, compliance trend, risk badge, vendor panel, audit probability, MoM growth, tax variance, HS risk heatmap
- `/invoices` — Invoice list with pagination and download links
- `/invoice/create` — Smart Invoice Builder with product dropdown, auto-calc, live compliance check
- `/invoice/{id}` — Invoice detail with compliance analysis card, share buttons
- `/invoice/{id}/edit` — Edit draft invoice with smart builder
- `/invoice/{id}/preview` — Preview with tax breakdown, risk score, QR image, validate button, download/share buttons
- `/invoice/{id}/validate` — Run HybridComplianceScorer, show validation result
- `/invoice/{id}/submit` — Submit to PRAL (Smart Mode or Direct MIS Mode)
- `/invoice/{id}/verify` — Verify SHA256 integrity
- `/invoice/{id}/pdf` — PDF with QR data (if locked), watermark (if expired)
- `/invoice/{id}/download` — PDF download with draft watermark or FBR verified header
- `/products` — Product master list
- `/products/create` — Create product
- `/products/{id}/edit` — Edit product
- `/products/{id}/deactivate` — Toggle product active status
- `/mis` — MIS Reports dashboard (monthly, tax, HS concentration, vendor risk)
- `/mis/export?type=` — CSV export (monthly/tax/hs/vendor)
- `/billing/plans` — Pricing plans
- `/compliance/certificate` — Monthly compliance certificate
- `/compliance/risk-report` — Risk Explanation Report
- `/api/products/search` — Product search API (AJAX)
- `/api/compliance/check` — Live compliance check API (AJAX)
- `/api/enterprise/invoice/{id}/status` — Enterprise invoice status API
- `/api/enterprise/company/compliance` — Enterprise compliance status API

## Routes — Super Admin
- `/admin/dashboard` — Super admin overview
- `/admin/companies` — Company management
- `/admin/users` — User management
- `/admin/fbr-logs` — FBR submission logs
- `/admin/system-health` — System health monitor
- `/admin/security-logs` — Security event logs
- `/admin/audit/export` — Immutable audit CSV
- `/admin/anomalies` — Anomaly detection logs
- `/admin/risk-settings` — Configurable risk thresholds (governance)
- `/admin/override-logs` — Override audit trail

## Demo Mode
- **Demo User**: demo@taxnest.pk / password123 (company_admin role)
- **Demo Company**: Demo Traders Pvt Ltd (NTN: 9876543-2)
- **Demo Products**: Cooking Oil 1L (HS 15179090, 18%), Cement Bag (HS 25232900, 18%), Fertilizer (HS 31021000, 0%)
- **Demo Invoices**: DEMO-INV-001 (draft), DEMO-INV-002 (locked with FBR MOCK-FBR-0001)
- **DEMO_MODE flag**: system_settings key 'demo_mode' = 'true' disables real PRAL API calls, uses mock FBR numbers
- DemoSeeder creates all demo data, registered in DatabaseSeeder

## PDF Download
- Draft invoices: Show "DRAFT COPY" watermark
- Locked invoices: Show FBR VERIFIED header, FBR invoice number, QR code image
- Expired subscriptions: Show "Subscription Expired" watermark

## Social Sharing
- Each invoice gets auto-generated UUID (share_uuid)
- Public shareable link: /share/invoice/{uuid}
- WhatsApp share button and Copy Link button on invoice preview/show pages

## QR Code Generation
- QR code images generated via qrserver.com API
- Invoice model accessor: qr_image_url attribute
- QR displayed in PDF, preview, share pages

## Smart Invoicing Flow
1. Create invoice: Product dropdown auto-fills HS code, tax rate, price, description
2. Auto-calculate: quantity * price for subtotal, tax = rate%, total = subtotal + tax
3. Live compliance check via AJAX before submission
4. Preview mode: Full layout with tax breakdown, risk score, QR image
5. Validate: Runs HybridComplianceScorer, shows score/flags/FBR status
6. Submit Smart Mode: Score -> block CRITICAL -> send to PRAL -> QR -> lock
7. Submit Direct MIS: Override reason required -> skip compliance block -> send to PRAL -> log override

## PRAL Submission Modes
- **Smart Mode**: Runs scoring, blocks CRITICAL risk, sends to PRAL, generates QR, locks invoice
- **Direct MIS Mode**: Requires company_admin+ role, override_reason (min 10 chars), logs to override_logs, skips compliance block

## Governance Settings (system_settings)
- mom_spike_threshold: MoM invoice spike % (default 200)
- tax_drop_threshold: Tax drop % (default 60)
- critical_score_threshold: Score below = CRITICAL (default 40)
- stability_bonus_weight: Max stability bonus (default 10)
- demo_mode: Enable/disable demo safety mode (default false)

## Regulatory AI Services
- **ComplianceEngine** — Rule-based validation (tax rate, buyer NTN S.23, banking S.73, structure)
- **AnomalyEngine** — MoM spike, tax drop, HS shift, value-tax anomaly (reads system_settings)
- **HybridComplianceScorer** — Merges rule+anomaly+stability (reads system_settings for thresholds)
- **VendorRiskEngine** — Vendor scoring with persistence
- **AuditDefenseService** — Risk reports with SHA256, audit probability

## Project Architecture
- **Framework**: Laravel 12 with Breeze
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Replit-managed)
- **Frontend**: Tailwind CSS + Alpine.js + Chart.js
- **Server**: `php artisan serve` on port 5000
- **Queue**: Database driver with SendInvoiceToFbrJob, ComplianceScoringJob

## Key Directories
- `app/Http/Controllers/` — DashboardController, InvoiceController, ProductController, MISController, BillingController, AdminController, ComplianceCertificateController, RiskReportController, ShareController
- `app/Http/Middleware/` — CompanyIsolation, RoleMiddleware, RateLimitByCompany
- `app/Models/` — User, Company, Invoice, InvoiceItem, Product, SystemSetting, OverrideLog, FbrLog, InvoiceActivityLog, SecurityLog, PricingPlan, Subscription, Notification, ComplianceScore, AnomalyLog, ComplianceReport, VendorRiskProfile
- `app/Jobs/` — SendInvoiceToFbrJob, NightlyComplianceCronJob, CheckFbrTokenExpiryJob, ComplianceScoringJob
- `app/Services/` — ComplianceEngine, AnomalyEngine, HybridComplianceScorer, VendorRiskEngine, AuditDefenseService, FbrService, ComplianceRiskService, AnomalyDetectionService, SmartInsightsService, ComplianceCertificateService, InvoiceActivityService, IntegrityHashService, SecurityLogService
- `database/seeders/` — DatabaseSeeder, PricingPlanSeeder, SystemSettingsSeeder, TestUsersSeeder, DemoSeeder

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
