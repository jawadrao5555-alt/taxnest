# Notification reliability — verification checkpoint

Base: 063859f56343fca4a0226086b6693710e3decc8e. Separate from PR144.

## Failure evidence
HTTP/source reproduction confirms that normal notices previously entered a one-at-a-time automatic popup queue. Different deployment SHAs inserted repeated customer content. Reannouncement reset the original timestamp and deleted acknowledgements. POS-first guard selection could acknowledge another session on FBR routes. Hotel's unconditional food-service audience exposed kitchen notices even without an enabled outlet. Screenshots show repeated Hotel titles; no production query was made to attribute individual historical rows.

## Implementation
- Routine notices stay in the bell; only explicit featured notices interrupt.
- Route-selected POS/FBR identity, category/family filtering and unique acknowledgements are retained.
- Customer content identity excludes SHA, publisher and date. Exact-SHA deployment receipt rows remain untouched, while customer queries choose the first published equivalent notice. Repeating a deploy cannot restart its seven-day window.
- Existing duplicate rows are backfilled with content identity, without deletion, re-dating, republishing or seen resets. A historical acknowledgement of any equivalent duplicate covers the canonical customer notice.
- Admin submissions use content-aware deduplication and a unique database publish key. Explicit reannouncement creates a new revision. Archive replaces permanent deletion: duplicate delivery stops, receipt rows, images and acknowledgements survive.
- Hotel remains hotel. Food notices also reach it only when effective plan/category-gated restaurant flags enable the outlet. Preview uses the same predicates and complete company settings.
- Featured dismissal waits for a successful server response. Failure keeps the popup open with retry; navigation waits for acknowledgement.

## Compatibility matrix
PRA and FBR panels; separate tenants; simultaneous sessions; seven routine notices; featured and normal notices; existing seen records on later duplicate IDs; repeated deployments; expired canonical content; changed content; explicit new revision; archive followed by another deployment; room-only hotel; hotel with enabled restaurant outlet; pharmacy unaffected; missing new schema retains legacy compatibility. Existing saved company/device/printer settings and wrapped TLS identity are preserved.

## Dependency remediation shared with PR144
Composer locks update Laravel, Flysystem and CommonMark; npm locks update Axios. Vulnerable node-forge is removed from runtime. Certificate generation uses Node native RSA/signing and a small DER encoder; saved PKCS1 identity/pairing pins are reused unchanged. Native TLS handshake, persistence and corrupt-identity refusal tests pass locally. Fresh Node 22 CI dependency audit, Agent tests, workflow safety, MariaDB and browser checks passed on 95da25c7d69661624fd250b193dd4ccf2ff9836c; notification implementation below requires its own exact-HEAD verification.

## Verification
Local PHP 8.3 syntax lint only; repository PHP requirement is 8.4. Full Laravel PHPUnit/MariaDB/browser execution is delegated to disposable CI and is not claimed as a local pass.
Four behavior tests execute the real featured Alpine component with failed/successful acknowledgements and in-flight requests on both panels.
New HTTP tests cover publishing idempotency, duplicate delivery, seen preservation, expiry, content changes, revision, archive and hotel module targeting.
New real browser journeys cover both panels at desktop/mobile: featured duplicate consolidation, server failure and retry, then seven routine notices across refresh.
No manual workflow rerun, production access, cleanup, merge or deploy. Draft remains until all required exact-HEAD checks and original flow acceptance pass.


## Category authorization browser test boundary
Negative category checks first assert the real authenticated HTTP denial (redirects may only remain in the correct panel). This cohort blocks Service Workers so it observes Laravel authorization and follows the real browser redirect without cached/opaque worker responses. Playwright documents missing network events when workers intercept requests: https://playwright.dev/docs/network#missing-network-events-and-service-workers.
All notification retry/refresh journeys, Hotel transactions and other browser cohorts keep Service Workers enabled. This change does not alter the production worker or claim the exact historical cause of its intermittent category navigation aborts. Offline/PWA transport behavior is a separate acceptance scope.

The audience-preview fixture now has real package/subscription tables. A regression enables stored hotel outlet flags and admin module grants, then proves a package without Restaurant still cannot receive food notices; a downgrade hides those notices while preserving saved flags.
Real simultaneous native MariaDB workers also prove one manual publication, one repeated-click reannouncement revision and an unchanged original timestamp.
