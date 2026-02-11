# TaxNest - Laravel Project

## Overview
TaxNest is a multi-company SaaS tax/invoice management system for Pakistan with FBR (Federal Board of Revenue) compliance integration. Features multi-company data isolation, role-based access control, invoice submission to FBR's PRAL Sandbox API, production invoice locking, async queue processing with retries, subscription-based billing with invoice limits, and Laravel Breeze authentication with professional Tailwind CSS UI.

## Test Accounts
- **Super Admin**: admin@test.com / admin123
- **Company Admin**: company_admin@test.com / admin123
- **Employee**: jawad@test.com / jawad123

## Recent Changes
- 2026-02-11: Complete project build — all views professionally styled with Tailwind CSS
- 2026-02-11: Full admin panel (dashboard, companies CRUD, users CRUD, FBR logs viewer)
- 2026-02-11: Invoice management (list, create with multiple items, show detail, edit, PDF, FBR submit)
- 2026-02-11: Dashboard with KPI cards, Chart.js doughnut/bar charts, recent invoices table
- 2026-02-11: RoleMiddleware now enforces role-based access (was pass-through before)
- 2026-02-11: CompanyIsolation middleware allows super_admin bypass
- 2026-02-11: Professional navigation with role badges and context-aware links
- 2026-02-11: Billing plans UI with current plan indicator and subscription management
- 2026-02-11: Database connection uses runtime DATABASE_URL parsing in config/database.php

## Database Tables
- **companies** — name, ntn (unique), email, phone, address, fbr_token
- **invoices** — company_id (FK), invoice_number, status (draft/submitted/locked), buyer_name, buyer_ntn, total_amount
- **invoice_items** — invoice_id (FK), hs_code, description, quantity, price, tax
- **users** — name, email, password, company_id (FK nullable), role (super_admin/company_admin/employee/viewer)
- **fbr_logs** — invoice_id (FK), request_payload, response_payload, status
- **pricing_plans** — name, invoice_limit, price
- **subscriptions** — company_id (FK), pricing_plan_id (FK), start_date, end_date, active
- **cache** — Laravel cache table
- **jobs** — Laravel jobs table

## Authentication
- **Laravel Breeze** — Blade + Tailwind CSS based authentication
- Routes: /login, /register, /forgot-password, /profile
- Root route `/` redirects to /dashboard (if logged in) or /login (if not)

## Middleware
- **company** — CompanyIsolation enforces company_id scoping. Super admins bypass. Users without company redirected to billing/plans.
- **role** — RoleMiddleware enforces role-based access. Super admins can access all routes. Accepts comma-separated roles.

## Routes
- `/dashboard` — Company dashboard with KPI cards and charts
- `/invoices` — Invoice list with pagination
- `/invoice/create` — Create invoice with multiple items (Alpine.js dynamic form)
- `/invoice/{id}` — Invoice detail view
- `/invoice/{id}/edit` — Edit draft invoice
- `/invoice/{id}/submit` — Submit to FBR (queue-based)
- `/invoice/{id}/pdf` — PDF view
- `/billing/plans` — Pricing plans and subscription management
- `/admin/dashboard` — Super admin overview
- `/admin/companies` — Company management (list + create)
- `/admin/users` — User management (list + create)
- `/admin/fbr-logs` — FBR submission logs

## Project Architecture
- **Framework**: Laravel 12 with Breeze
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Replit-managed, runtime DATABASE_URL parsing)
- **Frontend**: Tailwind CSS + Alpine.js + Chart.js (Vite built assets)
- **Server**: `php artisan serve` on port 5000
- **Queue**: Database driver with SendInvoiceToFbrJob (3 retries)
- **FBR Integration**: FbrService posts to PRAL sandbox, logs all requests/responses
- **Proxy Trust**: All proxies trusted via bootstrap/app.php for Replit compatibility

## Key Directories
- `app/Http/Controllers/` — DashboardController, InvoiceController, BillingController, AdminController
- `app/Http/Middleware/` — CompanyIsolation, RoleMiddleware
- `app/Models/` — User, Company, Invoice, InvoiceItem, FbrLog, PricingPlan, Subscription
- `app/Jobs/` — SendInvoiceToFbrJob
- `app/Services/` — FbrService
- `routes/web.php` — All web routes
- `resources/views/` — Blade templates (dashboard, invoice/*, billing/*, admin/*)
- `database/migrations/` — All migrations
- `database/seeders/` — DatabaseSeeder (creates test data), PricingPlanSeeder

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
