# TaxNest - Laravel Project

## Overview
TaxNest multi-company tax/invoice management system. Uses PHP 8.4 with Laravel framework and SQLite database.

## Recent Changes
- 2026-02-11: Phase 1 — Multi-Company Structure Setup (companies, invoices, invoice_items tables)
- 2026-02-11: Initial Laravel project setup with PHP 8.4

## Database Tables
- **companies** — name, ntn (unique), email, phone, address, fbr_token
- **invoices** — company_id (FK), invoice_number, status (draft/submitted/locked), buyer_name, buyer_ntn, total_amount
- **invoice_items** — invoice_id (FK), hs_code, description, quantity, price, tax
- **users** — default Laravel users table
- **cache** — default Laravel cache table
- **jobs** — default Laravel jobs table

## Project Architecture
- **Framework**: Laravel (latest)
- **PHP Version**: 8.4
- **Database**: SQLite (default, at `database/database.sqlite`)
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
