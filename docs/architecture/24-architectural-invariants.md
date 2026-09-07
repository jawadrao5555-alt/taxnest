# 24 — Architectural Invariants

These must not be broken without an explicit owner decision and migration plan.

1. **Four product types in ProductCatalog** — verticals are not new products (`NestErps`).
2. **Guard isolation** — no cross-login between `web`/`pos`/`fbrpos`/`health` for ordinary users.
3. **One `users.company_id`** — no inventing staff multi-company pivots; groups are admin insight only.
4. **SaaS admins ≠ company users** — separate tables/guards.
5. **Impersonation dual-guard** — Exit never `session()->invalidate()`; View vs Manage modes via `readonly`.
6. **DI ↔ POS data isolation** — no shared operational tables contamination (`replit.md`).
7. **Reporting-OFF finals** — regulator mode + NULL status, never `'local'`.
8. **NestPOS whole-rupee totals** — DI/FBR keep decimals.
9. **PRA fiscal_device** — server must not direct-submit when agent handles PRA.
10. **Print `target_printer` is SNAPSHOT** — do not reinterpret after enqueue without care.
11. **Supplier ledger balance** — only `SupplierLedgerService`.
12. **Health charges** — reverse, don’t delete.
13. **Authorization style** — middleware/helpers; don’t assume Policies exist.
14. **Desktop Agent allow-listed commands only** — no remote shell.
15. **Settings preservation** — don’t silently reset company prefs on “fixes”.
16. **Printed tickets ENGLISH only**.
17. **Public repo hygiene** — secrets only in `.local/` or Environments.
18. **Live Ops NestPOS scope** — `config/live_ops.php` product_types `pos`; super_admin SaaS gate.

## Owner process invariants (`replit.md`)

- Issue log vs implement only when told  
- Current focus NestPOS unless owner expands  
- Do not propose FBR/DI advance work while streams closed
