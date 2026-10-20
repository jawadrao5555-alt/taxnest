# Directory keyboard/mobile follow-up

PR #210 already supplies a six-theme/light-dark/1366px/390px contrast browser
matrix. This PR extends that existing actual authenticated journey rather than
claiming the live mobile review happened. It adds explicit accessible names to
search/product/status controls, verifies keyboard Tab order and visible focus,
checks document overflow for every matrix cell, and trial-clicks each existing
company action without submitting it. The existing composited contrast/native
option/placeholder and GET-filter assertions remain.

Compatibility: only labels change in product markup; form names, query values,
status actions, tenant scopes and saved themes remain unchanged. Fixture covers
multiple synthetic companies/statuses. No real customer data is involved.

Local `node --check scripts/rc-browser-acceptance.mjs` and `git diff --check`
passed. Supported PHP/Chrome are unavailable locally (Chromium download failed),
so actual runtime acceptance is pending exact-HEAD disposable browser CI. Light
and dark classes in CI do not establish that a light toggle exists in production.
