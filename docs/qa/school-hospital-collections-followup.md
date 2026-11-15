# School and Hospital standalone collections follow-up

## Scope and release boundary

This is one follow-up draft PR to the School local-cash pilot. It extends administrative collections, not Full School ERP (#314), a clinical record system, card acquiring, bank transfers or a fiscal adapter. All fixtures use synthetic references. No live bill, payment or change to an existing customer's settings is authorized by this implementation. No merge, auto-merge or deployment is performed.

The source baseline is main `e234402c9ace49db91e1c92c7fa741c7204c2872`. Required checks must pass on the final exact head before readiness is claimed. Keep this PR draft while checks are pending or failing.

## Acceptance scope

- School: fee month/period, tenant-configurable fee heads, campus creation/setup, immutable campus/cashier receipt labels, receipt search, counted cash and closing variance.
- Hospital / Clinic: standalone patient reference, consultation/lab/procedure, recorded cash/card/bank receipts, partial payments and remaining balance, saved receipt reprint and per-campus day close.
- Registration: validate requested product/category/package on the server; no cross-product package or category tampering. Category changes require authorized SaaS administration, not a self-service company/cashier request.
- Regression: existing PRA and retail signup, checkout, fiscal identity, retry and quota behavior remain intact. Existing School fee receipts and immutable closing history are not rewritten.

## Fiscal boundary

Hard-block fiscal reporting for standalone FBR School/Hospital collection pilots, including stale database flags, automatic submission, manual retry, background jobs and agent/PRA fallback. No official school/hospital reporting contract or accepted sandbox response is established by this PR. Existing PRA tenants are outside this new standalone pilot; their working behavior must not be disabled by a broad category-name match.

Before any future fiscal enablement, require written official reporting scope/event, endpoint/version/auth/schema, fee/service tax mapping, licensed-integrator requirements, approved sandbox enrollment and an accepted synthetic submission/acknowledgement with duplicate-safe recovery. Keep credentials out of source and public CI artifacts. Do not infer compatibility from a retail endpoint or DI scenario. The fiscal follow-up remains private; no new public issue or outreach is created here.

## Verification record

Implementation and tests are in progress. Record only checks actually executed on the final code; fixture code alone is not passing runtime evidence. Browser print invocation is not a physical-printer test; recorded card/bank payments do not initiate external transactions.

### Local recovery verification (15 November 2026 UTC)

- Full PHPUnit through committed `scripts/rc-safe-run` (loopback-only egress guard): **PASS**, 6,223 tests, 53,048 assertions, 28 deprecations and 9 skipped tests. No failure remains in this run.
- Fixed a compatibility regression discovered by that full run: older FBR registration forms may submit a paid plan ID. It is now server-validated as a same-product, non-trial, sellable package; a conflicting plan name/ID is rejected. This remains a request only, not paid subscription entitlement. Existing signup atomic rollback and bounded-trial tests pass.
- Focused School/Hospital/fiscal/signup, retail receipt/retry, admin category and PRA regression suites passed. Composer locked audit returned no security advisories. JavaScript syntax and `git diff --check` passed.
- An initial unisolated full-run attempt was stopped when external PRA egress risk was flagged. It is not acceptance evidence. The completed passing run used the committed fail-closed network runner and clean environment.
- GitHub publication is blocked on an unanswered Git-blob permission prompt. No follow-up PR, remote branch or hosted CI is claimed. Local browser/native MariaDB execution remains unverified; added browser/native probes must run in hosted CI after authorized publication. Do not mark ready until these required checks pass on the exact published commit.
