# Rider live sync upgrade

Current main uses 20s GPS / 45s upload / 20s map polling and oldest-first batches. Concurrent upload requests compare a stale model before updating the live pin. Count-based queue acknowledgement cannot identify holes in a partly accepted batch. Heartbeats re-date old coordinates as fresh GPS.

Compatibility matrix: on-duty fresh fix -> live pin advances; offline history -> original timestamps/trail only; overlapping older upload -> cannot regress pin/battery; package downgrade/suspension -> deny API and stop map polling/clear markers; old APK -> stored count unchanged; old backend -> new client retains unidentified partial batches; same-rider token rotation -> keep queue; different/unknown rider -> discard old GPS rather than attribute to another account. Manual duty, saved map style, thresholds, printers and billing are unchanged.

Implementation: five-second on-duty GPS request/upload loop, latest-by-capture-time first plus history, explicit acknowledgement identities, capture-time heartbeat, atomic conditional live-position update. Visible map polls five seconds after completion, aborts superseded requests, ignores late responses, pauses hidden tabs, retries reconnect/errors with capped backoff and cleans up on navigation. This is near-realtime polling, not a WebSocket guarantee. GPS/network/OS permission/battery restrictions can delay updates.

Local Node tests exercise actual map methods. CI must run PHP regressions (stale auth snapshot, mixed acknowledgement, suspended company), actual Android JSON-policy JUnit + debug compile and actual desktop/390px Leaflet/API polling proof. Local PHP/Gradle/Chrome unavailable. No physical phone, signed APK publication, production access or deployment claimed. App source becomes 1.8.0 (14); public download metadata remains unchanged until owner release.

## Refresh 19 October 2026

Integrated latest main `4500c7bbb79f246f9176b19fee0606203d3d2741` after #223. Retained Caller recovery, catalog/address regression journeys, public guide and pricing browser checks, and all required lanes. Resolved only additive workflow/browser conflicts and the current release announcement. No saved tenant settings, maps, package rows, billing or printer configuration changed. Full fresh exact-HEAD CI is required; prior checks on df37143 are not current integration evidence. #223 production deployment was waiting on its environment at refresh time; source integration is not a claim that deployment completed.

Refresh local verification: `bash scripts/rc-safe-run -- node --test scripts/tests/rider-realtime-map.test.mjs scripts/tests/notification-acknowledgement.test.mjs scripts/tests/catalog-search-results.test.mjs scripts/tests/delivery-address-state.test.mjs` passed 28/28. `node --check scripts/rc-browser-acceptance.mjs` and `git diff --cached --check` passed. PHP/Gradle/Chrome remain unavailable locally; CI must provide those results.
