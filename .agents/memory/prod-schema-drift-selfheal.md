---
name: PROD schema drift & self-heal migrations
description: Why admin/SaaS pages 500 only on the owner's cPanel PROD, and the idempotent self-heal pattern to fix it
---

The owner's production (cPanel MySQL) schema drifts from dev: an add-column migration can be recorded as "Ran" in the `migrations` table without the column actually existing (squashed history, partial failure, or a guard like `if (!hasColumn('a'))` that wraps several sibling columns and skips them all once `a` exists). Result: pages 500 on PROD only — dev is fine.

**The rule:** every add-column migration must wrap EACH column in its own `Schema::hasColumn` guard (never group siblings under one guard), and `down()` must guard drops too. For pages that crash on PROD, add a fresh idempotent "ensure columns exist" self-heal migration (new timestamp so PROD's `migrate --force` runs it) that re-checks every column the page touches.

**Why:** because old migrations are already marked Ran on PROD, editing them does nothing there — only a NEW migration file executes. The self-heal migration is the only reliable way to add columns PROD is missing.

**How to apply:** when a SaaS-admin / billing page errors only on PROD, suspect missing columns first. There is a recurring family of these files (`add_all_missing_columns`, `add_missing_columns_from_production`, `ensure_*_columns`). Also make Blade null-safe: dates via `optional($x)->format(...)`, and guard controller queries that filter on a possibly-missing column with `Schema::hasColumn(...)`.
