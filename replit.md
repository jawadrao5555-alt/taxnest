# Laravel Project

## Overview
Blank Laravel project ready for development. Uses PHP 8.4 with Laravel framework and SQLite database.

## Recent Changes
- 2026-02-11: Initial Laravel project setup with PHP 8.4

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
