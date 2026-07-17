---
name: POS team password visibility
description: Admin-viewable encrypted copy of PRA POS team passwords — which write paths must sync it and the guard rules.
---

# POS team password visibility (owner, Jul 2026)

Owner rule: POS admin/manager must SEE team-account passwords on /pos/team, not just reset them. Hashes are irreversible, so `users.pos_team_password_enc` (TEXT — encrypted columns must be TEXT) holds a `Crypt::encryptString` copy.

**Sync rule — EVERY path that changes a POS team member's password must refresh the encrypted copy**, or the admin sees a stale password:
1. Team page create (storeCashier)
2. Team page admin reset (updateCashier)
3. POS self-service change (userProfile `change_password` action)
4. Forgot-password email reset (NewPasswordController)

**Guards on every write/read:**
- Only for roles pos_cashier / pos_manager / pos_kitchen / pos_waiter — the pos_admin (owner) account NEVER stores a viewable copy; its row shows "—".
- Every write is wrapped in `Schema::hasColumn('users','pos_team_password_enc')` (prod schema-drift: cPanel pulls code before `migrate --force` runs).
- Column is in `User::$hidden` (JSON leak guard) AND `$fillable` (mass-assignment silently dropped it on first attempt — has_enc stayed 0 with no error).
- Decrypt server-side only in posTeam, per-member try/catch (APP_KEY rotation → treated as "not stored"); view escapes with `Js::from`.

Accounts created before the feature show "Set new password to view" until a new password is set. Scope = PRA POS only (FBR POS has no team page).

**Why:** reversible password storage was the owner's explicit demand; mitigation = encrypted at rest, admin-gated page only, hidden from serialization.
