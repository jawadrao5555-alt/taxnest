---
name: Company hard-delete purge coverage
description: Admin bin destroy relies on DB FK cascade — every NEW company-scoped table must either add the FK or be added to the forceDelete purge list.
---

# Company hard-delete purge coverage

**Rule:** Admin bin "destroy" (`AdminCompanyController::forceDelete`) cleans related rows two ways: (1) DB-level `ON DELETE CASCADE` FKs on `company_id` (older tables like pos_transactions, pos_products, restaurant_floors have this), and (2) an explicit application-level purge list inside `forceDelete` (wrapped in one DB::transaction) for tables created WITHOUT the FK. Any NEW table carrying `company_id` must land in one of the two — otherwise hard-deleting a company leaves orphan rows forever.

**Why:** Jul 2026 live test found orphan rows in pos_riders / pos_rider_settlements / pos_day_close_reports / pos_deals after a company hard delete — those newer migrations used plain `unsignedBigInteger('company_id')` with no FK, and nothing else cleaned them.

**How to apply:**
- When adding a company-scoped table, prefer `foreignId('company_id')->constrained()->cascadeOnDelete()`; if the FK is skipped deliberately, add the table to the purge list in `forceDelete`.
- Child tables without `company_id` (e.g. pos_deal_items → deal_id) need a parent-id purge BEFORE the parent rows go.
- Deliberate EXCLUSIONS from purge (never delete): `audit_logs` (immutable SHA256 chain), `registered_credentials` (blocks re-registration), `hs_*` logs (global HS intelligence).
- Purge entries are guarded by Schema::hasTable/hasColumn so deploy-before-migrate is safe.
- Audit query for orphans: rows whose `company_id NOT IN (SELECT id FROM companies)`.
