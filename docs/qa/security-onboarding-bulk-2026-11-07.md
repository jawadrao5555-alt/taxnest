# Security and onboarding bulk — P1

Base: 363a0d6823eae113336a6cd6069f160fc7a1a441 (merged #362). Priority: P1, owner requested 7 November 2026.

This is an implementation workstream opened as a Draft. Initial content records source reconciliation and acceptance; runtime implementation and acceptance are not complete. No approval-ready or deployed claim follows from documentation CI.

Source trackers: #337 / #320 / #321. Delivery order: third batch; security regressions found during the first two batches take precedence.

## Current source evidence

- Auth/NewPasswordController and PasswordResetLinkController already implement product-scoped reset identity and session-bound reset state. Review expiry/replay/session invalidation end to end before changing working recovery.
- Existing middleware/model helpers provide authorization; do not introduce a parallel role framework.
- OnboardingController and product routes already exist. Guided setup must preserve saved configuration and avoid duplicate opening balances/stock.
- Existing read-only/manage impersonation, support inbox and targeted notices are foundations, not proof of completion of every #320/#321 requirement.

## Implementation acceptance

- [ ] Reconcile password/OTP expiry, single use, retry limits, session invalidation, two-step challenge/recovery and existing-owner compatibility with actual code and negative tests.
- [ ] Verify owner/billing/support/operations/release capabilities across routes, APIs, exports, jobs and company/branch boundaries.
- [ ] Verify read-only impersonation and actor/target/reason audit; no hidden writes.
- [ ] Verify OAuth/viewer/device/consultant revocation through refresh, cached access and background paths.
- [ ] Reconcile data consent, retention, deletion/hold/export and backup restore evidence; distinguish synthetic restore drills from production backup verification.
- [ ] Complete consistent product/package/trial/setup journeys and fictional import preview with explicit posting/idempotency.
- [ ] Reconcile company-linked support case ownership, timestamps, resolution build and reopen behavior; bot replies do not prove resolution.
- [ ] Verify targeted release/read state, tutorial visibility, keyboard/mobile/accessibility and language rules while retaining preferences.
- [ ] Exercise wrong product, wrong tenant, expired/replayed token, revoked role, read-only session, existing setup, retry and mobile390 flows.

## Verification and preservation

Only local disposable or hosted synthetic data; fake mail/HTTP delivery. Preserve existing password-copy policy pending explicit review rather than silently changing owner decisions. No production credentials/browser/data, real messages, fiscal submissions or release actions. Required PHP8.4/native and real desktop/mobile authenticated journeys must pass before approval readiness.

## First implementation increment — expiring single-use reset grants

Reproduced through actual HTTP: consuming a valid link and waiting 16 minutes
still allowed password change because the final form checked only session
token/email. OTP/link consumption also used read-then-unconditional-update.

OTP and link now claim a grant through an atomic conditional update. A random
session token is stored as a SHA256 grant hash in the existing OTP row; the
original deadline remains authoritative. Final password change locks that row
and the selected product user, validates deadline/identity/hash and marks the
grant completed in the same transaction. Issuing a new reset revokes older
grants. Existing product identity and approved viewable-password behavior stay
in the original path. A nullable additive migration adds only grant metadata;
old in-progress forms without a grant must request a fresh reset.

Local PHP8.3.6/SQLite: 24 tests /156 assertions passed for actual HTTP expiry,
copied-session replay, new-request revocation, valid OTP and link flows,
cross-product identity, expired-form redirect and locale regressions. Added a required native MariaDB
probe with two independent final-password controller workers sharing one grant,
asserting one completion and another account unchanged. Native result and
supported PHP8.4/full suite/browser acceptance are pending hosted verification.

This increment does not complete broader role/impersonation/revocation,
consent/backup, two-step, onboarding, support or UI accessibility acceptance.
The original checklist remains open. No production or release actions used.

## Onboarding completion validation

Reproduced using the actual authenticated DI route with an active fictional
owner: POST /onboarding/complete marked an empty setup complete and redirected
to the dashboard. Completion now checks the same branch/token/product/invoice
milestones as the setup display under the company lock. Incomplete setup
returns to onboarding; owner Skip Setup remains available. Completion/skip
require the existing company owner role or company-bound super admin. Other
staff cannot change the company's setup flag. Existing completed companies
retain their state; repeat completion does not create stock/invoices.

Local real-schema HTTP coverage passed 3 tests /23 assertions for incomplete
setup, staff denial, owner skip, second-tenant isolation, existing configured
records and repeated completion. Draft invoice presence suffices: no fiscal
invoice is submitted to complete setup. Full authenticated browser acceptance
of the updated completion flow and broader guided setup remain pending.
