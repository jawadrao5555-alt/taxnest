---
name: Company status dual-column
description: companies has TWO status columns (status + company_status) enforced by different panels — every admin flip must set BOTH.
---

# Company approval/suspension is a DUAL-COLUMN state

`companies.status` ('pending'/'approved'/'suspended'/'rejected'/'locked') and
`companies.company_status` ('pending'/'active'/'suspended') are BOTH live:

- POS/FBR/DI request gating (`CheckCompanyApproval` middleware) reads **`status`**.
- Admin listings, the legacy admin panel, and pending-company queries read **`company_status`**.

**Why:** In a Jul 2026 e2e audit, POS/FBR registrations set only `company_status='pending'`
(status stayed 'approved') so pending companies were never actually gated; and the legacy
`AdminController::suspendCompany` toggle flipped only `company_status`, which either made
suspension a no-op on POS/FBR panels or stranded a company 403-blocked everywhere after a
SaasAdmin suspend + legacy unsuspend (status stuck 'suspended' while company_status='active').

**How to apply:**
- EVERY approve/reject/suspend/activate/register write path must set BOTH columns coherently:
  approve → status='approved' + company_status='active'; suspend → both 'suspended';
  unsuspend/activate → status='approved' + company_status='active'; register → both 'pending'.
- Both `SaasAdmin\AdminCompanyController` and legacy `AdminController` have these actions —
  fix/check BOTH controllers, they are separately routed and both live.
- A drifted pair (e.g. status='approved' + company_status='pending') is the bug signature;
  an idempotent backfill migration pattern exists for coherence repairs.
