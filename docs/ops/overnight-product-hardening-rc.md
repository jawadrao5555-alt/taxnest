# Product hardening RC evidence and ranked backlog

Date: 2026-09-14

This record separates executed evidence from static review and follow-up work.
It never represents production or customer verification. The final candidate
SHA and GitHub check URL belong in the pull request because embedding a commit's
own SHA inside that commit is impossible.

## Release baseline and safety boundary

- Baseline: `origin/main` commit `3e70490a0ebb3c5eafcf5ad08d7d662de3059ac3`
  (PR #65 squash merge).
- PR #65 source HEAD was `01a952840cb38c486bfdf804a925407061f718ca`.
- No production host, production database, live fiscal endpoint, real printer,
  customer credentials, customer data, or customer settings were used.
- The lab used loopback Laravel, disposable SQLite and fictional records.
  MariaDB could not bind a Unix socket in this execution sandbox.
- Managed Chrome refused loopback with `ERR_BLOCKED_BY_CLIENT`; therefore no
  desktop/tablet/mobile browser result is claimed. HTTP rendering, feature,
  JavaScript domain and workflow tests are the available release evidence.

## Implemented and re-tested

### Product and recipe on one page

Restaurant product create and edit now manage the product and its recipe/BOM in
one form and one database transaction. The normal path is one page and one save,
instead of saving a product and navigating to a separate recipe module. Existing
ingredients may be selected; a new ingredient may be named with canonical unit,
quantity and optional cost.

The complete recipe is parsed and validated before product, image, ingredient,
or recipe writes begin. Malformed JSON, partial ingredient rows, foreign-tenant
IDs, unsupported units, duplicate ingredient rows and invalid quantities reject
the whole request. A deliberately blank starter row still means “no recipe.”
Edit replaces the recipe atomically and does not silently retain stale parts.

Executed product/inventory group: **70 passed, 301 assertions**. It includes
create/edit rendering, malformed create/edit rollback, tenant isolation, unit
aliases, Excel recipe import, Inventory Master, Stock-In, frozen recipe
consumption and stock neutrality.

### Admin release approval resilience

The Deploy Approvals page no longer becomes HTTP 500 when GitHub eligibility is
temporarily unreachable. It retains approval history, displays an accessible
warning, disables new unverified approval selection and never creates an
approval without fresh PR/check evidence.

A credential-free immediate-dispatch seam is present but deliberately dormant.
Changing configuration alone cannot send a GitHub request. The owner-visible
status explains that scheduled OIDC pickup is active and the manual Approval
Relay Dispatch remains the emergency wake-up. Enabling immediate dispatch still
requires a separately reviewed GitHub App implementation and owner decision.

### Agent release continuity

An owner-approved PR that changes `pra-agent/**` or the Agent build workflow now
causes Owner Merge & Deploy to dispatch Build PRA Agent for the recorded exact
squash SHA. The build validates that full SHA is on `main`, binds the release to
it and refuses a version tag owned by another commit. Dispatch has bounded retry;
duplicate exact-SHA attempts serialize and do not replace an existing same-SHA
release.

This fixes the workflow gap where `GITHUB_TOKEN`-created merges do not recursively
start a second push workflow. It does not claim that Agent 1.13.4 is released:
release assets and the Build Agent run must exist first. Existing 1.13.2 clients
can only discover 1.13.4 after that successful release becomes authoritative.

All repository workflows now use `actions/checkout@v7`. Static release-chain,
owner-handoff and approval-dispatch checks pass, and all ten workflow YAML files
parse.

### Local Core edge cases

The Agent regression sweep found and fixed a real midnight edge: a waiter order
held offline could be rejected by the counter after the date changed because its
frozen business date was omitted from the held-row projection. The immutable date
now travels into the atomic settlement. LAN adapter enumeration also fails safely
instead of crashing Local Core, while the TLS pairing test uses an injected
private address in restricted CI.

Agent result: **20 test files passed; one actual Electron BrowserWindow file was
environment-skipped**. The production internet-cut harness passed with one cut
sync request, two reconnect attempts, two projected events and one local print.
The existing fail-closed duplicate-print rule remains unchanged.

The complete local PHPUnit run finished with **4,771 passed, 7 skipped and one
environment-contract failure (36,293 assertions)**. The sole failure is the
intentional `ComposerPlatformContractTest`: this sandbox runs PHP 8.3.6 while
`composer.json`, `composer.lock` and locked Symfony packages require PHP 8.4.1.
No application test failed, and the declared platform was not weakened to make
an incompatible runner appear green. GitHub validation must run on PHP 8.4.1+
before this candidate can be called merge-ready.

KOT/printing PHP regression group from the PR #65 baseline: **128 passed, 832
assertions**. Covered burst/concurrency, cashier/waiter, online/offline/reconnect,
printer routing and normalized name matching, stale/dead Agent, lost acknowledgement,
duplicate protection and tenant isolation. Local measured path: create 3.852 ms,
claim 10.493 ms, result acknowledgement 38.596 ms; dead-Agent failover 2.719 ms.

### App/update visibility regression

Existing Admin Agent health, update telemetry, app version endpoints, APK-backed
version selection, POS APK banner, Desktop Agent controls and Rider version stamp
tests pass: **48 passed, 224 assertions**. These prove server/UI contract behavior,
not installation on a real Windows fleet.

## Inventory maturity assessment

Current implementation is materially beyond a simple quantity table:

- Inventory Master Excel handles products, ingredients and recipes without
  posting stock. It supports text identifiers, aliases, row errors, ordered
  processing, tenant isolation and idempotent re-import.
- Stock-In Excel stages rows, matches code/barcode/name+unit, exposes unmatched
  rows, allows mapping/rematch and posts transactionally/idempotently. It refuses
  unsafe multi-branch ambiguity and never invents unmatched ingredients.
- Physical stock count has session create, counts, downloadable sheet, import,
  review, authorized post/cancel and PDF routes. Architecture tests cover frozen
  snapshots, deltas and idempotency.
- Transfer, receive/cancel, adjustments, low stock, wastage-relevant movement
  history and recipe-driven consumption are separate authorized operations.

The safest spreadsheet contract remains a typed workbook with stable text
codes/SKUs, explicit row type, product, ingredient, canonical unit, recipe
quantity and branch reference. Preview/staging must show row number and error;
posting must remain tenant/branch scoped, transactional and idempotent. Formula
cells, missing/wrong headers, blank or duplicate keys, ambiguous names, invalid
units, negative/non-finite quantities, locale-formatted numbers, oversized files,
cross-tenant IDs and partial rows must fail or remain unmatched—never silently
create a recipe or change stock.

## Static product-suite audit matrix

| Surface | Evidence in this RC | Classification |
|---|---|---|
| NestPOS products/recipes/inventory | HTTP rendering + feature/integration tests | Implemented and tested |
| Restaurant KOT/waiter/Local Core | PHP + Node domain/concurrency/harness tests | Regression-tested |
| Admin Deploy Approvals | Authenticated feature tests including GitHub outage | Implemented and tested; Chrome blocked |
| Agent/update endpoints and banners | 48 feature tests + release workflow statics | Contract-tested; Windows fleet pending |
| FBR POS shared update title/navigation | Existing regression coverage/code review | No new defect reproduced |
| Digital Invoicing, rider/caller/waiter apps | Route/app inventory and existing tests only | Deeper browser UX pending |
| ERP/accounting, healthcare/pharmacy | Route/permission/static inventory only | Authenticated browser UX pending |
| Public marketing website | Static inventory only | Claim-by-claim browser comparison pending |
| APIs/webhooks/queues/scheduler | Functional suite plus targeted workflow/domain tests | Automated regression gate; PHP 8.4 GitHub run required |

This matrix must not be described as a complete screen-by-screen visual audit.

## Product direction

Do not create a second competing Windows agent. Evolve the existing TaxNest
Agent into one signed NestPOS Desktop package containing Agent + Local Core for
counter/kitchen resilience. Keep waiter/mobile as an installable PWA and keep
owner/manager workflows in responsive web/PWA. This preserves one update source,
one offline authority and one printer integration while avoiding split-brain
queues and duplicate slips.

## Ranked follow-up backlog

1. On an owner-approved candidate, verify GitHub PR checks, then the exact-SHA
   Build Agent run and published 1.13.4 assets. Test a disposable Windows 1.13.2
   client through available → download → install → restart → success/failure.
2. Run authenticated Chrome desktop/tablet/mobile QA in an environment whose
   managed browser can reach the isolated loopback RC. Capture console, page,
   failed-request, overflow, focus, keyboard and touch evidence per role.
3. Run the database suites against disposable MariaDB, including concurrent
   physical count/sale/purchase and interrupted import transactions.
4. Perform the remaining surface-by-surface visual audit: public site, FBR POS,
   DI, ERP/accounts, healthcare/pharmacy, rider/caller/waiter, SaaS/company admin
   and every PWA shell. Classify every observation as reproduced, not reproduced,
   recommendation-only or blocked before changing code.
5. Only after owner security review, implement the GitHub App driver with
   installation-scoped `actions:write`, short-lived tokens, exact approval/SHA
   payloads, request-id idempotency, audit log, bounded rate limiting and a hard
   fallback to the existing OIDC relay. Never store a PAT.

## Rollback and release gate

The product/recipe change is application-only and adds no migration. Rollback is
the normal exact-SHA application rollback; existing product and recipe rows stay
compatible. Agent packaging changes are dormant until a qualifying approved PR
is merged. Immediate GitHub App dispatch remains disabled. The release is ready
for owner review only after targeted tests, all available Agent tests, asset build,
workflow static checks/YAML parse and `git diff --check` are green, and GitHub's
PHP 8.4 validation confirms the complete PHPUnit gate.
Production deployment, Windows rollout, live printing and callbacks remain
separate post-merge evidence gates.
