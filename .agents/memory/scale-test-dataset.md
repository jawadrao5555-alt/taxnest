---
name: Scale-test dataset & admin dashboard cap
description: 3000 seeded @scaletest.pk companies, purge command, test accounts, and the dashboard scale rules that came out of it.
---

# Scale-test dataset (seeded Jul 2026)

- `scripts/seed_scale_test.php` seeds ~1000 companies per product (di/pos/fbrpos), all emails `@scaletest.pk`, password `Scale@12345`, plus realistic invoices/transactions.
- Purge everything: `env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE php scripts/seed_scale_test.php --purge` (deletes by email domain, incl. registered_credentials).
- Heavy test accounts: DI `stdi939@scaletest.pk`, POS `stpos1228@scaletest.pk`, FBR `stfbrpos2902@scaletest.pk`.
- Seeder uses explicit IDs (innodb_autoinc_lock_mode=2 makes batch lastInsertId unsafe) and one shared bcrypt hash for speed.

# Durable rules from scale testing

- **Admin dashboard must stay capped**: `SaasAdmin/AdminDashboardController` shows latest 50 companies per tab with grouped aggregate queries; the full paginated list is `/admin/companies`.
  **Why:** unbounded lists + per-company queries collapsed at 3000 companies (6s response, 4.9MB HTML, 6000+ queries). Never re-add unbounded `->get()` company lists or per-row revenue loops there.
- **DI revenue filter**: locked DI invoices = `invoices.status = 'locked'`. `fbr_status` NEVER holds 'locked' (values: NULL/production/submitted/failed) — filtering fbr_status='locked' silently yields PKR 0.
- Admin login for testing: AdminUser `admin@taxnest.com` / `Admin@12345` via `/login` (auto-detects admin guard).
- `resources/views/admin/dashboard.blade.php` (a DIFFERENT dashboard, unbounded expiringTrials/atRiskCompanies/companyScores loops) is NOT the live admin dashboard — live route uses `saas-admin/dashboard.blade.php`. If admin/dashboard.blade.php ever goes live, it needs the same capping treatment.
