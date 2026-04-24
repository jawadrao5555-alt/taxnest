# TaxNest Production Sync — 2026-04-24

Sync staging data → Hostcry production MariaDB **in ONE shot**.

## What it adds to production
- **PUNJAB PLUS RESTAURANT** (company id=17) + admin user, head office branch, POS Plus subscription
- **ZIA's 79 invoices** (id 593–671) + **81 invoice_items** for today

ZIA company (id=7) and NestPOS (id=11) already exist on prod — untouched.
**Today's NestPOS POS transactions and FBR POS test data are intentionally NOT in this batch** (test/internal — keep staging-only).

## Safety
- All ALTER TABLE use `ADD COLUMN IF NOT EXISTS` → existing columns silently skipped, never altered
- All INSERT use `INSERT IGNORE` → if a row exists, it's skipped, never overwritten
- **Primary keys / ID column types are NEVER touched** (no DDL on existing PKs)
- Pre-migration backup auto-taken if not present (`~/backup-before-sync*.sql`)
- Fully **idempotent** — safe to re-run if anything fails mid-way

## How to run (ONE command on Hostcry)

After `git pull` on Hostcry to get the latest files:

```bash
cd ~/public_html
bash database/deploy/2026-04-24-production-sync/run-migration.sh
```

That's it. The script:
1. Takes a fresh backup if needed
2. Runs schema-align (adds any missing columns to 6 tables)
3. Inserts PUNJAB PLUS company + user + branch + subscription
4. Inserts ZIA's 79 invoices + 81 items
5. Prints VERIFY results so you can confirm

## Expected VERIFY output (last lines)

| check_name | result |
|---|---|
| PUNJAB PLUS company present | 1 |
| PUNJAB PLUS PRA configured | 1 |
| PUNJAB PLUS user present | 1 |
| PUNJAB PLUS branch present | 1 |
| PUNJAB PLUS subscription present | 1 |
| ZIA invoices today (79 expected) | 79 |
| ZIA invoice_items today (81 expected) | 81 |
| Orphan invoice_items (must be 0) | 0 |

If any number is off, **STOP** and ping me — DO NOT run anything else.

## Rollback (only if something is wrong)

```bash
mysql taxnestc_db < ~/backup-before-sync.sql
```

That restores the entire DB to pre-migration state.

## Files in this folder

| File | Purpose |
|---|---|
| **`run-migration.sh`** | **The one command** — runs everything end-to-end |
| `MASTER-ALL.sql` | Single concatenated SQL (used by the runner) |
| `02-SCHEMA-ALIGN.sql` | Standalone schema-align (uses ADD COLUMN IF NOT EXISTS) |
| `01a..01d` | PUNJAB PLUS company/user/branch/subscription |
| `03-zia-invoices.sql` | 79 ZIA invoices |
| `04-zia-invoice-items.sql` | 81 ZIA invoice items |
| `999-VERIFY.sql` | Verification SELECT queries |
| `00-PREFLIGHT.sql` | Pre-flight checks (optional, MASTER already runs them implicitly) |
| `99-SCHEMA-FIXES.sql` | Old/superseded — use `02-SCHEMA-ALIGN.sql` instead |
| `RUN-ALL.sql` | Old order doc — superseded by run-migration.sh |

## phpMyAdmin alternative (if shell access is limited)

In cPanel → phpMyAdmin → select `taxnestc_db` → SQL tab →
**Import** tab → upload `MASTER-ALL.sql` (169 KB) → Go.

Then run `999-VERIFY.sql` separately to see verification.

## Post-migration follow-ups

1. Rotate the DB password (it was shared in chat earlier — change in cPanel → MySQL Databases → User → Change Password, then update `.env` `DB_PASSWORD` and run `php artisan config:clear`)
2. Optional: `php artisan queue:restart` to pick up any queue config changes
