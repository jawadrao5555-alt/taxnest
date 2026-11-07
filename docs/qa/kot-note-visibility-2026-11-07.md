undefined
## Full-suite readiness correction

The original supported-PHP run42391721117 executed all 5,965 tests /49,502 assertions and found one setup error: `GitHubActionsOidcVerifierTest` could not generate its per-case OpenSSL RSA key before the wrong-ref rejection was exercised. This is a fixture prerequisite failure, not permission to remove an identity/signature assertion. The test now loads an explicitly public, synthetic RSA-2048 JWK fixture and asserts key type, modulus length and real signing success. All existing allow/reject cases still execute the production verifier with real RS256 signatures and faked GitHub JWKS HTTP. No production security service or gate changes.

Local focused verifier rerun: **10 tests /59 assertions pass**, with the unchanged data-provider deprecation. The fixture is deliberately public test material and must never be used for production identity. Current-head full CI still must confirm the complete result.

## Browser readiness correction

Original hosted browser run42391721117 passed the full KOT-note desktop1366 journey and the mobile OFF/enable/ticket checks, then the mobile `page.reload` was aborted. The sale screen has its own delayed boot-fingerprint/cache-refresh navigation, which can coincide with a manual reload after this setting changes. The persisted-setting journey now uses its own authenticated context with service workers blocked, waits for the actual initial boot-check response before changing the setting, then verifies an ordinary network reload and the original KOT. It has independent full console/page/request/HTTP diagnostics; only the already-established blocked-external-resource errors are excluded. The broader existing PWA/service-worker browser journeys stay enabled and unchanged.

The same KOT-note journey also runs early in mandatory browser preflight, in addition to the full general journey, for prompt failure evidence. No existing CI test, guard or check was removed. Local Node syntax and whitespace pass; the runnable CI DAG contract passes. MariaDB helper discovery/startup static cases passed, but its local Unix-socket fixture could not start in this environment; hosted native/browser evidence remains required.
