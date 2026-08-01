---
name: TaxNest dev environment access
description: How to run PHP/artisan, query the dev MySQL, and the production-env quirks that block tinker
---

- **PHP/artisan CLI must strip Postgres env vars**, otherwise it tries Replit's PostgreSQL instead of the app's MySQL. Always prefix: `env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE php artisan ...`.
- **Tinker is DISABLED** ("CLI interactive shell disabled in production environment") because `APP_ENV=production` even in the dev container. Use `php artisan migrate:status`, `route:list`, etc. for inspection — not tinker.
- **Dev MySQL direct access:** `mysql --defaults-file=/home/runner/workspace/.local/mysql_run/my.cnf -u root taxnest_staging -e "..."`. Plain `mysql` (OS user `runner`, no password) → Access denied; `-u root` works passwordless. DB name: `taxnest_staging`.
- **After editing Blade views:** run `php artisan view:clear && php artisan view:cache` to recompile (catches Blade syntax errors before serving).
- **Lint a compiled view:** compiled filename = `storage/framework/views/` + `hash('xxh128', 'v2'.<absolute blade path>)` + `.php`; run `php -l` on it to prove the view really parses (view:cache alone doesn't).
- **Tinker workaround:** write a standalone script that `require`s `vendor/autoload.php` + `bootstrap/app.php`, then `$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();` — full Eloquent access under the same env-strip prefix.
- **Pushing to GitHub origin:** `git push origin HEAD:main` works (tokened remote), but pushes only COMMITTED work — the platform checkpoint-commits at end of turn, so the current session's changes can only be pushed on a LATER turn.
- **Panel login is NOT decided by pos_role:** Test Trading (test@testtrading.pk) has pos_role=pos_admin yet gets "Invalid credentials" on /pos/login — it is a DI-panel company (log in via plain /login). Use posadmin@taxnest.com for POS-panel curl tests.
- **curl login for authenticated pages:** the login POST field is `login` (NOT `email` — supports email/phone/username/CNIC/NTN). Recipe: GET /login with `-c jar` to grab `_token` + cookies, POST `_token`+`login`+`password` with `-b jar -c jar`, always add `-H "X-Forwarded-Proto: https"`. Then GET any panel page to verify rendered HTML without Playwright. Super-admin login (`admin@taxnest.com` + the standard dev password) works on plain /login via admin auto-detect → /admin/dashboard; saas-admin pages live under the `/admin` prefix (e.g. /admin/companies/{id}), NOT /saas-admin.
- **Lazy-loading is FATAL, even in dev:** `Model::preventLazyLoading()` is on whenever APP_ENV=production — which is ALWAYS (dev container + cPanel). Any NEW relation accessed in a Blade view 500s unless every controller that renders that view eager-loads it (`with(...)`). CLI service tests don't catch this — always curl the actual pages that render the new relation.

**Why:** these are environment quirks (production `APP_ENV` inside a dev container + dual PostgreSQL/MySQL setup) that cause repeated, confusing failures if forgotten.
- **Running PHPUnit/artisan test:** must prefix `APP_ENV=testing` (plus the usual PG env-strip), else the PRODUCTION DB GUARD aborts ("expected mysql, got sqlite"). Tests run on sqlite :memory:; DON'T rely on migrations (a 2026 performance-index migration breaks on sqlite — ~24 legacy tests fail for this pre-existing reason). Pattern: build minimal schema in setUp via Schema::create (see Phase3LoginIsolationTest / ExpiryReminderBoundaryTest); companies table needs softDeletes().
