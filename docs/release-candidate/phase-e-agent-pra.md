# Phase E agent/PRA release-candidate ledger

UTC: 2026-09-16T10:42:34Z

Recovery note (2026-09-16T11:04Z–11:06Z): the temporary worktree and raw
logs were lost; this ledger was reconstructed from retained context on
`93de2ccf`. Historical verification counts below are observed evidence, not
recertification by this reconstructed worktree.

## Implemented

- `PraIntegrationService` now fails closed when the cache lock backend throws:
  no fiscal HTTP leg is reached, the non-fiscalised transaction returns to the
  retryable `pending` lane, and logs only the exception class.
- Added a canonical Agent release manifest contract (`schema_version`, product,
  version/tag, artifact name/SHA-256/size, source/build SHA, compatibility
  range). The server validates it against GitHub release metadata, rejects
  missing/ambiguous/extra executable archives, and returns a clear unavailable
  outcome rather than selecting the largest asset or a local/stale fallback.
- Agent update metadata is manifest-derived. Desktop verifies canonical product,
  filename, version compatibility, source/build identity and streaming SHA-256
  before extraction; a legacy mirror is used only when a separately validated
  asset has the same name/size/hash.
- Added bounded, non-sensitive heartbeat dimensions for process liveness,
  printer health, fiscal connectivity, callback backlog and sync freshness.
  They merge beneath the telemetry-owned `agent_diagnostics` key and do not
  rewrite existing printer settings or affect older agents.
- Callback delivery evidence is retained indefinitely with capped exponential
  retry scheduling; it is not dropped after 50 failed callback attempts.
- The existing SaaS-admin status surface now shows printer health, fiscal
  connectivity and callback-queue freshness. The public download page and
  SaaS-admin explicitly surface unavailable release verification rather than
  implying an update exists.
- Windows Agent build metadata now creates/uploads the canonical manifest.
  Added an Android hosted-artifact provenance guard that binds exact bytes to
  source SHA and approved out-of-band build-input inventory hash.
- The desktop package and lockfile root metadata are versioned `1.13.6` with
  `engines.node >=22.12.0`. This is the smallest unreleased patch after the
  already-released `v1.13.5`, which points at a different source SHA and has
  no canonical manifest.
- Hardened the cloud PRA transport: direct PRAL and approved relay requests
  require HTTPS peer/host verification and never follow redirects. A relay
  can only be an exact operations-configured `PRA_RELAY_TRUSTED_HOSTS` host;
  tenant URLs pointing at localhost/private IPs, HTTP, credentials, alternate
  ports, or lookalike hosts fail before a token, `PraLog`, or HTTP request is
  created. Fiscal-device mode remains a local desktop-agent path and never
  uses this server relay.
- Preserved the day-close cloud transport-failure/offline regression with an
  explicitly trusted, resolver-pinned test relay hostname. The fixture
  resolves only through operations-style `PRA_RELAY_RESOLVE` test config to a
  dead loopback socket, so it tests connection failure without legitimising a
  tenant-controlled LAN endpoint.

## Changed files

- `.github/workflows/build-agent.yml`
- `app/Http/Controllers/AgentController.php`
- `app/Http/Controllers/AgentManagementController.php`
- `app/Http/Controllers/PosController.php`
- `app/Services/AgentReleaseManifest.php`
- `app/Services/PraIntegrationService.php`
- `pra-agent/main.js`
- `pra-agent/src/agent.js`
- `pra-agent/src/callback-retry-policy.js`
- `pra-agent/src/heartbeat-diagnostics.js`
- `pra-agent/src/release-manifest.js`
- `pra-agent/test/callback-retry-policy.test.js`
- `pra-agent/test/heartbeat-diagnostics.test.js`
- `pra-agent/test/release-manifest.test.js`
- `config/services.php`
- `resources/views/downloads.blade.php`
- `resources/views/saas-admin/companies/show.blade.php`
- `scripts/android-release-provenance-check.sh`
- `scripts/apk-release-check.sh`
- `scripts/tests/agent-release-chain-check.sh`
- `scripts/tests/android-release-provenance-check-test.sh`
- `tests/Feature/AgentHeartbeatUpdateTelemetryClearTest.php`
- `tests/Feature/AgentReleaseAvailabilityTest.php`
- `tests/Feature/PraSubmitIdempotencyTest.php`
- `tests/Unit/AgentReleaseManifestTest.php`

## Verification

| Command | Result |
| --- | --- |
| `php -l app/Services/AgentReleaseManifest.php && php -l app/Services/PraIntegrationService.php && php -l app/Http/Controllers/AgentManagementController.php && php -l app/Http/Controllers/AgentController.php && php -l tests/Unit/AgentReleaseManifestTest.php && php -l tests/Feature/PraSubmitIdempotencyTest.php && php -l tests/Feature/AgentHeartbeatUpdateTelemetryClearTest.php` | exit 0 |
| `node --check pra-agent/main.js && node --check pra-agent/src/agent.js && node --test pra-agent/test/release-manifest.test.js pra-agent/test/heartbeat-diagnostics.test.js pra-agent/test/callback-retry-policy.test.js && bash scripts/tests/agent-release-chain-check.sh && git diff --check` | exit 0; 6 Node tests passed; release-chain checks passed |
| `bash scripts/tests/android-release-provenance-check-test.sh` | exit 0; exact-byte pass and intentional byte-mismatch rejection both confirmed |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Unit/AgentReleaseManifestTest.php tests/Feature/PraSubmitIdempotencyTest.php tests/Feature/AgentHeartbeatUpdateTelemetryClearTest.php` | exit 0; 92 assertions. Laravel reported 23 pre-existing `file_get_contents` warnings from the minimal test bootstrap; no test failures. |
| `node --check pra-agent/src/agent.js && node --check pra-agent/src/callback-retry-policy.js && node --test pra-agent/test/callback-retry-policy.test.js pra-agent/test/release-manifest.test.js pra-agent/test/heartbeat-diagnostics.test.js && git diff --check` | exit 0; 7 Node tests passed, including retained completed-callback replay with no print execution path. |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/AgentReleaseAvailabilityTest.php tests/Unit/AgentReleaseManifestTest.php tests/Feature/PraSubmitIdempotencyTest.php tests/Feature/AgentHeartbeatUpdateTelemetryClearTest.php` | exit 0; 105 assertions. Includes no-manifest current-release fail-closed, explicit 503 download response, and existing admin/download status-surface tests. Laravel emitted 26 existing minimal-bootstrap `file_get_contents` warnings; no failures. |
| `env -i HOME="$HOME" PATH="/nix/store/bf45nflf0wylnscwwa2xgliib91x226l-nodejs-22.22.0/bin:/usr/bin:/bin" NODE_ENV=test node --test $(find test -type f -name '*.test.js' -print \| sort)` from `pra-agent` | repeated after the `1.13.6` package/lock update: exit 1 only because the real Electron release-gate child cannot load Linux library `libglib-2.0.so.0`; 43 tests passed and the Electron harness is the sole blocked test. The harness helper was deliberately excluded because it is a `NODE_OPTIONS=--require` helper, not a standalone test. |
| `env -i HOME="$HOME" PATH="/nix/store/bf45nflf0wylnscwwa2xgliib91x226l-nodejs-22.22.0/bin:/usr/bin:/bin" NODE_ENV=test npm test` from `agent-realtime-gateway` | exit 0; all 8 gateway security/wake tests passed. |
| `env -i HOME="$HOME" PATH="/nix/store/bf45nflf0wylnscwwa2xgliib91x226l-nodejs-22.22.0/bin:/usr/bin:/bin" NODE_ENV=production npm run build:win` from `pra-agent` | repeated as `taxnest-pra-agent@1.13.6`; exit 1 after Windows Electron package/NSIS preparation, when `wine` is unavailable (`spawn wine ENOENT`). The command includes `--publish never`; no release action occurred. |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/PraSubmitIdempotencyTest.php tests/Feature/AgentReleaseAvailabilityTest.php tests/Unit/AgentReleaseManifestTest.php` | exit 0; 20 tests / 92 assertions, including untrusted relay SSRF, lookalike/HTTP/private-IP rejection, direct PRAL host allowlisting, redirect/TLS guards, and no live calls. |

| `env -i HOME="$HOME" PATH="/nix/store/bf45nflf0wylnscwwa2xgliib91x226l-nodejs-22.22.0/bin:/usr/bin:/bin" NODE_ENV=test LD_LIBRARY_PATH="<Chromium-mapped libX11:nss:glib directories>" node --test test/local-core-internet-cut.test.js` from `pra-agent` | one bounded Electron retry; GLib was supplied from existing Chromium process `/proc/40593/maps`, then Electron stopped on the next missing GUI runtime dependency: `libnspr4.so`. No further library search/install was attempted. |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/PosDayCloseAutoFinalizeTest.php tests/Feature/PraSubmitIdempotencyTest.php tests/Feature/AgentReleaseAvailabilityTest.php tests/Unit/AgentReleaseManifestTest.php` | exit 0; 41 tests / 255 assertions. This is the targeted closure after the recorded full-suite failure: the trusted/pinned dead relay takes the real cloud connection-failure branch, records its attempt, and leaves the day-close bill `offline`; security/manifest PRA regressions also pass. |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/PosDayCloseAutoFinalizeTest.php` | historical observed result: exit 0; 22 tests / 168 assertions. The restored focused regression keeps an inactive legacy relay and the chosen printer preference on fiscal-device settings save, while rejecting that same untrusted relay when cloud activation is requested. This result was not rerun after worktree reconstruction. |

## Blockers

The dependency owner installed audited dependencies after the earlier checks.
The reviewed Node 22 binary is
`/nix/store/bf45nflf0wylnscwwa2xgliib91x226l-nodejs-22.22.0/bin/node`; the
Agent build workflow now pins Node 22 as the release-build runtime. The
remaining full Electron release-gate test first lacked `libglib-2.0.so.0`.
The one bounded retry with Chromium's existing mapped GLib library directory
then exposed missing `libnspr4.so`; Windows packaging is blocked by missing
`wine`.
These are environment/toolchain blockers, not skipped tests or runtime
fallbacks. No release was dispatched, published, or deployed.

The evidence full suite remains recorded as **5012 tests, 39246 assertions,
1 failure, 7 skipped**: the sole failure was
`PosDayCloseAutoFinalizeTest::test_pra_connection_failure_finalizes_bill_as_offline_never_lost`
using an untrusted tenant `http://127.0.0.1:9` relay. It was not weakened to
accept `pending`; the fixture was changed to the explicit trusted transport
described above and the targeted proof passed. A full-suite rerun was
intentionally not performed for this bounded closure.

## Rollout prerequisite (not executed)

`v1.13.5` is deliberately unavailable to the manifest-driven server because
it has no canonical manifest and its tag does not identify this changed
desktop source. Before deploying the server-side manifest enforcement, build
and publish the reviewed, hash-verified `v1.13.6` Agent ZIP/EXE together with
its canonical `release-manifest.json`, then verify the published GitHub asset
metadata and manifest hashes. Only after that verification may the server
rollout switch clients to the new release chain. The workflow edit and this
rollout are proposed only and remain uncommitted; no tag, GitHub release,
workflow dispatch, deployment, or production call was made. Existing legacy
clients remain compatible: they receive an update only when the validated
legacy ZIP has the same canonical name, size, and SHA-256.

The GitHub workflow-tree API returned 404 / lacks workflow scope for delivery
of the active edit. The complete proposal is therefore also preserved as the
non-executable documentation copy
`docs/release-candidate/proposed-workflows/build-agent.yml`; the active
`.github/workflows/build-agent.yml` edit remains unstaged and uncommitted.