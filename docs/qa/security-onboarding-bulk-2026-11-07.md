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

## Session revocation and impersonation continuation

Reproduced two additional failures through authenticated HTTP before fixing:

- Downgrading the persisted admin role to viewer left a pre-existing Manage-as
  grant able to POST /onboarding/skip and change the company's setup flag.
- A separate browser session captured before successful password recovery
  could still POST /onboarding/skip after the password had changed.

Impersonation now rechecks the persisted super-admin identity and the active
panel user's company on every request. A different operator, revoked role,
disabled/deleted panel user or changed tenant ends that company session;
the admin's own login and Exit remain available. Read-only writes stay blocked.
Existing targeted announcement tests now use real persisted operators and full
guard/company context rather than an unpersisted id-only placeholder. Their
zero-seen-write and popup assertions are retained.

An additive nullable users.session_generation is rotated only by successful
password recovery, inside the grant/password transaction. Login records the
current generation independently for web/PRA/FBR/registered ERP guards. The
web middleware ends stale company logins without clearing independent admin
or other-product sessions, deleting session-table rows by ambiguous user_id,
or changing API/Agent keys or pairing. Null generations preserve existing
customers until a reset occurs. The field is hidden from serialized users.
Stateless machine routes explicitly omit this session-only middleware.

Local PHP8.3.6/SQLite post-fix run: **205 tests /2918 assertions passed** across
Auth, onboarding, impersonation, Hotel native UI rendering, targeted release
history, PRA announcements, surveys, profit freezing and Security. This is
below the unchanged supported PHP8.4 contract, not native MariaDB proof.
The original old-session request is refused; a fresh login with the new
password succeeds; the independently logged-in PRA identity stays unchanged.
The native concurrent reset probe additionally requires a UUID generation
after exactly one completed reset and retains the other-account comparison.
After changing revoked impersonation exit to current-device logout (preserving
the company's own remember token), the focused impersonation suites passed
12 tests /47 assertions, including an unchanged company-user row.

Required hosted browser acceptance now includes a dedicated desktop1366 and
mobile390 journey: actual incomplete setup screen, refused completion POST,
explicit owner Skip Setup, configured completion and persisted result, actual
password recovery form in an independent browser, old-browser revocation and
fresh login. Fixtures are fictional, local-only and use no mail/fiscal call.
Syntax checks passed; actual browser/native/full-suite results must be read
for the next exact published HEAD. No local Chrome/native success is claimed.
Local browser-infrastructure check: after installing the locked Node packages,
the URL allow/refuse checks passed, but the discovery test could not bind its
Unix-domain socket (EPERM at SingletonSocket). No local Chrome executable or
supported MariaDB10.6 was available. The hosted browser and native gates remain
required and have not been weakened to accommodate this environment.

These corrections do not establish complete acceptance of every B07/B08
requirement. The unchecked role/capability matrix, two-step enrollment/recovery,
all revocation mechanisms, consent/deletion/restore, import preview/posting,
support-case lifecycle and wider UI/tutorial/notification requirements still
need their individual source reconciliation and evidence. PR remains Draft.
Owner's latest delivery decision: finish all three workstreams (#363/#364/#365),
then combine on a feature branch based on current main and perform fresh
combined full-suite/native/desktop/mobile acceptance before one owner release.
This does not authorize an agent merge to main, approval, relay or deployment.

CI iteration: run42368848487 at161b66eb062afc41f807cd9d4e2a2cff16d7e23c
failed Workflow and release safety because ci-mariadb-provision-check.sh only
searched the first120 lines of each job for its artifact uploader. Adding the
new browser acceptance moved the existing uploader beyond that arbitrary
window. Replaced the bounded line budget with exact job-block extraction;
the uploader requirement remains. Added positive long-job and negative
next-job-uploader regression checks. Those checks plus38 discovery and12
startup regression cases pass locally. The subsequent fixture socket test is
blocked by this environment's Unix-socket EPERM; hosted safety must verify the
updated HEAD. Other earlier checks do not confer readiness on the update.
