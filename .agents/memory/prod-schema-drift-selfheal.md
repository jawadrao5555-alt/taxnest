---
name: PROD schema drift & self-heal migrations
description: Why admin/SaaS pages 500 only on the owner's cPanel PROD, and the idempotent self-heal pattern to fix it
---

The owner's production (cPanel MySQL) schema drifts from dev: an add-column migration can be recorded as "Ran" in the `migrations` table without the column actually existing (squashed history, partial failure, or a guard like `if (!hasColumn('a'))` that wraps several sibling columns and skips them all once `a` exists). Result: pages 500 on PROD only — dev is fine.

**The rule:** every add-column migration must wrap EACH column in its own `Schema::hasColumn` guard (never group siblings under one guard), and `down()` must guard drops too. For pages that crash on PROD, add a fresh idempotent "ensure columns exist" self-heal migration (new timestamp so PROD's `migrate --force` runs it) that re-checks every column the page touches.

**Why:** because old migrations are already marked Ran on PROD, editing them does nothing there — only a NEW migration file executes. The self-heal migration is the only reliable way to add columns PROD is missing.

**How to apply:** when a SaaS-admin / billing page errors only on PROD, suspect missing columns first. There is a recurring family of these files (`add_all_missing_columns`, `add_missing_columns_from_production`, `ensure_*_columns`). Also make Blade null-safe: dates via `optional($x)->format(...)`, and guard controller queries that filter on a possibly-missing column with `Schema::hasColumn(...)`.

**Corollary — access-gating boolean columns must backfill UNCONDITIONALLY.** When a new boolean column gates access/billing (e.g. a `*_setup_completed` flag that redirects un-configured tenants into a setup wizard), the migration's backfill of existing rows to the SAFE value must run on EVERY `up()` — `DB::table('x')->where('flag', false)->update(['flag' => true])` — NOT only when the column was just added (`if ($added) {...}`). On drift-prone PROD the column may already exist all-FALSE (partial/earlier deploy, or marked Ran without the backfill); an `if ($added)`-gated backfill is then skipped and EVERY live tenant gets trapped behind the gate. The `where(flag,false)` keeps it idempotent and leaves genuinely-new post-migration rows at their default so the gate still fires for them. **Why:** an `if ($added)` backfill passes on dev (column freshly added → backfilled) yet silently traps all tenants on a drifted PROD where the column pre-exists.

## Column DEFAULT drift on prod
- Drift is not only MISSING columns — a column can exist on prod with the WRONG DEFAULT (pos_products.show_on_sale ended up DEFAULT 0 on cPanel MySQL; migration defines default(true), dev correct). Every create path relying on the DB default (CSV import, quick-create, auto-create) then writes the wrong value → whole shop's sale grid empty.
- **How to apply:** never trust DB defaults on prod — set business-critical booleans EXPLICITLY in every ::create(); fix via idempotent migration that re-asserts the default (guarded ALTER) + a one-time heuristic backfill (e.g. flip only companies where >=90% of >5 active products are affected, so deliberate per-item settings survive).
- Diagnosis pattern: newest form-created rows correct, older/imported rows wrong = default drift, not user action.
