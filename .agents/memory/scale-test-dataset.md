---
name: Scale-test dataset & admin dashboard cap
description: 4500 seeded @scaletest.pk companies, incremental --more mode, purge command, test accounts, and the dashboard scale rules that came out of it.
---

# Scale-test dataset (seeded Jul 2026, expanded to 4500)

- `scripts/seed_scale_test.php` seeds 1000/product by default; `--more=N` adds N MORE per product (continues email/ntn/phone numbering from DB max, maps new rows via whereIn on this-run emails — never LIKE, which would re-attach old companies). Current: 4500 companies (1500 each di/pos/fbrpos), emails `st{product}{N}@scaletest.pk` N=1..4500 global seq, password `Scale@12345`.
- Purge everything: `env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE php scripts/seed_scale_test.php --purge` (deletes by email domain, incl. registered_credentials). Purge resets numbering to #1.
- Heavy test accounts: DI `stdi939@scaletest.pk`, POS `stpos1228@scaletest.pk`, FBR `stfbrpos2902@scaletest.pk`. Batch-2 spot accounts: `stdi3001` / `stpos3501` / `stfbrpos4001`.
- Seeder uses explicit IDs (innodb_autoinc_lock_mode=2 makes batch lastInsertId unsafe) and one shared bcrypt hash for speed.
- Seeded POS/FBR companies need `pos_setup_completed=1` (seeder sets it) or `/pos/dashboard` 302s to the first-run setup wizard (`/pos/features?welcome=1`) instead of rendering.

# Durable rules from scale testing

- **Admin dashboard must stay capped**: `SaasAdmin/AdminDashboardController` shows latest 50 companies per tab with grouped aggregate queries; the full paginated list is `/admin/companies`.
  **Why:** unbounded lists + per-company queries collapsed at 3000 companies (6s response, 4.9MB HTML, 6000+ queries). Never re-add unbounded `->get()` company lists or per-row revenue loops there. Re-verified at 4500 companies: dashboard 0.23s/278KB, companies list 0.05s.
- **DI revenue filter**: locked DI invoices = `invoices.status = 'locked'`. `fbr_status` NEVER holds 'locked' (values: NULL/production/submitted/failed) — filtering fbr_status='locked' silently yields PKR 0.
- Admin login for testing: AdminUser `admin@taxnest.com` / `Admin@12345` via `/login` (auto-detects admin guard).
- `resources/views/admin/dashboard.blade.php` (a DIFFERENT dashboard, unbounded expiringTrials/atRiskCompanies/companyScores loops) is NOT the live admin dashboard — live route uses `saas-admin/dashboard.blade.php`. If admin/dashboard.blade.php ever goes live, it needs the same capping treatment.
