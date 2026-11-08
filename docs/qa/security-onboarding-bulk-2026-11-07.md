# Security and onboarding bulk — P1

## Persisted customer-release authority continuation

Actual route reconciliation found that an admin-panel login alone could publish,
edit, reannounce or archive customer updates, change the What's New master switch,
and alter tutorial publication, public visibility, feature audience or role gates.
Those operations change the customer-facing release and must not be available to
support, viewer or unmapped roles—or to a formerly privileged browser after its
persisted actor is downgraded.

Every release mutation now re-reads the persisted administrator and requires the
existing `admin` or `super_admin` role. Customer-update and tutorial histories stay
readable to support/viewer accounts, but the write forms and hidden edit modals are
not rendered and the screen states that it is read-only. Existing administrators
retain the same release controls; audience preview remains a read-only calculation.

Focused actual-HTTP coverage keeps a super-admin model cached, downgrades only its
persisted row, requires every customer-update and tutorial mutation to return 403,
and proves the update, tutorial, feature setting and audit-facing history stay
unchanged. The required security browser journey now checks the real read-only
screens at desktop1366 and mobile390 and requires all release forms to be absent.
It does not publish a notice, toggle a tutorial or contact a customer. Fresh
exact-HEAD PHP8.4/full-suite/browser/final-validate acceptance remains required.

## Versioned Healthcare consent and withdrawal evidence continuation

Source reconciliation reproduced an evidence ambiguity in the patient record:
removing the final granted consent cleared the current consent timestamp and
actor, making an explicit withdrawal look the same as a patient for whom no
consent had ever been recorded. The patient row now retains the disclosure
version used for a consent decision and records the exact withdrawal time and
actor separately. A declined initial registration is not mislabeled as a
withdrawal. Re-granting consent records the new current decision and clears the
current-row withdrawal marker; the existing append-only Healthcare audit trail
continues to retain the earlier transition.

Unrelated demographic edits do not restamp consent. The additive columns are
deploy-before-migrate guarded, existing patient rows remain nullable, and no
patient, clinical, fiscal or subscription row is rewritten by the migration.
The Healthcare form displays the current recorded/withdrawn state and disclosure
version in English, Roman Urdu and Urdu. Focused actual-HTTP coverage exercises
grant, unrelated edit, full withdrawal and later re-grant through the existing
patient controller. The required general browser journey now grants and withdraws
consent through that real receptionist form at desktop1366 and mobile390, while
retaining the existing cross-branch and cross-tenant refusal assertions. Exact
updated-HEAD PHP8.4/full-suite and desktop/mobile browser acceptance remain
required; no production consent or patient data was used.

## Persisted company-lifecycle authority continuation

Actual route reconciliation found that the AdminAuth middleware proved only an
admin-panel login.  The company reject, suspend, activate, move-to-bin,
restore and post-hold permanent-delete methods did not re-read the persisted
role.  A support/viewer account—or an administrator downgraded while its
cookie remained open—could therefore change customer access or destroy
operational rows.

Those mutations now require the existing persisted `admin` or `super_admin`
roles on every request.  Cached role downgrades are refused before company,
subscription or audit state changes.  The company directory, company detail
and bin remain readable to support staff, but their lifecycle forms are
replaced by explicit read-only wording.  Existing administrators retain the
same lifecycle authority; the super-admin-only early bin-hold override stays
super-admin-only.  No subscription period, purchased feature, company setting
or retention rule was changed.

Focused HTTP regressions require a cached super-admin downgraded to support to
receive403 on suspend and reject with the complete company/subscription rows
and audit count unchanged.  A separate real-route regression requires support
to receive403 on bin, restore and permanent delete while both companies remain
in their original state.  Hosted PHP8.4/full-suite and desktop/mobile browser
acceptance for the exact updated HEAD remain required.

## Guided import concurrent-posting continuation

The existing Healthcare setup importer already required an owner-only,
tenant-sealed upload, an explicit row-by-row preview, an unchanged workbook
fingerprint and a re-analysis before posting.  It also deleted the workbook
and review evidence after a successful commit.  Source reconciliation found a
remaining posting race: two requests could both pass the review/fingerprint
checks before either deleted the file.  Natural-key upserts made simple master
data look idempotent, but staff credential creation and opening journals must
not depend on that incidental behavior.

Commit now takes an exclusive per-company/per-token filesystem claim before it
parses or writes the reviewed workbook.  A concurrent request is refused with
a visible English, Roman Urdu or Urdu message and writes nothing.  Exceptions
release the claim so the reviewed workbook can be retried; success deletes the
workbook and review before releasing, so a later replay follows the existing
file-gone path.  The claim contains only its timestamp and actor id, never
workbook rows, CNICs or credentials.  Another company still resolves the token
inside its own directory only.

Focused HTTP coverage pre-owns the exact commit claim, requires the competing
request to leave all department rows untouched, releases it, then commits the
same retained preview once.  Existing tests continue to cover preview-before-
write, file-swap detection, sequential replay, tenant isolation, owner-only
authority and per-row transactions.  This workspace has no PHP/Chrome/MariaDB
runtime; only diff and shell syntax checks are local.  Exact updated-HEAD
hosted PHP8.4/full-suite and final validate remain required, and no local or
production acceptance is claimed.

## Read-only viewer credential revocation continuation

Reproduced a cached-access gap in both Local Bills Viewer management paths and
the SaaS Archive Viewer path: changing a viewer password replaced the stored
credential, but an already signed-in browser retained access. Password changes
now rotate the existing per-user browser-session generation atomically with the
credential update. `CompanySessionGeneration` refuses the old browser on its
next request while a fresh generation works. Profile-only edits do not revoke a
session; another viewer, the company owner, the SaaS admin and stateless
Agent/API credentials are unchanged. The additive column check keeps
deploy-before-migrate and legacy schemas safe.

Focused HTTP coverage exercises both company-owner and SaaS-admin password
reset paths, exact tenant/user ownership, stale-generation refusal,
fresh-generation access, profile-only stability and a second viewer left
unchanged. The Archive Viewer fixture also requires generation rotation on a
password change and no rotation on a name/email-only edit. Exact updated-HEAD
hosted PHP8.4, browser and final validate evidence is still required. The
mandatory security journey now uses independent viewer/admin browser contexts
at desktop1366 and mobile390: the old Local Bills Viewer browser must be sent
to login after the real SaaS edit form resets its password, then the new
password must open the portal. Local
PHP8.3/SQLite viewer-only coverage passed **38 tests / 261 assertions**; the
expanded Auth, impersonation, consultant and viewer set passed **114 tests /
807 assertions**. PHP8.3 remains below the supported contract and SQLite is not
native concurrency proof. This increment does not complete the remaining
OAuth/device/consultant,
deletion/restore, import, support and wider UI checklist.

## Export fixture correction after native acceptance

Exact65b15fb6fba3c26b8f192a4adc627e38d3c4e4c3 native job128478806265
passed concurrent export expiry/history/invoice and foreign-path preservation.
Browser fixture seed failed before UI execution because invoices has no user_id
column. Removed that synthetic insert field, preserving actual migration/schema
and all browser assertions. The retention regression now inserts that same
fictional locked invoice shape against real migrations and proves its complete
row remains unchanged. Fresh exact-HEAD hosted browser/full-suite/validate still
required; the earlier fixture failure is not a product or UI acceptance pass.

## Billing authority and read-only export retention continuation

Actual HTTP regressions reproduced support/viewer/unmapped roles mutating billing,
including alternative company approval/grant/limit and legacy plan paths. Financial
mutations now re-read the persisted AdminUser and require the existing admin or
super_admin role. Cached role downgrades revoke writes; existing default admin
and owner permissions remain. Read-only billing pages retain history without
write forms. The tables-first direct-controller fixture now supplies a real
persisted administrator rather than bypassing authentication. No price or
purchased-access policy changes are introduced.

Compliance GET previously deleted expired export files and history; stale status
GET also advanced a build. GET is now read-only. Workerless builds use a bounded,
authenticated CSRF POST from the existing poller, preserving the chunk claim.
Both download paths independently enforce the original seven-day export deadline
and exact company/pack path. Expired history stays visible. Scheduled bounded
pruning removes generated ZIPs only, retains export metadata and all invoice
rows, isolates unsafe paths, skips active/locked work and rechecks under locks.
Explicit deletion likewise refuses a mismatched file path. No source/fiscal
retention or production backup/restore policy is changed.

Local PHP8.3/SQLite selected regression93tests1489assertions initially found two
old unauthenticated direct-controller fixtures; after adding the persisted actor,
capability/retention/tables-first12tests110assertions passed. Final extended
capability/retention/company-form/account-overview/plan/payment/annual regression:
**94 tests /1500 assertions passed**, including the retained default-admin paths
and refusal of foreign-path explicit deletion. Earlier combined
recovery/billing/retention121tests720assertions passed before this last path
extension; neither result is native/browser/full-suite proof. Blade compilation,
Node/PHP/bash syntax and diff checks pass. Mandatory native two-worker export
retention probe and actual1366/390 GET/POST/expiry/viewer journeys are wired into
required hosted checks. A real-ZIP HTTP workerless build test requires hosted
ZipArchive (unavailable locally). Exact updated HEAD needs fresh PHP8.4/full suite,
native, browser, Node/build and validate acceptance.

Previous exact7febb411c3d02af57709017b462c3fde9e525a01 passed all required lanes
including validate in42391564035. That proves the prior two-step increment only,
not these new changes or full remaining consent/import/support/UI scope. All
three workstreams must finish before creating the combined approval PR.

## Optional authenticator two-step implementation (7 November continuation)

Implemented on this feature branch, default OFF for existing accounts:
password-confirmed enrollment, a ten-minute setup deadline, confirmed six-digit
RFC6238 SHA1 authenticator codes, encrypted per-account keys, ten hashed
single-use recovery codes, per-record five-guess/five-minute cooldown, locked
one-use time steps, password-plus-factor disable, and append-only security
events containing no secrets. DI/PRA/FBR/Healthcare/Admin have their own guard
proofs; enabling requires no company setting or Agent credential change.
Password changes and every new login invalidate that guard's proof. Recovery
does not silently disable the enrolled factor. Manual authenticator key entry
works without an external QR or SMS/email provider.

The DI gate deliberately runs AFTER existing password-generation, persisted
impersonation and consultant-consent revocation. An initial middleware-based
implementation ran before consultant revocation; the actual revoke regression
was corrected by placing the DI gate after those web middleware. Delegated
sessions authenticate the granting admin/consenting consultant rather than
borrowing the client's factor. Enrollment/disable during delegation is refused;
existing Exit remains reachable without a factor. Other company guards keep
their existing product/role checks. Stateless API and Agent keys are unchanged;
this is browser-session two-step, not an assertion of full companion API parity.

Compatibility matrix exercised: default-OFF/legacy schema; opted-in five
panels; protected GET/POST/JSON refusal; wrong product and guest; different
account/provider; expired/password-changed setup; used time-step/recovery
replay; distributed-IP guessing; password change/new login; another active
product session; read-only impersonation; consented/revoked consultant and
delegated exit. Local PHP8.3.6/SQLite Auth + consultant + impersonation +
onboarding: **85 tests /586 assertions passed**. PHP HOTP/TOTP RFC vectors and
an independent Node SHA1 implementation agree. Syntax/diff checks pass.

Mandatory native MariaDB now runs two independent ACTUAL verification
controller workers against one authenticator step, then one recovery code;
each must produce exactly one verified session and retain the other account.
Required desktop1366/mobile390 security journeys now enroll through actual
forms, verify a genuinely fresh authenticator step, use and reject replay of a
recovery code, then disable with password + another recovery code. Fictional
keys/codes stay out of screenshots/logs. Native and real browser evidence for
this new increment is pending hosted CI, not inferred from SQLite. Local
MariaDB/Chrome are unavailable. PHP8.4/full suite and final validate must also
verify the newly published exact HEAD.

Previous published cc81de6919d65482cf2373e9e90cbbd5c29449fe passed all lanes,
including validate in run42387012247; that result does NOT cover this increment.
This does not finish the broader capability/revocation, consent/deletion/restore,
import/support, language/accessibility/tutorial/notification checklist below.
No approval-ready, deployed or production acceptance claim is made.

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

Browser iteration at e0142b7849898cd959348b2fe298bd9ccf822e3c:
native MariaDB and workflow safety passed, but the dedicated security journey
failed on both widths. Mobile pointer evidence showed the header's Download
App control intercepting the Skip Setup button; moved that form to the main
content with an explicit touch target and keyboard focus. Completion errors
are displayed as an alert. Desktop reached the recovery stage but could not
find the password field; its root cause is not yet established. Added a
navigation-path/status trace (no tokens, query strings or user data) that
refuses an incorrect recovery destination immediately, retaining all original
form/revocation assertions. Local backend regression run:8 tests/75 assertions
passed; Node syntax/diff checks passed. Original pointer and recovery failures
require fresh hosted browser evidence; no browser pass is claimed here.

The next browser trace at3c4c936dd34ef36a1824ae71f494c26c5667108e
localized recovery rejection to GET /reset-password after successful link
consumption. Link claim now explicitly preserves expires_at in its atomic
update, supporting legacy MariaDB first-TIMESTAMP ON UPDATE behavior instead
of allowing the claim to change the original deadline. Native acceptance now
starts through the actual link controller, verifies the unchanged deadline
and issued grant, then runs the existing competing final-reset workers. This
compatibility correction still requires native/browser confirmation at the
next HEAD; no confirmed root-cause or browser success claim is made yet.
Mobile acceptance checks the visible main setup heading rather than a toolbar
heading that is hidden at390px; form completion/skip/recovery and real click
assertions remain. No force click, artificial DOM unhide or gate suppression.

Full-suite iteration at da382f3fae2cf5ffdf31908e4019a2c3cfbf91cb
reached 5,975 tests but exposed one stale inventory-export fixture. The
fixture used an unpersisted admin placeholder and no current POS identity, so
the new persisted-identity revalidation correctly ended its supposed view-as
session with a302. The production middleware was not relaxed. The isolated
test now supplies re-readable current super-admin and active same-company POS
identities for both view-as and manage-as modes. Its export, unchanged session
grant and zero-write assertions remain. Focused local PHP8.3.6 evidence:
1 test /13 assertions passed. Fresh hosted PHP8.4/full-suite evidence is still
required for the updated exact HEAD.

## Record-bound reset OTP retry limit

Reproduced through actual HTTP: after five wrong OTP attempts from distinct
client addresses, the original correct code still issued a reset grant. The
existing IP/email endpoint throttle did not bound guesses against the issued
record when the client address changed.

An additive verification_failures counter now limits each unexpired issued
record to five wrong guesses. Increments and the final grant claim are
conditional atomic updates. Unscoped guesses also count for all still-active
panel codes for that address, so omitting the panel is not a bypass. Neutral
invalid-code responses remain identical. A fresh reset request invalidates the
old code and provides a new attempt budget; the high-entropy recovery link
remains an independent authorized recovery path. Updates explicitly preserve
the original expiry on legacy MariaDB TIMESTAMP schemas. The existing endpoint
throttle remains in place. Pre-migration minimal schemas retain the old route
throttle until the additive column is installed.

Local PHP8.3.6/SQLite Auth + impersonation + onboarding:55 tests/303 assertions
passed, including the original cross-client failure, unchanged deadline,
fresh-request recovery and existing link/session behavior. The mandatory
native reset probe now exercises actual OTP rejection and deadline retention
before its existing link-consumption/concurrent-completion checks. Native and
full supported PHP8.4 acceptance must verify the next published HEAD. This
does not complete optional two-step login enrollment/recovery or the other
unchecked security/onboarding requirements.

## Durable company support-case lifecycle

The existing Support Inbox was only a super-admin IMAP/SMTP reader. A reply had
no durable company ownership, case state, resolution build or reopen history,
so an email or bot response could be mistaken for operational resolution.

Inbound messages can now be linked once to an active company. The resulting
case has an explicit administrator owner, open/resolved/reopened state and an
append-only event timeline. Resolution is a separate authenticated action that
requires both an exact build/release identifier and evidence note; sending an
email does not change status. Reopening retains the previous resolution build,
timestamp and timeline. Persisted super-admin identity is re-read for every
case mutation, so a cached role cannot continue writing after downgrade.
Mailbox availability is not required to view or operate the durable case queue.
No email is sent by these lifecycle actions and no company/product setting is
changed.

Local PHP8.3/SQLite focused evidence: **20 tests / 94 assertions** across the
existing mocked mailbox plus real-schema case creation, duplicate source
idempotency, active/deleted company scope, explicit ownership, reply-not-resolve,
resolution, reopen, retained history and cached-role revocation. PHP/Blade/Node
and shell syntax plus diff checks pass. The required MariaDB lane now races two
actual resolution controller workers and requires exactly one resolution event,
then a retained reopen timeline and unchanged second-company case. The required
desktop1366/mobile390 security journey resolves and reopens separate fictional
cases through the actual admin UI. These native/browser checks remain pending
until the exact published HEAD completes hosted CI; no local MariaDB/Chrome
equivalence is claimed.

The remaining consent/deletion/restore, import preview/posting, broader
OAuth/device revocation, accessibility/tutorial/notification and backup-restore
evidence checklist remains open. This increment is not full #365 readiness.

The first exact-HEAD hosted browser run for the Local Bills Viewer revocation
journey reached the real administrator page but stopped before submitting the
password change: the test locator matched both the update form and the delete
form because those routes intentionally share the same URL and differ by the
spoofed HTTP method. The journey now selects the Alpine edit form explicitly
(`x-show="edit"`). Node syntax and diff checks pass locally; a fresh hosted
desktop1366/mobile390 run is required before this slice is accepted. No
application authorization, route, assertion or production state was changed
to address this test-harness ambiguity.

## Cached Agent-device revocation acceptance

The Desktop Agent's known-good cache is only a shared-IP brute-force throttle
exemption; authorization still re-reads the company key and enabled/deleted
state on every background poll. New negative coverage first authenticates and
proves the cache exists, then rotates the company key: the cached old key is
refused immediately while the replacement succeeds. A separate sequence
proves disable and soft-delete also refuse the previously healthy key on the
next poll. No pairing, printer, offline record or company setting is changed by
the implementation; these are disposable tests of the existing runtime path.
This closes the Desktop Agent cached-key slice only. OAuth/viewer/consultant
and other companion/background revocation acceptance remains part of the
broader open matrix.

## Switched consultant principal revocation

The delegated consultant guard already rechecked the client link, consultant
profile and client company on every switched request. It did not recheck the
originating consultant user row because the browser is authenticated as the
client administrator during the switch. Disabling that original user could
therefore leave the delegated session usable while its link and profile stayed
active.

The guard now requires the persisted consultant user to remain active on every
request. A focused actual HTTP regression switches into a fictional client,
disables the original consultant account from outside that browser identity,
and requires the very next request to clear the delegation, log out completely
and retain the forced-exit audit. Existing link revocation still restores an
active consultant to their own account. No client, company, link, Agent key or
business record is changed by the middleware correction.

This closes the switched consultant-principal slice only. OAuth refresh,
remaining companion revocation and the broader security/onboarding checklist
remain open until their exact runtime paths and hosted acceptance are complete.

## Company lifecycle revocation for Desktop Agent credentials

The Desktop Agent middleware already re-read key rotation, `agent_enabled` and
soft deletion for every request, but it did not apply the company lifecycle
state which the browser and DI API guards enforce. A cached healthy Agent key
could therefore keep polling after either status column was changed to pending,
suspended, rejected or inactive.

The middleware now re-reads both persisted lifecycle columns after proving key
possession and refuses every explicit inactive state before setting the request
tenant. The known-good cache remains only a shared-IP rate-limit exemption and
never authorizes the request. Legacy installations which used `approved` in
`company_status` remain compatible. Focused actual-route coverage authenticates
first, retains the known-good cache, then checks every inactive value in each
column and requires immediate refusal followed by recovery only after the state
is restored. No key, pairing, printer, company state or business row is changed
outside the disposable test database.

Hosted PHP8.4/full-suite acceptance is required for the published exact HEAD.
This closes the company-lifecycle Agent slice only; OAuth refresh and the wider
companion/security/onboarding checklist remain open.

## DI API dual-column lifecycle revocation

The DI API re-read its company on every request, but its rejected check covered
only `status` and neither lifecycle column handled `inactive`. That allowed a
valid machine key to continue through the read-only status endpoint when the
administrator persisted one of those states in the other authoritative column.

The middleware now applies pending, suspended, rejected and inactive to both
persisted lifecycle columns before tenant binding or telemetry. Existing
structured errors remain and an explicit `company_inactive` response covers the
new state. Actual HTTP coverage exercises both columns and every inactive value
against the status endpoint, then restores the fictional company between cases.
No subscription, invoice, API key or business record is changed outside the
disposable test database. Hosted PHP8.4/full-suite acceptance is required for
the published exact HEAD; other companion and OAuth acceptance remains open.

## Reporting OAuth cached-authorization revocation acceptance

The read-only reporting connector already re-reads the persisted super-admin
actor for every bearer request and token exchange. Acceptance now exercises
that same authority at the token endpoint: an explicit connection revocation
refuses its unused refresh token, a persisted owner-role downgrade refuses both
the existing access token and unused refresh token, and a code issued before
the downgrade cannot be exchanged afterward. Failed exchanges do not create a
replacement grant or mark the untrusted code as consumed.

These are actual authorization-code and refresh-token HTTP paths with fictional
companies and no external connector or message. Existing PKCE, one-use refresh
rotation/replay revocation, resource binding and read-audit tests remain in
place. Hosted PHP8.4/full-suite acceptance is required for the exact published
HEAD. This closes the reporting OAuth cached-authority slice only; broader
companion revocation and the remaining security/onboarding matrix stay open.

## Shared service-worker browser race correction

The first OAuth-acceptance HEAD passed native MariaDB and the non-browser
guards, but its browser job failed before the dedicated security journey. The
shared PRA/FBR service-worker recovery test changed Chromium back online and
immediately started its malformed-response case. Chromium can dispatch the
page's online recovery reload after `setOffline(false)` resolves, so that late
reload raced the next navigation and replaced the expected recovery response.

The test now completes one explicit healthy online reload before deleting the
sale cache and serving malformed HTML. The malformed document must still return
the real service worker's 503 recovery screen, its Try again action must still
load a repaired document, and saved local state must remain intact. No service
worker, application route or assertion was weakened. Node syntax and diff
checks pass locally; a fresh hosted real-Chromium run is required.
