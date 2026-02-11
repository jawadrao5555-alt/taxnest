# TaxNest - Laravel Compliance Control Center

## Overview
TaxNest is a multi-company SaaS tax/invoice management system for Pakistan with FBR (Federal Board of Revenue) compliance integration. Features multi-company data isolation, role-based access control, invoice submission to FBR's PRAL Sandbox API, production invoice locking with SHA256 integrity hashing, async queue processing with retries, subscription-based billing with invoice limits, compliance risk scoring, system health monitoring, security logging, and Laravel Breeze authentication with professional Tailwind CSS UI.

## Test Accounts
- **Super Admin**: admin@test.com / admin123
- **Company Admin**: company_admin@test.com / admin123
- **Employee**: jawad@test.com / jawad123

## Recent Changes
- 2026-02-11: Phase 1 — Invoice Hardening: integrity_hash SHA256 column, invoice_activity_logs table, timeline component, verify integrity button
- 2026-02-11: Phase 2 — FBR Intelligence: failure_type classification, response_time_ms tracking, retry_count in fbr_logs
- 2026-02-11: Phase 3 — Compliance Risk Engine: compliance_score in companies, ComplianceScoreService (success_rate 40%, retry_ratio 20%, draft_aging 20%, failure_ratio 20%)
- 2026-02-11: Phase 4 — Executive Dashboard: draft aging breakdown, FBR success rate, compliance trend chart, animated KPI counters, activity panel
- 2026-02-11: Phase 5 — Billing Intelligence: subscription expiry countdown, usage percentage indicator, upgrade suggestion banner, plan comparison modal
- 2026-02-11: Phase 6 — Security Hardening: security_logs table, failed login tracking, admin action logging, IP/user-agent capture
- 2026-02-11: Phase 7 — System Health Panel: pending/failed jobs, avg FBR response time, retries 24h, failure breakdown, company risk distribution, health score indicator

## Database Tables
- **companies** — name, ntn, email, phone, address, fbr_token, compliance_score
- **invoices** — company_id (FK), invoice_number, status (draft/submitted/locked), integrity_hash, buyer_name, buyer_ntn, total_amount
- **invoice_items** — invoice_id (FK), hs_code, description, quantity, price, tax
- **invoice_activity_logs** — invoice_id (FK), company_id (FK), user_id (FK), action, changes_json, ip_address, created_at
- **users** — name, email, password, company_id (FK nullable), role (super_admin/company_admin/employee/viewer)
- **fbr_logs** — invoice_id (FK), request_payload, response_payload, status, failure_type, response_time_ms, retry_count
- **security_logs** — user_id (FK), action, ip_address, user_agent, metadata, created_at
- **pricing_plans** — name, invoice_limit, price
- **subscriptions** — company_id (FK), pricing_plan_id (FK), start_date, end_date, active
- **cache** — Laravel cache table
- **jobs / failed_jobs** — Laravel queue tables

## Authentication
- **Laravel Breeze** — Blade + Tailwind CSS based authentication
- Routes: /login, /register, /forgot-password, /profile
- Root route `/` redirects to /dashboard (if logged in) or /login (if not)

## Middleware
- **company** — CompanyIsolation enforces company_id scoping. Super admins bypass.
- **role** — RoleMiddleware enforces role-based access. Super admins can access all routes.

## Routes
- `/dashboard` — Company dashboard with KPI cards, charts, draft aging, activity panel
- `/invoices` — Invoice list with pagination
- `/invoice/create` — Create invoice with multiple items
- `/invoice/{id}` — Invoice detail with timeline and integrity verification
- `/invoice/{id}/edit` — Edit draft invoice
- `/invoice/{id}/submit` — Submit to FBR (queue-based)
- `/invoice/{id}/verify` — Verify SHA256 integrity (POST)
- `/invoice/{id}/pdf` — PDF view
- `/billing/plans` — Pricing plans with usage indicators, expiry countdown, upgrade banners
- `/admin/dashboard` — Super admin overview
- `/admin/companies` — Company management
- `/admin/users` — User management
- `/admin/fbr-logs` — FBR submission logs
- `/admin/system-health` — System health monitor
- `/admin/security-logs` — Security event logs

## Services
- **FbrService** — Posts to PRAL sandbox, measures response time, classifies failure types
- **InvoiceActivityService** — Logs all invoice actions (created, edited, submitted, retry, locked, fbr_failed)
- **IntegrityHashService** — Generates/verifies SHA256 hash from invoice data
- **ComplianceScoreService** — Calculates 0-100 score based on success_rate, retry_ratio, draft_aging, failure_ratio
- **SecurityLogService** — Logs security events (login, failed_login, admin actions, subscription changes)

## Project Architecture
- **Framework**: Laravel 12 with Breeze
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Replit-managed, runtime DATABASE_URL parsing)
- **Frontend**: Tailwind CSS + Alpine.js + Chart.js (Vite built assets)
- **Server**: `php artisan serve` on port 5000
- **Queue**: Database driver with SendInvoiceToFbrJob (3 retries, exponential backoff)
- **Events**: Login/Failed login events trigger security logging via AppServiceProvider

## Key Directories
- `app/Http/Controllers/` — DashboardController, InvoiceController, BillingController, AdminController
- `app/Http/Middleware/` — CompanyIsolation, RoleMiddleware
- `app/Models/` — User, Company, Invoice, InvoiceItem, FbrLog, InvoiceActivityLog, SecurityLog, PricingPlan, Subscription
- `app/Jobs/` — SendInvoiceToFbrJob
- `app/Services/` — FbrService, InvoiceActivityService, IntegrityHashService, ComplianceScoreService, SecurityLogService
- `routes/web.php` — All web routes
- `resources/views/` — Blade templates
- `database/migrations/` — All migrations
- `database/seeders/` — DatabaseSeeder, PricingPlanSeeder

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
