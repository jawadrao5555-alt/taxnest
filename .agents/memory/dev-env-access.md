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
- **curl login for authenticated pages:** the login POST field is `login` (NOT `email` — supports email/phone/username/CNIC/NTN). Recipe: GET /login with `-c jar` to grab `_token` + cookies, POST `_token`+`login`+`password` with `-b jar -c jar`, always add `-H "X-Forwarded-Proto: https"`. Then GET any panel page to verify rendered HTML without Playwright.

**Why:** these are environment quirks (production `APP_ENV` inside a dev container + dual PostgreSQL/MySQL setup) that cause repeated, confusing failures if forgotten.
