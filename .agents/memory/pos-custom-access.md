---
name: POS Team Custom Access
description: Per-member feature grants overlaying pos_role defaults — where the single source of truth lives and its scope rules
---

- **One source of truth:** `PosAccessService` — `FEATURES`, `PATH_MAP` (URL prefix → feature), `customSet()`. Nav (`$posNavCan` closure in the pos-app layout), route gate (PosAuth + PosAdminOnly expansion), and controller guards ALL go through it. Never hand-roll a second feature list.
- **Scope rules:** custom sets apply ONLY to pos_cashier + pos_manager. Confined roles (kitchen/waiter/rider/delivery/viewers) return null — their PosAuth path confinement SUPERSEDES grants. company_admin can never be restricted (no self-lockout). NULL set = exact role default (backward compatible).
- **Grants EXPAND and RESTRICT:** a cashier with a Customize tick passes PosAdminOnly AND the in-controller cashier guards, which use `posCashierBlocked()` (request-path-aware). New cashier-blocking guards must use `posCashierBlocked()`, not `isPosCashier()`, or grants silently fail there.
- **Unmapped paths always allowed** (sale screen, invoice APIs, theme/language prefs, my-profile) — billing must never break; deny-redirect targets the sale screen when dashboard is also denied (loop-proof). `pos/settings/theme` is deliberately excluded from the settings→customize mapping.
- **Rider settle path maps to `deliveries`**, not `riders` (cashiers receive rider cash). Order detail/edit lives under SINGULAR `pos/transaction/` — easy to miss next to `pos/transactions`.
- **Why:** owner wants tick-box per-member access with nav/gate/page always in sync; zero behavior change for shops that never touch it.
- **How to apply:** adding a POS page? Add its prefix to PATH_MAP + wrap its nav link in `$posNavCan(...)` with the old condition as the default arg. Unit invariants live in tests/Unit/PosAccessServiceTest.php.
