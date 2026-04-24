# Production Sync Package — 2026-04-24

This package syncs new data from staging to Hostcry production MySQL.

## What's Included

| File | Purpose | Rows |
|---|---|---|
| `00-PREFLIGHT.sql` | Pre-checks (READ-ONLY) | — |
| `99-SCHEMA-FIXES.sql` | ALTER TABLE if columns missing | — |
| `01a-punjab-plus-company.sql` | New company (PUNJAB PLUS) | 1 |
| `01b-punjab-plus-user.sql` | Owner user (KHALID) | 1 |
| `01c-punjab-plus-branch.sql` | Head office branch | 1 |
| `01d-punjab-plus-subscription.sql` | 14-day trial | 1 |
| `03-zia-invoices.sql` | ZIA's today invoices | 79 |
| `04-zia-invoice-items.sql` | Invoice line items | 81 |
| `999-VERIFY.sql` | Post-migration verification | — |

All INSERTs use `INSERT IGNORE` — running twice will NOT create duplicates.

## Step-by-Step Procedure

### 1. BACKUP FIRST (mandatory)

On Hostcry cPanel:
- Go to **Backup Wizard** → **Partial Backup** → **MySQL Databases**
- Download the .sql.gz file BEFORE running anything below
- Or via SSH:
  ```bash
  mysqldump -u USER -p --single-transaction --routines DBNAME > backup-$(date +%Y%m%d-%H%M).sql
  ```

### 2. Upload SQL files to server

Via cPanel **File Manager** → upload all files in this directory to a temp folder like `/home/USER/sync-2026-04-24/`.

Or via SCP/FTP.

### 3. Run preflight checks

Open cPanel **phpMyAdmin** → select your DB → **SQL** tab → paste contents of `00-PREFLIGHT.sql` → Execute.

**Stop and report** if any check returns unexpected result.

### 4. Apply schema fixes (only if needed)

If preflight step 7 shows missing columns:
- Run `99-SCHEMA-FIXES.sql` via phpMyAdmin SQL tab
- Note: requires MySQL 8.0.29+ for `IF NOT EXISTS`. Older MySQL — comment out columns that already exist manually.

### 5. Run inserts in order

Via phpMyAdmin SQL tab (one file at a time):
1. `01a-punjab-plus-company.sql`
2. `01b-punjab-plus-user.sql`
3. `01c-punjab-plus-branch.sql`
4. `01d-punjab-plus-subscription.sql`
5. `03-zia-invoices.sql`
6. `04-zia-invoice-items.sql`

Or via SSH (faster):
```bash
cd /home/USER/sync-2026-04-24/
for f in 01a-punjab-plus-company.sql 01b-punjab-plus-user.sql 01c-punjab-plus-branch.sql 01d-punjab-plus-subscription.sql 03-zia-invoices.sql 04-zia-invoice-items.sql; do
  echo "Running: $f"
  mysql -u USER -p DBNAME < "$f"
done
```

### 6. Verify

Run `999-VERIFY.sql`. All counts must match expected values.

### 7. Login Test on Live

Open https://taxnest.com.pk/pos/login → login with:
- Email: `hassankhan21500@gmail.com`
- Password: `Admin@12345`

Should redirect to restaurant POS screen.

Open https://taxnest.com.pk/login → login with ZIA admin → check today's invoices visible (IDs 593–671).

## Rollback

If anything goes wrong:
```bash
mysql -u USER -p DBNAME < backup-YYYYMMDD-HHMM.sql
```

## Notes

- `INSERT IGNORE` skips rows whose primary key OR unique key already exists. Safe to re-run.
- This package does NOT include MALIK CHICKEN BROAST POS transactions or FBR POS test transactions — those companies are staging-only and not yet on production.
- This package does NOT modify any existing production rows. Only ADDs new ones.
- PRA production token is included in plain text inside the company INSERT — handle the SQL files securely.
