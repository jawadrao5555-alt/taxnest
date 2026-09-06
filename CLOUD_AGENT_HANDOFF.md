# TaxNest — Cursor Cloud Agent Handoff

Sanitized onboarding for future Cloud Agents. Full product map: `replit.md`. Do not put secrets, credentials, tokens, passwords, SSH details, server IPs, or `.env` / `.local` contents in tracked files.

## Identity
- GitHub repo: `jawadrao5555-alt/taxnest`
- Default branch: `main`
- App name: TaxNest
- Product: multi-company Pakistan tax + invoice SaaS (DI, NestPOS PRA POS, FBR POS, Nest ERPS healthcare vertical, SaaS admin)

## Stack
- Laravel 12 / PHP 8.4 / Composer 2
- Frontend: Vite 7, Tailwind, Alpine.js, Chart.js / related UI libs
- Auth: Breeze; isolated guards (`web` DI, `pos` PRA, `fbrpos` FBR, plus health/erps)
- Tests: PHPUnit via `php artisan test` (`phpunit.xml` forces sqlite `:memory:`, array cache/session/mail, sync queue)
- DB in real deploys: MySQL/MariaDB (not required for the default in-memory test suite)

## Current owner focus (from replit.md)
- Work ONLY on NestPOS PRA (PRA POS) unless the owner explicitly expands scope.
- Do not propose or start FBR POS / Digital Invoice (DI) tasks unless the owner starts that work.
- Prefer stability work on existing PRA POS behavior: bug fixes, test locks, hardening — not unsolicited advanced features.
- Generic root-cause fixes apply to every applicable company at code/logic level; never silently overwrite company settings, staff permissions, feature toggles, or legacy chosen values.

## Hard product invariants (short)
- DI and POS data stay fully isolated — no cross-contamination.
- Auth guards stay isolated; no cross-login between panels (admin auto-detect is the documented exception).
- Admin UI copy = English only; printed receipts/bills = English only.
- Multi-tenancy is `company_id` + isolation middleware; respect RBAC and approval workflow.
- Before editing a subsystem, read the matching topic under `.agents/memory/` when present; `replit.md` is the short map.

## Cloud Agent environment notes
- Install PHP 8.4 + extensions needed by Composer (at least: mbstring, xml, curl, zip, gd, intl, bcmath, sqlite3, mysql).
- `composer install` for PHP deps; `npm ci` for Node deps.
- If `npm ci` fails resolving `package-firewall.replit.local` hosts from `package-lock.json`, remap those tarball hosts to `registry.npmjs.org` without rewriting the lockfile when possible (local hosts/proxy remap is OK). Do not commit lockfile host rewrites unless explicitly asked.
- Create a minimal local `.env` only (untracked). Never copy production secrets. `APP_KEY` can be generated locally. Tests override DB to sqlite memory.
- Do NOT install/configure MySQL solely for the default suite unless a task needs the MySQL-specific skipped test.
- Do NOT commit, push, or deploy unless explicitly asked.
- Do NOT run `npm run build` / `vite build` casually: `public/build` assets are tracked and the build rewrites them.

## Baseline verification (observed on Cloud Agent)
- `php artisan --version` / `about` / `list` boot successfully with a minimal local `.env`.
- `php artisan test`: green overall; example baseline ~4396 passed, 0 failed, 3 skipped.
- Known environment-dependent skips (not product failures):
  - `AiInvoiceReaderTest` scanned-PDF cases — need PDF rasterizer (`pdftoppm` and/or Ghostscript).
  - `FbrPosKotReprintPermissionTest` MySQL timestamp claim-token case — needs reachable MySQL.
- `phpunit.xml` blanks Cloudflare token/zone for tests; leave them empty in test runs.
- `public/storage` may be unlinked in a fresh Cloud Agent; link only if a task needs public disk URLs.

## Safety
- Repo is public — never write passwords or live credentials into tracked files.
- QA/dev logins belong in untracked local files only (see `replit.md` pointers); do not reproduce them here.
- Prefer targeted tests for the area you change; run full `php artisan test` before claiming a Cloud Agent task complete when feasible.
- Leave production/staging infrastructure, deploys, and live customer data alone unless the task explicitly requires them and credentials are provided through approved secret channels.
