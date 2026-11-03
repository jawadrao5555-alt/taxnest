# Open POS: online sale rejected by offline asset preparation

Baseline: deployed main `85680806a3330d910fc2e7eb68131231c0b9c74f`.
Owner reports the newer Agent still opens the "Sale screen needs a fresh copy"
card. The screenshot identifies the shared service-worker recovery response;
it does not establish the installed binary version or the failing HTTP resource.
No private desktop image or customer data is copied into this repository.

## Reproduction and cause established in code

`public/sw.js` combined structural validation of fresh online sale HTML with
offline build-asset preparation. A complete, correctly marked 200 response was
replaced by a 503 recovery card if an optional worker asset fetch failed, or if
its build references used an asset origin. A slow prefetch could also delay the
navigation response. This mechanism can occur inside the Agent, which loads the
same web app in its persistent browser partition; an installer update does not
change the worker's document/asset decision.

Before the runtime change, the new test using the actual worker fetch handler
failed with:

`SW SMOKE FAIL: pra valid online document was replaced with recovery after unavailable asset preparation`

This reproduces the reported screen through a concrete code path. Identifying
which exact request failed on the owner's PC remains separate field evidence.

## Change

- Structurally valid live HTML is returned without waiting for offline asset
  preparation. The document's own startup/error handling remains active.
- Prepare offline assets and persist HTML under the real event lifetime with
  `waitUntil`; publish a copy only after its build resources are available.
- Cached HTML requires assets already in cache. Missing old hashes go directly
  to fresh HTML instead of probing obsolete resources before opening the screen.
- Cross-origin required build resources do not silently qualify an incomplete
  offline copy. Explicit re-prime uses the same validation as navigation.
- Keep existing malformed-document rejection, login/logout separation, recovery
  button, cache-storage failure tolerance and deferred service-worker activation.

## Compatibility and acceptance

| Case | Required outcome | Evidence |
|---|---|---|
| Valid PRA/FBR HTML, failed worker asset preparation | Online document opens; no unusable offline HTML | Actual worker-handler regression passes |
| Slow optional asset fetch | Navigation resolves before that fetch | Deferred-promise regression passes |
| Separate/mixed asset origins | Online remains usable; unverified offline copy rejected | Both variants pass |
| Valid offline document and assets | Existing offline sale remains available | Existing worker smoke passes |
| Obsolete hash or incomplete document | Fresh online copy or explicit offline/recovery response | Existing worker smoke passes |
| Login/logout and PRA/FBR separation | No wrong-variant or logged-out cached document | Existing worker smoke passes |
| Disabled storage / quota errors | Online sale remains usable | Existing worker smoke passes |
| Prime message | Same offline-readiness rule as navigation | Regression passes |
| Company settings, pairing and print/download routing | No write or protocol changes | No changed application/Agent/settings code; pairing worker test passes |

No financial, fiscal, tenant, numbering, bill, IndexedDB queue, Local Core or
printer configuration logic is changed. Shared PRA/FBR handling is tested because
both variants use this service worker. No schema or Agent binary/version change.

## Validation checkpoint

- PASS: `bash scripts/rc-safe-run -- node scripts/sw-smoke-check.mjs`.
- PASS: `bash scripts/rc-safe-run -- node --test pra-agent/test/pairing-service-worker.test.js`.
- PASS: JavaScript syntax, whitespace, and `bash scripts/tests/rc-ci-gates-check.sh`.
- Added `scripts/tests/sale-online-recovery-browser.test.mjs`: actual loopback
  HTTP server, actual worker and Chromium navigation, PRA/FBR at 1366/390,
  functional button, unavailable/prepared offline starts, obsolete hashes,
  malformed response and actual Try again. No production endpoint/account.
- Local real-browser run could not start: Chrome is absent. The pinned
  Playwright Chromium download failed with a truncated/non-ZIP archive. The test
  is added before the broad browser preflight in the required existing CI lane;
  no browser PASS is claimed at this checkpoint.
- Local PHP is unavailable. Required hosted full PHPUnit, MariaDB, browser and
  remaining current-head checks have not run for this change.
- Vite build is not needed: the runtime change is the directly served worker,
  with no Vite input/manifest/dependency changes.

Pending: hosted browser/full required CI and original installed-PC retest after
an owner-approved release. No merge, deployment, production access, blanket
storage reset or physical-printer fix is claimed.
