# TaxNest - Laravel Project

## Overview
TaxNest multi-company tax/invoice management system. Uses PHP 8.4 with Laravel framework and PostgreSQL database.

## Recent Changes
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
- **users** — name, email, password, company_id (FK nullable to companies)
- **fbr_logs** — invoice_id (FK), request_payload, response_payload, status
- **cache** — default Laravel cache table
- **jobs** — default Laravel jobs table

## Middleware
- **company** — `CompanyIsolation` middleware enforces company_id on authenticated users, stores `currentCompanyId` in app container

## Project Architecture
- **Framework**: Laravel (latest)
- **PHP Version**: 8.4
- **Database**: PostgreSQL (Production-ready, managed by Replit)
- **Server**: `php artisan serve` on port 5000
- **Proxy Trust**: All proxies trusted via `bootstrap/app.php` for Replit compatibility

## Key Directories
- `app/` - Application logic (Models, Controllers, etc.)
- `routes/web.php` - Web routes
- `routes/console.php` - Console commands
- `resources/views/` - Blade templates
- `database/migrations/` - Database migrations
- `public/` - Publicly accessible files

## Running
- Workflow "Laravel Server" runs `php artisan serve --host=0.0.0.0 --port=5000`
- Workflow "Queue Worker" runs `php artisan queue:work`
