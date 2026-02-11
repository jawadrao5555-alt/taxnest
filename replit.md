# TaxNest - Enterprise V2 Compliance Control Center

## Overview
TaxNest is a multi-company SaaS tax/invoice management system for Pakistan with FBR (Federal Board of Revenue) compliance integration. Enterprise V2 adds API rate limiting, FBR token expiry monitoring, immutable audit export, nightly compliance cron, anomaly/tax spike detection, trial mode, PDF watermarking, auto-suspension, compliance certificate generation, industry benchmarking, and smart insights.

## Test Accounts
- **Super Admin**: admin@test.com / admin123
- **Company Admin**: company_admin@test.com / admin123
- **Employee**: jawad@test.com / jawad123

## Recent Changes
- 2026-02-12: Enterprise V2 Phase A — API rate limiting (200/min per company), FBR token expiry monitor, immutable audit CSV export, nightly compliance cron
- 2026-02-12: Enterprise V2 Phase B — ComplianceRiskService, anomaly detection (invoice spike 3x), tax spike detection (40%+), smart insights panel, industry benchmark widget
- 2026-02-12: Enterprise V2 Phase C — Trial mode (14 days), auto-suspension (expired sub blocks FBR), usage warning banner (80%+), PDF watermark on expired subs
- 2026-02-12: Enterprise V2 Phase D — Compliance certificate PDF generator, industry benchmark comparison, smart insights panel
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
- **cache / jobs / failed_jobs** — Laravel system tables

## Authentication
- **Laravel Breeze** — Blade + Tailwind CSS based authentication
- Routes: /login, /register, /forgot-password, /profile

## Middleware
- **company** — CompanyIsolation enforces company_id scoping. Super admins bypass.
- **role** — RoleMiddleware enforces role-based access.
- **rate_limit_company** — RateLimitByCompany enforces 200 req/min per company. Super admins exempt.

## Routes
- `/dashboard` — Company dashboard with KPI cards, charts, smart insights, industry benchmark, anomalies
- `/invoices` — Invoice list with pagination
- `/invoice/create` — Create invoice with multiple items
- `/invoice/{id}` — Invoice detail with timeline and integrity verification
- `/invoice/{id}/edit` — Edit draft invoice
- `/invoice/{id}/submit` — Submit to FBR (checks trial/subscription expiry)
- `/invoice/{id}/verify` — Verify SHA256 integrity
- `/invoice/{id}/pdf` — PDF view (watermark if subscription expired)
- `/billing/plans` — Pricing plans with trial info, usage indicators
- `/compliance/certificate` — Monthly compliance certificate PDF
- `/admin/dashboard` — Super admin overview with anomalies
- `/admin/companies` — Company management (14-day trial on new companies)
- `/admin/users` — User management
- `/admin/fbr-logs` — FBR submission logs
- `/admin/system-health` — System health monitor
- `/admin/security-logs` — Security event logs
- `/admin/audit/export` — Immutable audit CSV with SHA256 signatures
- `/admin/anomalies` — Anomaly detection logs

## Services
- **FbrService** — Posts to PRAL sandbox, measures response time, classifies failure types
- **ComplianceRiskService** — Calculates 0-100 score (success 40%, retry 20%, draft aging 20%, failure 20%), stores in compliance_scores table
- **AnomalyDetectionService** — Detects invoice spike (3x month-over-month), tax spike (40%+ increase)
- **SmartInsightsService** — Generates actionable insights (draft aging, retry rate, success rate, compliance warnings)
- **ComplianceCertificateService** — Generates monthly compliance certificate HTML/PDF
- **InvoiceActivityService** — Logs all invoice actions
- **IntegrityHashService** — Generates/verifies SHA256 hash
- **SecurityLogService** — Logs security events

## Scheduled Jobs
- **NightlyComplianceCronJob** — Runs daily at 02:00, recalculates all company scores
- **CheckFbrTokenExpiryJob** — Runs daily at 06:00, checks for expiring FBR tokens (48h warning)

## Project Architecture
- **Framework**: Laravel 12 with Breeze
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Replit-managed)
- **Frontend**: Tailwind CSS + Alpine.js + Chart.js
- **Server**: `php artisan serve` on port 5000
- **Queue**: Database driver with SendInvoiceToFbrJob (3 retries, exponential backoff)
- **Scheduler**: NightlyComplianceCronJob + CheckFbrTokenExpiryJob via routes/console.php

## Key Directories
- `app/Http/Controllers/` — DashboardController, InvoiceController, BillingController, AdminController, ComplianceCertificateController
- `app/Http/Middleware/` — CompanyIsolation, RoleMiddleware, RateLimitByCompany
- `app/Models/` — User, Company, Invoice, InvoiceItem, FbrLog, InvoiceActivityLog, SecurityLog, PricingPlan, Subscription, Notification, ComplianceScore, AnomalyLog
- `app/Jobs/` — SendInvoiceToFbrJob, NightlyComplianceCronJob, CheckFbrTokenExpiryJob
- `app/Services/` — FbrService, ComplianceRiskService, AnomalyDetectionService, SmartInsightsService, ComplianceCertificateService, InvoiceActivityService, IntegrityHashService, SecurityLogService

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
