# TaxNest - Laravel Project

## Overview
TaxNest multi-company tax/invoice management system. Uses PHP 8.4 with Laravel framework and PostgreSQL database. Features Laravel Breeze authentication with Tailwind CSS.

## Recent Changes
- 2026-02-11: Fixed database connection — explicit DB_HOST/DB_PORT/DB_DATABASE in .env from parsed DATABASE_URL
- 2026-02-11: Fixed DatabaseSeeder to call PricingPlanSeeder + TestUsersSeeder properly
- 2026-02-11: Test accounts updated to admin@test.com / jawad@test.com
- 2026-02-11: Phase 12 — Visual Analytics (Chart.js integration, KPI cards, DashboardController)
- 2026-02-11: Phase 11 — Billing UI (Plan selection, subscription management, PricingPlanSeeder)
- 2026-02-11: Phase 10 — Billing Enforcement (Invoice limit checking, PricingPlan/Subscription models)
- 2026-02-11: Laravel Breeze Authentication installed (Login, Register, Profile, Password Reset)
- 2026-02-11: Fixed DB_CONNECTION from sqlite to pgsql, SESSION_DRIVER to file
- 2026-02-11: Phase 7 — PostgreSQL Migration (Production-ready database setup with indexing)
- 2026-02-11: Phase 6 — Real PRAL Sandbox Integration (Logging, Retries, Sandbox Endpoint)
- 2026-02-11: Phase 5 — Queue Retry System (Async FBR submission)
- 2026-02-11: Phase 4 — Invoice UI & PDF (Creation form, Dashboard, PDF generation)
- 2026-02-11: Phase 3 — Production Invoice Lock (Status-based locking, FBR Service structure)
- 2026-02-11: Phase 2 — Company Isolation Middleware (CompanyIsolation middleware, company_id on users, route protection)
- 2026-02-11: Phase 1 — Multi-Company Structure Setup (companies, invoices, invoice_items tables)
- 2026-02-11: Initial Laravel project setup with PHP 8.4

## Database Tables
- **companies** — name, ntn (unique), email, phone, address, fbr_token
- **invoices** — company_id (FK), invoice_number, status (draft/submitted/locked), buyer_name, buyer_ntn, total_amount
- **invoice_items** — invoice_id (FK), hs_code, description, quantity, price, tax
- **users** — name, email, password, company_id (FK nullable to companies), role (super_admin/company_admin/employee/viewer)
- **fbr_logs** — invoice_id (FK), request_payload, response_payload, status
- **pricing_plans** — name, invoice_limit, price
- **subscriptions** — company_id (FK), pricing_plan_id (FK), start_date, end_date, active
- **cache** — default Laravel cache table
- **jobs** — default Laravel jobs table

## Authentication
- **Laravel Breeze** — Blade + Tailwind CSS based authentication
- Routes: /login, /register, /forgot-password, /profile
- Root route `/` redirects to /dashboard (if logged in) or /login (if not)

## Middleware
- **company** — `CompanyIsolation` middleware enforces company_id on authenticated users, stores `currentCompanyId` in app container. Redirects to billing/plans if no company assigned.
- **role** — `RoleMiddleware` enforces role-based access (super_admin, company_admin, employee, viewer)

## Project Architecture
- **Framework**: Laravel 12 with Breeze
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Replit-managed, explicit host/port/db in .env parsed from DATABASE_URL)
- **Frontend**: Tailwind CSS + Chart.js
- **Server**: `php artisan serve` on port 5000
- **Proxy Trust**: All proxies trusted via `bootstrap/app.php` for Replit compatibility

## Key Directories
- `app/` - Application logic (Models, Controllers, etc.)
- `app/Http/Controllers/Auth/` - Breeze auth controllers
- `routes/web.php` - Web routes
- `routes/auth.php` - Authentication routes (Breeze)
- `resources/views/` - Blade templates
- `resources/views/auth/` - Auth views (login, register, etc.)
- `database/migrations/` - Database migrations
- `database/seeders/` - Database seeders (PricingPlanSeeder)
- `public/` - Publicly accessible files

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
