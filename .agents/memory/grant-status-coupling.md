---
name: Grant ↔ company-status coupling
description: Admin subscription grants must flip company status; expired date-grants must demote; reconciliation is cron-independent.
---

# Grant ↔ company status coupling

A subscription grant/override (lifetime / temporary / grace-days / usage-free) is a SEPARATE action from company approval. Historically the grant methods only wrote the `subscriptions` table and never touched the dual company status columns, so granting access to a pending company left it stuck 'pending' (view-only, can't act). approve() correctly sets both columns; the grant methods did not.

**Rules (owner intent, Jul 2026):**
- Granting ANY override to a pending company must unlock it → status='approved' + company_status='active' (mirror approve()). Never un-suspend / un-reject a deliberately suspended/rejected company via a grant.
- When a DATE-based grant (temporary / grace) passes its `override_until`, the company must demote back to pending (BOTH columns), mirroring a fresh registration. Lifetime never expires; usage_free is invoice-count-capped (handled in hasAccess), not date-based.

**Why:** the owner treats grants as the primary unlock/lock mechanism for non-paying companies; a grant that doesn't unlock, or an expired grant that doesn't lock, reads as a bug to them.

**How it's applied:** `SubscriptionAccessService::reconcileExpiredGrants()` does the demotion. It (a) only touches currently-active, non-suspended/rejected companies; (b) re-checks `hasAccess()` so a company that has SINCE gained valid access (paid / trial / lifetime) is never locked; (c) clears EVERY spent temp/grace grant UNCONDITIONALLY (even for skipped companies) so a stale expired grant can't silently demote a paying company whose plan lapses months later. Called from `CheckTrialExpiryJob` (daily) AND lazily at the top of `AdminCompanyController::index()` so it still works when prod has no `schedule:run` cron.

**First-run caveat:** on first deploy the reconciler bulk-demotes every company with a historical expired temp/grace grant + no valid access. Intended, but happens in one shot. Demoted companies need admin re-approval/grant to unlock (CheckCompanyApproval blocks their non-GET actions except onboarding/*).
