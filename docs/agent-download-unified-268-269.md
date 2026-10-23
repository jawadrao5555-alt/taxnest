# Agent download and company pairing — Issues #268 and #269

**Implementation candidate; not owner-approved or released. Fresh CI/Windows acceptance pending.**

## Shared approved release
Website, PRA POS and FBR POS download routes use AgentManagementController's
same validated AgentReleaseManifest inventory. Agent Settings' manual download
now uses /download/agent instead of bypassing it through GitHub latest. Responses
set no-store, version and SHA256 headers. Invalid/unavailable canonical inventory
still returns 503; no unvalidated legacy asset is substituted. Existing validated
mirror and legacy Agent compatibility are preserved.

Release inventory remains shared/cached for **up to 10 minutes**, matching existing
rate-limit-safe behavior. New promotion/rollback can therefore take that long to
appear; no instant global/CDN freshness claim is made. Versioned GitHub asset URLs
are immutable. Existing cache entries expire normally. This PR does not introduce
an unreviewed repository override, public signing claim or new release authority.

## Company-specific first run: authenticated exchange
The shared installer contains no company key or tenant credentials. In the installed
Agent open PRA POS or FBR POS and log in as the intended company owner/admin.
The corresponding authenticated session previews the company identity; a native
confirmation is required before fresh pairing or changed credentials are applied.
A five-minute nonce is stored only as a digest in the server session, bound to
panel/user/company/key revision. Confirmation is a CSRF-protected POST serialized
by the session lock, with a DB row lock for key generation/rotation checks. It
consumes the confirmation once. Expired/replayed/wrong-company/rotated/revoked
claims fail without replacing saved local credentials. A new preview can retry.
This is an authenticated first-run exchange, not an EXE customized with a secret
or a bearer token embedded in a public download or URL.

Plan and admin/custom-access gates apply. Existing supported clients retain the
legacy /pos/desktop/agent-config endpoint; new clients do not bypass the new
confirmation when it is unavailable. Same-company/key reinstalls are a no-op.
Printer and device preferences remain intact. Manual configuration is retained.
FBR pairing does not change submission mode/reporting switches; fresh PRA keys
retain the existing printing-only default. Local Core/tenant data are not reset.

## Verification and limits
- Local Node suite: 104/104 platform-independent tests passed, including 14 new
  pairing/login orchestration cases plus the actual service-worker secret-cache bypass case. Mocked session transport is not a real
  authenticated Laravel or installed-Windows acceptance result.
- New Laravel Feature tests cover actual controller routes, first-run key/hash,
  nonce replay/expiry, changed tenant/login, role/guard denial, rotation/disable,
  existing explicit routing, plan locking/grandfathering and shared download
  version/digest headers. Supported local PHP8.4.27 with locked Composer dependencies
  passed 48 tests / 290 assertions across DesktopAgentPairing, AgentReleaseAvailability,
  AgentReleaseManifest and FbrAgentAndFeatureToggles. No platform requirement was lowered.
  Initial plan-lock fixture lacked the subscriptions table and exercised the existing
  schema-lag fallback; adding an empty real table fixed that fixture without changing
  application behavior or assertions. Forged-nonce and restricted-manager cases also pass.
- Existing release availability regressions add no-cache headers and expired
  inventory/invalid newer release checks. Full supported 1366/390 browser,
  PHP8.4/MariaDB, Node22 real Electron and all eight gates remain mandatory.
- A read-only PR-only Windows lane runs pairing/renderer/identity tests and actual
  build:win with publish never, requiring real installer/ZIP outputs. It does not
  install on a customer PC, change registry, pair a real company, or print.
- Physical Windows installation, signature/SmartScreen behavior and first-run
  pairing on an owner device remain unverified. This PR does not claim signing
  keys or universal power-loss recovery; draft #256 remains separate.
- Existing feature/* head still fails the owner handoff cursor/replit prefix.
  Do not weaken that gate or rename it blindly (open head rename closes PR).
  A safe owner-authorized linked replacement route remains a release blocker.
