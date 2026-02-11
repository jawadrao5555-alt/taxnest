# TaxNest - Enterprise Regulatory AI Hybrid Model

## Overview
TaxNest is a multi-company SaaS tax/invoice management system for Pakistan with FBR (Federal Board of Revenue) compliance integration. The Enterprise Regulatory AI Hybrid Model combines rule-based compliance validation (Sales Tax Act 1990), anomaly detection, hybrid scoring, vendor risk profiling, and audit defense capabilities.

## Test Accounts
- **Super Admin**: admin@test.com / admin123
- **Company Admin**: company_admin@test.com / admin123
- **Employee**: jawad@test.com / jawad123

## Recent Changes
- 2026-02-12: Regulatory AI Hybrid Model — ComplianceEngine (Section 23/73 validation), AnomalyEngine (MoM spike, tax drop, HS shift, value-tax anomaly), HybridComplianceScorer (rule+anomaly+stability), VendorRiskEngine, AuditDefenseService, auto-run on submission with CRITICAL blocking
- 2026-02-12: Enterprise V2 Phase A-D — API rate limiting, FBR token expiry, audit CSV, nightly cron, anomaly detection, trial mode, PDF watermark, compliance certificate, industry benchmark, smart insights
- 2026-02-11: Phase 1-7 — Invoice hardening, FBR intelligence, compliance risk engine, executive dashboard, billing intelligence, security hardening, system health panel

## Database Tables
- **companies** — name, ntn, email, phone, address, fbr_token, token_expires_at, compliance_score
- **invoices** — company_id (FK), invoice_number, status (draft/submitted/locked), integrity_hash, buyer_name, buyer_ntn, total_amount
- **invoice_items** — invoice_id (FK), hs_code, description, quantity, price, tax
- **invoice_activity_logs** — invoice_id (FK), company_id (FK), user_id (FK), action, changes_json, ip_address
- **users** — name, email, password, company_id (FK nullable), role (super_admin/company_admin/employee/viewer)
- **fbr_logs** — invoice_id (FK), request_payload, response_payload, status, failure_type, response_time_ms, retry_count
- **security_logs** — user_id (FK), action, ip_address, user_agent, metadata
- **pricing_plans** — name, invoice_limit, price
- **subscriptions** — company_id (FK), pricing_plan_id (FK), start_date, end_date, trial_ends_at, active
- **notifications** — company_id (FK), user_id (FK), type, title, message, read, metadata
- **compliance_scores** — company_id (FK), score, success_rate, retry_ratio, draft_aging, failure_ratio, category, calculated_date
- **anomaly_logs** — company_id (FK), type, severity, description, metadata, resolved
- **compliance_reports** — company_id (FK), invoice_id (FK), rule_flags (json), anomaly_flags (json), final_score, risk_level
- **vendor_risk_profiles** — company_id (FK), vendor_ntn, vendor_name, vendor_score, total_invoices, rejected_invoices, tax_mismatches, anomaly_count, last_flagged_at
- **cache / jobs / failed_jobs** — Laravel system tables

## Authentication
- **Laravel Breeze** — Blade + Tailwind CSS based authentication
- Routes: /login, /register, /forgot-password, /profile

## Middleware
- **company** — CompanyIsolation enforces company_id scoping. Super admins bypass.
- **role** — RoleMiddleware enforces role-based access.
- **rate_limit_company** — RateLimitByCompany enforces 200 req/min per company. Super admins exempt.

## Routes
- `/dashboard` — Dashboard with KPI, compliance trend, risk badge, vendor panel, audit probability meter
- `/invoices` — Invoice list with pagination
- `/invoice/create` — Create invoice (triggers ComplianceScoringJob)
- `/invoice/{id}` — Invoice detail with compliance analysis card
- `/invoice/{id}/edit` — Edit draft invoice (re-triggers scoring)
- `/invoice/{id}/submit` — Submit to FBR (runs HybridComplianceScorer, blocks CRITICAL)
- `/invoice/{id}/verify` — Verify SHA256 integrity
- `/invoice/{id}/pdf` — PDF view (watermark if subscription expired)
- `/billing/plans` — Pricing plans with trial info
- `/compliance/certificate` — Monthly compliance certificate PDF
- `/compliance/risk-report` — Risk Explanation Report with SHA256 hash
- `/admin/dashboard` — Super admin overview with anomalies
- `/admin/companies` — Company management (14-day trial on new companies)
- `/admin/users` — User management
- `/admin/fbr-logs` — FBR submission logs
- `/admin/system-health` — System health monitor
- `/admin/security-logs` — Security event logs
- `/admin/audit/export` — Immutable audit CSV with SHA256 signatures
- `/admin/anomalies` — Anomaly detection logs

## Regulatory AI Services
- **ComplianceEngine** — Rule-based: tax rate vs HS code mismatch, buyer NTN validation (Section 23), banking violation (Section 73, >50K PKR), invoice structure check. Returns structured flags array with deductions.
- **AnomalyEngine** — Detects: MoM invoice spike %, tax drop %, HS category shift, high-value/low-tax anomaly. Returns risk_weight (0-50).
- **HybridComplianceScorer** — Merges: 100 - rule_deductions - anomaly_weight + stability_bonus. Returns final_score (0-100) and risk_level (LOW/MODERATE/HIGH/CRITICAL). Stores in compliance_reports table.
- **VendorRiskEngine** — Scores vendors (0-100) based on rejection frequency, tax mismatch rate, anomaly count. Stores in vendor_risk_profiles table.
- **AuditDefenseService** — Generates risk explanation reports with SHA256 hash, calculates audit probability based on compliance score and recent critical/high reports.

## Other Services
- **FbrService** — Posts to PRAL sandbox, measures response time, classifies failure types
- **ComplianceRiskService** — Legacy: Calculates 0-100 score (success 40%, retry 20%, draft aging 20%, failure 20%)
- **AnomalyDetectionService** — Legacy: Detects invoice spike (3x MoM), tax spike (40%+)
- **SmartInsightsService** — Generates actionable insights
- **ComplianceCertificateService** — Monthly compliance certificate HTML/PDF
- **InvoiceActivityService** — Logs all invoice actions
- **IntegrityHashService** — Generates/verifies SHA256 hash
- **SecurityLogService** — Logs security events

## Auto-Run Engine (Phase 8)
On invoice creation/edit: ComplianceScoringJob dispatched to queue
On invoice submission:
1. HybridComplianceScorer::score() runs synchronously
2. If risk_level = CRITICAL -> FBR submission blocked with detailed error
3. If risk_level != CRITICAL -> Invoice submitted, SendInvoiceToFbrJob dispatched

## Scheduled Jobs
- **NightlyComplianceCronJob** — Runs daily at 02:00, recalculates all company scores
- **CheckFbrTokenExpiryJob** — Runs daily at 06:00, checks for expiring FBR tokens (48h warning)

## Project Architecture
- **Framework**: Laravel 12 with Breeze
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Replit-managed)
- **Frontend**: Tailwind CSS + Alpine.js + Chart.js
- **Server**: `php artisan serve` on port 5000
- **Queue**: Database driver with SendInvoiceToFbrJob, ComplianceScoringJob
- **Scheduler**: NightlyComplianceCronJob + CheckFbrTokenExpiryJob via routes/console.php

## Key Directories
- `app/Http/Controllers/` — DashboardController, InvoiceController, BillingController, AdminController, ComplianceCertificateController, RiskReportController
- `app/Http/Middleware/` — CompanyIsolation, RoleMiddleware, RateLimitByCompany
- `app/Models/` — User, Company, Invoice, InvoiceItem, FbrLog, InvoiceActivityLog, SecurityLog, PricingPlan, Subscription, Notification, ComplianceScore, AnomalyLog, ComplianceReport, VendorRiskProfile
- `app/Jobs/` — SendInvoiceToFbrJob, NightlyComplianceCronJob, CheckFbrTokenExpiryJob, ComplianceScoringJob
- `app/Services/` — ComplianceEngine, AnomalyEngine, HybridComplianceScorer, VendorRiskEngine, AuditDefenseService, FbrService, ComplianceRiskService, AnomalyDetectionService, SmartInsightsService, ComplianceCertificateService, InvoiceActivityService, IntegrityHashService, SecurityLogService

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
