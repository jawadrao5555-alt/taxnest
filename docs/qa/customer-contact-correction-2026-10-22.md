# Correct a saved customer's name/phone from Current Order

Owner audio AUDIO-2026-10-20-19-41-15.m4a requests correcting saved delivery contacts without adding another customer. Keep the legacy name-only API and its phone-immutable contract (including FBR) unchanged. Add an explicit PRA contact-correction endpoint and attached-card Edit action. Reuse the existing customer ID, update profile name/phone, preserve address/credit/loyalty/places and historical bill snapshots. Prompts can cancel before any write.

The endpoint validates input, scopes every lookup to the current company, rejects a number belonging to another customer after normalized comparison, detects stale expected_phone, serializes correction transactions and coordinates with existing customer-create phone mutexes across local/international PK spellings. Lock timeout rejects rather than writing unlocked. Old name-only clients still cannot change phone. No customer merge, deletion, historical invoice rewrite or new setting.

Local actual UI-method tests: 3/3 pass (atomic payload/DTO sync, cancellation, rejection/retry). Pure PHP identity/create-mutex matrix passes for five equivalent number formats plus another tenant; PHP 8.3 used only for this platform-independent helper and syntax, not as substitute for supported application tests. JS syntax/whitespace/CI contract pass.

Added actual HTTP regressions: stable customer ID/count, full historical transaction attribute immutability, normalized duplicate rejection without partial write, stale identity, foreign-company 404, same phone allowed in another tenant, invalid phone. Existing legacy name-only/FBR tests retained. The real Chrome delivery journey clicks attached Edit, handles actual prompts, PATCHes fictional local API, checks updated identity/phone and captures desktop/390px evidence before existing address/settlement tests. Supported PHP 8.4/full-suite/MariaDB/browser acceptance pending CI; local supported app/browser unavailable. Not independently production-verified.

## Overnight CI follow-up

Full PHP/identity/tenant checks passed. The 390px browser case attempted the attached Edit button while still on the menu. Enter the cart using its real control before registering prompts/PATCH response; retain both prompts, actual PATCH and all later delivery-address assertions. No force click/hidden-control bypass.

Local environment recheck: loopback binding is available in this run, but supported PHP 8.4 is absent and Chrome installation failed (truncated/non-zip download). Local pure JS/syntax checks are limited evidence; supported real browser/database/full PHP acceptance remains GitHub CI, not customer production acceptance.
