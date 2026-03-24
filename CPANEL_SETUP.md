# TaxNest - cPanel Deployment Guide

## Step 1: Upload Files
- Download/clone the repo from GitHub
- Upload ALL files to `public_html/taxnest/` (ya apni domain folder mein)
- Ya phir direct `public_html/` mein upload karein agar dedicated domain hai

## Step 2: Database Setup (PostgreSQL ya MySQL)
1. cPanel > **PostgreSQL Databases** (ya MySQL Databases)
2. Naya database banayein: `taxnest_db`
3. Naya user banayein: `taxnest_user` with strong password
4. User ko database mein add karein (ALL PRIVILEGES)
5. `backup.sql` import karein:
   - **PostgreSQL:** cPanel > Terminal > `psql -U taxnest_user -d taxnest_db < backup.sql`
   - **MySQL:** cPanel > phpMyAdmin > Import > `backup.sql` select karein

## Step 3: Environment File (.env)
1. `.env.example` ko `.env` rename karein
2. Ye values update karein:

```
APP_NAME=TaxNest
APP_ENV=production
APP_DEBUG=false
APP_URL=https://aapki-domain.com

DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=taxnest_db
DB_USERNAME=taxnest_user
DB_PASSWORD=aapka_password

SESSION_DRIVER=file
SESSION_LIFETIME=120

QUEUE_CONNECTION=database
```

## Step 4: PHP Version
- cPanel > **MultiPHP Manager** > PHP 8.2+ select karein
- cPanel > **MultiPHP INI Editor** > Ye enable karein:
  - `fileinfo`
  - `pdo_pgsql` (PostgreSQL ke liye)
  - `pdo_mysql` (MySQL ke liye)
  - `mbstring`
  - `openssl`
  - `gd`
  - `zip`

## Step 5: Terminal Commands
cPanel > **Terminal** mein ye commands chalayein:

```bash
cd ~/public_html/taxnest

composer install --no-dev --optimize-autoloader

php artisan key:generate

php artisan config:cache

php artisan route:cache

php artisan view:cache

php artisan storage:link

chmod -R 775 storage bootstrap/cache
```

## Step 6: Domain/Subdomain Point karein
- Agar subdomain use kar rahe hain: cPanel > **Subdomains** > Document Root set karein:
  `/public_html/taxnest/public`
- Agar main domain hai to root `.htaccess` already public folder redirect karega

## Step 7: Queue Worker (Optional but Recommended)
cPanel > **Cron Jobs** mein add karein:
```
* * * * * cd ~/public_html/taxnest && php artisan schedule:run >> /dev/null 2>&1
```

Queue ke liye Supervisor ya cron:
```
* * * * * cd ~/public_html/taxnest && php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

## Step 8: SSL Certificate
- cPanel > **SSL/TLS** ya **Let's Encrypt** se free SSL lagayein
- `.env` mein `APP_URL=https://...` rakhein

## Folder Structure (cPanel mein)
```
public_html/
└── taxnest/
    ├── .htaccess          (root - public folder redirect)
    ├── .env               (environment config)
    ├── app/
    ├── bootstrap/
    ├── config/
    ├── database/
    ├── public/
    │   ├── .htaccess      (Laravel routes)
    │   ├── index.php
    │   └── build/
    ├── resources/
    ├── routes/
    ├── storage/
    └── vendor/
```

## Troubleshooting
- **500 Error:** `chmod -R 775 storage bootstrap/cache`
- **Blank Page:** `.env` mein `APP_DEBUG=true` karein temporarily
- **CSS/JS not loading:** `php artisan storage:link` chalayein
- **Database error:** `.env` mein DB credentials check karein
- **Session issues:** `php artisan config:cache` dobara chalayein
