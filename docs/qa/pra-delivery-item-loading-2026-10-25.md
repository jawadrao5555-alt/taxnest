# PRA mixed-delivery item loading — 25 October 2026

Read-only owner-approved Live Ops Diagnose run 40214782554 / report 01M5T0DHW1P1W90BF93HHHQD2F found repeated PosTransaction.items LazyLoadingViolationException. The bounded log tail counted 19 errors; the top-level three operational errors are separate print/update incidents, not a count of HTTP 500s. No direct production access or customer data used for tests.

## Reproduction / fix

Synthetic local PHP8.4.27 sqlite regression with Model::preventLazyLoading enabled:
- Two Local provisionals plus two Final historical mixed snapshots: apiProvisionalBills throws at PosController's delivery_items mapping.
- Two pending plus two delivered historical mixed snapshots with rider collection amounts: actual GET /pos/deliveries returns500 / the rendered Blade throws the same items exception.

After explicit batched loadMissing on only the relevant scoped bill collections, both tests pass. Keep production's lazy-loading guard enabled, avoid globally eager-loading every PosTransaction, keep legacy/empty list behavior, scopes, fiscal snapshots, totals and settings. Board includes non-null collection snapshots because that is the renderer's predicate. Explicit loading also protects mixed single/bulk return guards; the guard decisions are unchanged. Bulk regression uses multiple bills so Eloquent's production-like hydration guard is actually exercised.

## Compatibility / checks

- Local and Final bills, multiple records, pending/history tabs, empty lists and old schemas covered by new plus existing tests.
- Foreign company rows excluded; full transaction snapshot byte comparisons prove no mutations on list GETs or refused mixed bulk return.
- Original two failures reproduced before patch, then passed after: 2 tests /14 assertions.
- Relevant popup, board, return, settlement, stream-scope and delivered-by suite: 62 tests /363 assertions pass;28 existing deprecations.
- Actual existing Chromium journey extended with synthetic historical Local/Final fixtures: request popup, open Deliveries page and verify line labels, screenshot1366/390, return to New Sale, retain all existing cart/KOT/payment actions. Local full Chrome/MariaDB not available; mandatory isolated CI must execute it.
- npm run build, Node browser-helper syntax, PHP fixture syntax and git diff --check pass.
- Full local suite attempted using the committed rc-safe-run egress/environment guard; result recorded in PR when complete. Do not bypass isolated network protection.

## Limits

This fixes reproduced mixed-delivery read/guard failures matching the production exception class. It does not prove every reported500 has the same cause, or independently reproduce the Windows Agent Open POS failure. Agent targets /pos/invoice/create, but no Agent binary change/release is justified by these samples. Separate auto-update errors report replacement of a running executable failed; no restart, update, print replay or live mutation performed. No claim of live resolution until owner deployment and live verification.
