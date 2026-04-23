# TaxNest — MySQL Migration Playbook

This document describes how to switch TaxNest from PostgreSQL to MySQL 8+ **after** the backend code has been made dual-compatible (this phase is complete — see `Backend MySQL-Readiness` below).

> **DO NOT run this in production without:**
> 1. Full Postgres dump verified offsite
> 2. Maintenance window scheduled (recommended Saturday 11pm-3am PKT)
> 3. Rollback path tested on staging first

---

## ✅ Backend MySQL-Readiness (COMPLETED)

The following code-level work has been done so the app boots and runs on either DB by toggling a single env var:

| Item | Status | Where |
|---|---|---|
| Central `DbCompat` helper expanded | ✅ | `app/Helpers/DbCompat.php` |
| `MODE() WITHIN GROUP` removed (PG-only) | ✅ | `app/Services/SmartTaxEngine.php` |
| `EXTRACT(HOUR …)` → `DbCompat::extractHour` | ✅ | `app/Http/Controllers/RestaurantPosController.php` |
| `WhtReportController` local shims → central helper | ✅ | `app/Http/Controllers/WhtReportController.php` |
| `customer_profiles` partial-unique index | ✅ already dual | `database/migrations/2026_02_12_070000_create_customer_profiles_table.php` |
| `pos_transaction_items` decimal upgrade dual | ✅ already dual | `database/migrations/2026_04_19_130000_…` |
| `fbr_pos_transaction_items` decimal upgrade dual | ✅ already dual | `database/migrations/2026_04_19_120000_…` |
| Risk Intelligence JSON extract dual branch | ✅ already dual | `app/Services/RiskIntelligenceEngine.php` |
| `config/database.php` mysql block ready | ✅ | `config/database.php` |

`DbCompat` now exposes: `like()`, `dateFormat()`, `extractYear()`, `extractMonth()`, `extractDay()`, `extractHour()`, `dateOnly()`, `cast()`, `castFloat()`, `substring()`, `boolTrue()`, `boolFalse()`, `jsonExtract()`, `isMySQL()`, `isPgSQL()`.

---

## 🚦 Migration Steps (When User Approves)

### Step 1 — Backup Production Postgres
```bash
pg_dump "$DATABASE_URL" --format=custom --no-owner --no-acl -f taxnest_pg_backup_$(date +%Y%m%d_%H%M).dump
# Verify size, store offsite (S3 / external drive)
```

### Step 2 — Provision MySQL Database
- MySQL **8.0+ required** (5.x will NOT work — JSON functions and CTEs needed)
- Charset `utf8mb4`, collation `utf8mb4_unicode_ci`
- Get connection credentials: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

### Step 3 — Test on Staging First
1. Create a fresh MySQL DB
2. Set `.env`:
   ```
   DB_CONNECTION=mysql
   DB_HOST=...
   DB_PORT=3306
   DB_DATABASE=taxnest
   DB_USERNAME=...
   DB_PASSWORD=...
   ```
3. Run: `php artisan migrate --force`
4. Run smoke tests: login, create invoice, generate report
5. Fix any issues that surface (will be much fewer now thanks to dual-compat code)

### Step 4 — Data Migration
Two options:

**Option A — pgloader (recommended)**
```bash
pgloader pgsql://user:pass@host/taxnest_pg mysql://user:pass@host/taxnest_mysql
# Handles type conversions, JSONB → JSON, boolean → tinyint, sequences
```

**Option B — Manual export/import** (if pgloader unavailable)
- Use `pg_dump --data-only --inserts` then sed-edit incompatible types
- More error-prone, only as fallback

### Step 5 — Verify Data Integrity
```sql
-- Compare row counts
SELECT 'invoices', COUNT(*) FROM invoices
UNION ALL SELECT 'companies', COUNT(*) FROM companies
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'invoice_items', COUNT(*) FROM invoice_items
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs;
```
Match against same query on Postgres dump.

### Step 6 — Cutover (Production)
1. `php artisan down --message="Scheduled maintenance — back in 30 min"`
2. Final Postgres delta dump (incremental since Step 1)
3. Apply delta to MySQL
4. Update production `.env` → `DB_CONNECTION=mysql`
5. `php artisan config:clear && php artisan cache:clear && php artisan view:clear`
6. `php artisan up`
7. Smoke test: login as admin, ZIA Corp, posadmin, fbrpostest

### Step 7 — Monitor (24-48h)
- Tail `storage/logs/laravel.log` for SQL errors
- Watch slow query log
- Keep Postgres backup retained for 30 days

---

## 🔁 Rollback Plan

If anything critical breaks within 24h:
1. `php artisan down`
2. Switch `.env` back: `DB_CONNECTION=pgsql`
3. Restore Postgres dump if data was modified after cutover
4. `php artisan config:clear && php artisan up`
5. Investigate, fix, retry on next maintenance window

---

## ⚠️ Known MySQL Differences to Watch

| Behavior | Postgres | MySQL 8 |
|---|---|---|
| String comparison case | Sensitive by default | Case-insensitive default (utf8mb4_unicode_ci) |
| `LIKE` | Case-sensitive (use `ILIKE`) | Case-insensitive |
| Auto-increment | `serial` / `bigserial` | `AUTO_INCREMENT` (Laravel handles) |
| JSON column ops | `->>`, `->` operators | `JSON_EXTRACT`, `->` (similar but stricter) |
| Boolean | true / false | tinyint(1) (1/0) |
| Row size | ~8KB per page (TOAST) | 65,535 bytes hard limit |
| Partial indexes | `WHERE x IS NOT NULL` supported | NOT supported (use composite or app-level dedup) |
| Group by strict mode | Lenient | Strict — every non-aggregated column must be in GROUP BY |

`only_full_group_by` mode in MySQL is the most common surprise — if any report fails after migration, that's the first place to look. Either disable it in MySQL config or fix the query to include all selected columns in GROUP BY.

---

## 📞 Escalation

If migration runs into trouble:
1. Immediately rollback (Step above)
2. Note the exact error + which feature was affected
3. Don't try to fix forward under maintenance window pressure
