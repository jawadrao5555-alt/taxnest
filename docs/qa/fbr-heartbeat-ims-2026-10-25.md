# FBR fiscal heartbeat omitted IMS identity

## Original flow and reproduction

Owner screenshots show Agent1.13.23 connected/Online with the IMS section missing, while FBR Settings shows local fiscal-device mode. No production login, token, data or Agent process was accessed. The screenshots' fixed “PRA POS ID” label does not establish the company's actual `product_type`.

On deployed main `079a2d02ef7a0b06e7da3abf0fb42b0ee1510fb9`, `heartbeat()` returns early through `fbrHeartbeat()` whenever `Company::agentServesFbr()` is true. The separate FBR response omits `product_type`, `pra_connection_mode` and `fbr_connection_mode`; the preceding correction added them only to the default PRA response. Agent1.13.23 stores the actual company response as serverInfo. Its existing `imsMode()` correctly fails closed without authenticated identity, leaving Online visible and IMS hidden.

Before patching, the new real Laravel controller regression failed: expected company.product_type=fbrpos, got null (1 failed, 5 assertions). This confirms a source-level failure on the actual FBR route. It does not assert that the live company's unseen product_type was inspected.

## Smallest correction and compatibility

Add the same three existing authenticated UI identity fields to the early FBR response. Keep actual stored product_type, existing legacy pra_pos_id/pra_environment aliases containing FBR values, queue/heal results, auth and all routing predicates untouched. No migration, company setting, fiscal submission, Agent runtime, package, version or immutable release tag changes. Current Agent1.13.23/Electron42.11.16 remains unchanged; no new installer is required for this server contract correction.

Matrix: FBR fiscal-device response now carries identity; FBR cloud stays on the unchanged default branch; PRA cloud/fiscal remain unchanged; unknown/disconnected Agent response stays hidden. Two fictional FBR tenants return their own ID/name/POS values. The regression asserts no token/API key disclosure and no change to stored routing identity.

## Actual checks

- PHP8.4.27 with locked Composer dependencies, sqlite :memory:. Existing/new Feature suites in normal alphabetical order: `vendor/bin/phpunit tests/Feature/AgentHeartbeatUpdateTelemetryClearTest.php tests/Feature/FbrPosSubmissionEvidenceTest.php`: **30 tests, 180 assertions PASS**. A reverse-order combined invocation initially hit three existing schema-discovery static-cache telemetry failures; isolated PRA suite passes9/32 and normal-order combined suite passes30/180. No production cache or test assertion changed to hide this result.
- Existing actual Agent helper: `node --test pra-agent/test/ims-visibility.test.js`: **3/3 PASS**.
- Generated before and after JSON by bootstrapping the real Laravel Feature fixture and invoking the actual `AgentController::heartbeat()` on fictional FBR company data, not by constructing a substitute response. Loaded unchanged actual `pra-agent/index.html` plus its real browser helper in Chrome headless shell, with an injected IPC fixture for local UI observation only. At **1366 and390**, before response: Online but IMS hidden; after response: FBR IMS section/Install button visible, real Re-check click requests status, disconnect hides the section. Zero pageerrors in these four local component cases. No installer, native IPC, real printer or external network was invoked. Screenshots `.local/browser-evidence/ims-before/after-1366/390.png` inspected.
- Full Chrome initially failed its Unix singleton socket in this runtime. Available Chrome headless-shell successfully ran the above real component checks; this is not a supported full Laravel/MariaDB or installed Windows acceptance claim.
- Full PHP suite started separately; do not count a running log as a full pass. Fresh supported PR CI (all8 including own validate, PHP8.4/MariaDB/Node22/full browser1366+390) remains mandatory before owner readiness. Existing PR checks/gates/assertions remain unchanged.

No real installer run, physical print, customer live UI, fiscal submission or deploy claim. The recent screenshot's separate Code401 result needs its own exact error/evidence; fixing hidden IMS UI does not prove that submission succeeds. Splitting PRA/FBR installers is not necessary to correct this missing server response.
