# Empty owner approval dropdown: GitHub lookup recovery

The owner reported an empty release dropdown after PR176 deployed, while PR177
was open, non-draft and had green current-HEAD checks. A prior owner handoff
reported GitHub HTTP403. That evidence does not establish the exact upstream
cause of the empty live dropdown; no production access was used.

## Reproduced behavior

Using the real eligibility service with fictional PRs and Laravel HTTP fakes:
an HTTP403 or connection failure fetching check-runs was swallowed as an
ordinary ineligible PR. The panel therefore reported no green release without
the actual availability warning. Each page load also fetched the list, fetched
each PR again, and fetched its checks. The new regression tests fail against
the old service on warning visibility and repeated-request count.

The fix reuses the list's existing branch/repository/HEAD bindings and caches
the display snapshot for 60 seconds. Upstream failures stop further check
requests in that scan, expose a fixed status-only warning, and respect numeric
Retry-After / rate-limit-reset hints for a bounded 60–3600-second display
cooldown. Once the cooldown expires and GitHub recovers, eligible PRs return.
The UI distinguishes inability to verify availability from no green PRs.

## Compatibility and release gates

| Case | Behavior |
|---|---|
| GitHub check lookup unavailable | Warning visible; no unverified candidate admitted |
| Healthy green cursor/* or replit/* release | Same selectable exact-HEAD candidate |
| Failed / pending validate | Remains excluded |
| Cached candidate, changed HEAD or checks | Approval resolves current GitHub data again; stale cache cannot authorize |
| Existing approval history | Remains displayed; no approval records rewritten |
| Tenant fiscal / printer / Agent settings | No reads, writes, migrations or contract changes |

Only display eligibility is cached. Password, fresh approval resolution,
repository/branch, validate, OIDC, exact-SHA merge and deploy gates remain in
their existing paths. The cache contains public release metadata and fixed
warnings, never upstream bodies, credentials or production data.

## Verification

- Guarded targeted PHPUnit: `DeploymentEligibilityRecoveryTest`,
  `SmartDeploymentApprovalTest`, `OwnerDeploymentApprovalRelayTest`:
  50 tests, 225 assertions, no failures; 28 existing PHPUnit deprecations.
- Actual authenticated owner GET route render verifies the HTTP403 warning and
  removes the misleading no-green-PR message. Existing one-step password and
  exact-HEAD approval tests pass.
- Local Chrome at 1280px and 390px verifies both HTTP403 and healthy states:
  disabled approval on unavailable checks; green fixture selectable after
  recovery. HTML comes from the actual authenticated Laravel route with a
  disposable SQLite fixture, then is served on loopback. Local compiled CSS
  and installed Alpine replace CDN assets for offline verification; external
  requests are blocked. This is rendered-route browser verification, not a
  live authenticated release or full interactive login session.
- `npm run build`, Pint on changed PHP files and `git diff --check` pass.
- Full guarded PHPUnit result is recorded in the PR description.

Remaining limitation: this prevents silent exclusion and reduces repeated
requests, but cannot repair external GitHub permissions or an upstream outage.
Owner deployment and live dropdown verification are still required. Do not
weaken eligibility to make a release appear during upstream failure.
