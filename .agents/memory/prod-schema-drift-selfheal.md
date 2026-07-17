---
name: PROD schema drift & self-heal migrations
description: Why admin/SaaS pages 500 only on the owner's cPanel PROD, and the idempotent self-heal pattern to fix it
---

The owner's production (cPanel MySQL) schema drifts from dev: an add-column migration can be recorded as "Ran" in the `migrations` table without the column actually existing (squashed history, partial failure, or a guard like `if (!hasColumn('a'))` that wraps several sibling columns and skips them all once `a` exists). Result: pages 500 on PROD only — dev is fine.

**The rule:** every add-column migration must wrap EACH column in its own `Schema::hasColumn` guard (never group siblings under one guard), and `down()` must guard drops too. For pages that crash on PROD, add a fresh idempotent "ensure columns exist" self-heal migration (new timestamp so PROD's `migrate --force` runs it) that re-checks every column the page touches.

**Why:** because old migrations are already marked Ran on PROD, editing them does nothing there — only a NEW migration file executes. The self-heal migration is the only reliable way to add columns PROD is missing.

**How to apply:** when a SaaS-admin / billing page errors only on PROD, suspect missing columns first. There is a recurring family of these files (`add_all_missing_columns`, `add_missing_columns_from_production`, `ensure_*_columns`). Also make Blade null-safe: dates via `optional($x)->format(...)`, and guard controller queries that filter on a possibly-missing column with `Schema::hasColumn(...)`.

## PRIMARY TOOL since Jul 2026: `schema:selfheal` (manifest-driven, whole-DB)
- `php artisan schema:manifest` snapshots the HEALTHY dev schema (every table's columns + full CREATE DDL) into committed `database/schema-manifest.json`; `php artisan schema:selfheal [--dry-run] [--table=]` compares the live DB against it and ADD-ONLY heals: missing columns get the exact dev type/null/default (AFTER-positioned), missing tables get created from DDL. Never modifies/drops existing things; idempotent; always exits 0 (partial heal must not abort a deploy); failures logged.
- A one-time wrapper migration runs it during `migrate --force`, so the owner's standard deploy heals in one pass. For FUTURE drift: regenerate the manifest in dev (after migrations run), commit, deploy, then run `php artisan schema:selfheal` on prod by hand.
- **ORDERING RULE (architect):** on prod ALWAYS `migrate --force` FIRST, manual `schema:selfheal` SECOND — several older migrations call Schema::create without hasTable guards; if selfheal creates their table first, those pending migrations fail "table already exists".
- **Silent no-op trap:** the wrapper swallows CommandNotFoundException (e.g. stale optimized autoloader on cPanel missing the new command class) — after deploy verify the `schema:selfheal via migration:` line in laravel.log, or run `php artisan schema:selfheal` by hand once.
- Guards learned: auto_increment/generated columns are skipped (need real migrations); NOT NULL without default is added NULL-able (strict-mode safe); TEXT/BLOB/JSON never get DEFAULT clauses; MySQL-only (no-ops on other drivers).
- **Why manifest, not models:** Eloquent models don't declare column types — the healthy dev information_schema is the only complete, typed source of "expected" schema.
- Hand-written `ensure_*_columns` migrations are still fine for surgical page fixes, but the manifest pass covers the whole DB at once.

**Corollary — access-gating boolean columns must backfill UNCONDITIONALLY.** When a new boolean column gates access/billing (e.g. a `*_setup_completed` flag that redirects un-configured tenants into a setup wizard), the migration's backfill of existing rows to the SAFE value must run on EVERY `up()` — `DB::table('x')->where('flag', false)->update(['flag' => true])` — NOT only when the column was just added (`if ($added) {...}`). On drift-prone PROD the column may already exist all-FALSE (partial/earlier deploy, or marked Ran without the backfill); an `if ($added)`-gated backfill is then skipped and EVERY live tenant gets trapped behind the gate. The `where(flag,false)` keeps it idempotent and leaves genuinely-new post-migration rows at their default so the gate still fires for them. **Why:** an `if ($added)` backfill passes on dev (column freshly added → backfilled) yet silently traps all tenants on a drifted PROD where the column pre-exists.

## Column DEFAULT drift on prod
- Drift is not only MISSING columns — a column can exist on prod with the WRONG DEFAULT (pos_products.show_on_sale ended up DEFAULT 0 on cPanel MySQL; migration defines default(true), dev correct). Every create path relying on the DB default (CSV import, quick-create, auto-create) then writes the wrong value → whole shop's sale grid empty.
- **How to apply:** never trust DB defaults on prod — set business-critical booleans EXPLICITLY in every ::create(); fix via idempotent migration that re-asserts the default (guarded ALTER) + a one-time heuristic backfill (e.g. flip only companies where >=90% of >5 active products are affected, so deliberate per-item settings survive).
- Diagnosis pattern: newest form-created rows correct, older/imported rows wrong = default drift, not user action.
