# Phases C and H — Hotel canonical resolver and service workflows

**Recorded UTC:** 2026-09-16T10:18:00Z
**Baseline:** `0677abbc3fec912b3011dd6449be6a17263db84d`
**Scope:** Hotel shell canonical-category hotfix; shared POS service work-order
engine; marketed-category coverage contract; work-order mobile forms and
tenant/branch/role tests.

## Delivered source changes

### Phase C — Hotel

- `app/Services/HotelShell.php`
  - Uses `PosFeatureService::profileCategory()` for native Hotel recognition,
    including a valid legacy `pos_type=hotel` record with no stored
    `business_category`.
  - Keeps an explicitly non-Hotel category, an unknown category, and a
    Hotel record with rooms switched off outside the Hotel shell.
  - Does not write category, feature, restaurant, room, permission, outlet or
    checkout-policy settings.
- `tests/Feature/HotelCategoryNativeUiTest.php`
  - Ports the reviewed remote hotfix tests for canonical legacy recognition,
    valid fallback refusal and preservation of saved settings.
  - Existing coverage in this file exercises front desk, housekeeping-only,
    outlet-cashier, denied user, separate tenant, separate branch and the
    separated Restaurant Outlet.

Remote hotfix review evidence: sanitized
`/tmp/taxnest-rc-evidence/github-baseline.json`, commit list
`42769c6d`, `c52eecd7`, `0288a777`. The source change was ported manually;
no cherry-pick was used.

### Phase H — category-native services

- `app/Services/PosServiceWorkflowProfiles.php`
  - Retains and tests the original 12 typed profiles.
  - Adds typed operational records for the 18 previously billing-only service
    profiles: Gym, Event Management, Travel Agent, Property Dealer,
    Advertising, IT Services, Security Services, Clinic POS, Education,
    Consultant, Architect, Construction, Manpower, Warehouse, Media
    Production, Entertainment, Financial Services and Other Service.
  - Each has a distinct record noun/prefix, permitted detail fields, required
    operational fields, lifecycle, terminal closure and cancellation path.
  - Adds an explicit inherited action contract (`create`, `transition`,
    `invoice`, `report`) for every profile. This preserves the established
    manager/cashier permissions for the original 12 and permits a future
    profile override without silently widening an unknown action.
  - Company administrators/POS administrators, managers and cashiers can use
    the standard action contract; confined roles remain denied. `PosAuth`
    evaluates `pos_custom_access` first, so `service_jobs` remains the
    feature-level route gate even where the action contract allows a role.
- `app/Services/PosServiceWorkOrderService.php`
  - Rejects creation that bypasses the form without the profile's required
    operational fields; only declared detail keys are persisted.
- `app/Http/Controllers/PosServiceWorkOrderController.php`
  - Enforces per-action role policy for creation, transition, billing and CSV
    reporting after its company/category context is resolved.
  - Adds company-and-active-branch-scoped search by job number, customer or
    work title. Existing company/category/branch query scoping remains intact.
- `resources/views/pos/service-work-orders/create.blade.php`
- `resources/views/pos/service-work-orders/index.blade.php`
- `resources/views/pos/service-work-orders/show.blade.php`
  - Render required profile fields, touch-friendly phone entry and
    width-constrained inputs.
  - Make critical action controls full-width on phones, retain desktop layout,
    and add work-order search.
  - Hide create/transition/invoice controls where the action-role policy denies
    them; controller checks remain authoritative.
- `app/Services/PosCategoryProfiles.php`
  - Adds semantic landing, workflow-engine and regression-fixture contracts
    for category profiles. `general` is explicitly a catalogue/billing fallback
    and is not counted as a marketed profile.
- `tests/Unit/PosCategoryProfilesTest.php`
  - Generated guard requires exactly 46 commercial profiles and verifies each
    profile's landing surface, vocabulary family, module policy, workflow
    classification and regression fixture. Service classifications must map to
    executable typed profiles.
- `tests/Feature/PosServiceWorkOrderTest.php`
- `tests/Feature/PosServiceWorkOrderInvoiceTest.php`
- `tests/Feature/PosServiceWorkOrderNativeUiTest.php`
  - Covers initial state, permitted fields, required-field refusal, legal
    lifecycle, terminal invoice conversion, audited original 12 profiles,
    all 18 added profiles via actual GET/POST form routes, mobile form markers,
    custom-access menu/report visibility and denial, company isolation and
    branch isolation.

## Browser fixture handoff

`scripts/rc-browser-fixture-seed.php` is the data-only synthetic seeder used
by the RC browser harness. It refuses unless all of the following are true:

- `RC_BROWSER_FIXTURE_FRESH=1` confirms a reset occurred;
- the driver is MariaDB/MySQL over `127.0.0.1` and a
  `/tmp/taxnest-rc-mariadb-*` socket;
- the database is exactly the allowlisted `taxnest_rc_browser` (not a prefix
  match and never a configured application database);
- all required migrated tables exist; and
- its output is the temporary
  `/tmp/taxnest-rc-browser-<token>/fixture.json` path.

The fixture password is generated in memory and written only to that `0600`
temporary JSON. It is neither committed, printed, copied into this report nor
reused from a developer or customer account. Every named login uses the
reserved `@rc-browser.invalid` domain and all company, room, work-order and
customer labels are explicitly synthetic.

The generated contexts are:

| Journey | Synthetic role/context | Surfaces / behavior |
|---|---|---|
| `hotel-owner` | POS company admin | Hotel front desk |
| `hotel-manager` | POS manager, `hotel` + `hotel_housekeeping` grants | room management |
| `hotel-housekeeping` | POS cashier, housekeeping-only grant | housekeeping board |
| `hotel-outlet` | POS cashier, orders-only grant | separated Restaurant Outlet |
| `hotel-denied` | POS cashier, dashboard-only grant | Hotel denied |
| `hotel-admin-manage-as` | synthetic platform super-admin | full Manage-as POST into Hotel front desk |
| `service-work-orders` | Event Management POS admin | actual Event Plan create → five legal transitions → fiscal bill linkage |
| `service-work-orders-manager` | POS manager, `service_jobs` grant | board and CSV report |
| `service-work-orders-denied` | POS cashier, dashboard-only grant | board and CSV report denied before controller |
| `health` / `fiscal` | isolated Health owner / FBR POS owner | their product-native dashboard/create surfaces |

The fixture uses separate `readOnlyJourneys` and `transactionalJourneys`.
`scripts/rc-browser-acceptance.mjs` rejects a fixture that names
`service-work-orders` without a transactional `serviceWorkflow`; the browser
then fills the real work-order form, submits each declared legal transition,
creates the bill, revisits the original job and requires the fiscal-link
marker. It performs this path at both desktop and mobile viewports.

The RC CI/runner owns loopback server startup and egress controls. The main
agent should perform one browser pass after fixture setup with the exact
temporary `RC_BROWSER_FIXTURE` printed by the setup command; this agent did
not start an application server or browser.

The setup wrapper defaults to a separate
`/tmp/taxnest-rc-mariadb-browser-<uid>` instance on loopback port `33126`, so
it cannot share the migration/concurrency lab's default socket or port.

## Local verification

All tests below used disposable SQLite `:memory:` with database and PostgreSQL
environment variables removed. No regulator endpoint, production system or
credential was used.

| UTC | Command | Result |
|---|---|---|
| 2026-09-16T10:00Z | `git diff --check` and `php -l` for changed PHP source/tests | exit 0 |
| 2026-09-16T10:02Z | `env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit tests/Unit/PosCategoryProfilesTest.php tests/Feature/PosServiceWorkOrderTest.php tests/Feature/PosServiceWorkOrderInvoiceTest.php tests/Feature/HotelCategoryNativeUiTest.php --testdox` | exit 0; 116 tests, 9,817 assertions |
| 2026-09-16T10:04Z | `env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit tests/Feature/PosServiceWorkOrderNativeUiTest.php --testdox` | exit 0; 18 tests, 477 assertions |
| 2026-09-16T10:05Z | `env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit tests/Unit/PosCategoryProfilesTest.php tests/Feature/PosServiceWorkOrderTest.php tests/Feature/PosServiceWorkOrderInvoiceTest.php tests/Feature/PosServiceWorkOrderNativeUiTest.php tests/Feature/HotelCategoryNativeUiTest.php --testdox` | exit 0; 136 tests, 10,332 assertions |
| 2026-09-16T10:16Z | `env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit tests/Feature/PosServiceWorkOrderTest.php tests/Feature/PosServiceWorkOrderNativeUiTest.php --testdox` | exit 0; 91 tests, 1,576 assertions |
| 2026-09-16T10:16Z | `git diff --check`; `php -l scripts/rc-browser-fixture-seed.php`; `node --check scripts/rc-browser-acceptance.mjs`; `bash -n scripts/rc-browser-fixture.sh` | exit 0 |
| 2026-09-16T10:25Z | `git diff --check`; `php -l scripts/rc-browser-fixture-seed.php`; `php -l app/Services/PosServiceWorkflowProfiles.php`; `php -l app/Http/Controllers/PosServiceWorkOrderController.php`; `node --check scripts/rc-browser-acceptance.mjs`; `bash -n scripts/rc-browser-fixture.sh`; `bash scripts/tests/rc-browser-acceptance-check.sh` | exit 0; fixture contract checks passed |

## Remaining limits / handoff

- This scope did not run a full PHPUnit suite, MariaDB-native suite, browser
  pass, or live/hardware acceptance journey. The browser fixture is ready for
  the one CI-owned loopback pass; no browser outcome is claimed here.
- No migration is required: the shared work-order schema stores details as
  declared JSON keys and lifecycle status as existing strings.
- No tenant configuration was rewritten. Restaurant Outlet separation remains
  untouched and Hotel stays/folios remain its own engine.