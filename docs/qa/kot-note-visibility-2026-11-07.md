# P1: order note present on receipt, absent on KOT

Owner reported a receipt carrying an order note while its matching kitchen ticket omitted it. Customer media remains private; fixtures and examples here are fictional.

## Diagnosis and boundary

`RestaurantPosController::renderTransactionKot` already maps transaction notes to `kitchen_notes`; held orders persist the same field. The shared KOT template deliberately gates order notes on `companies.kot_show_kitchen_notes` (default OFF), while per-item instructions are independent. Both universal-screen note editors previously advertised a kitchen/bill note without revealing this gate. The actual reported company's saved setting and the note-entry timing have **not** been inspected in production; the video cannot establish either. This PR reproduces and resolves the hidden-OFF configuration, not a claim that every missing-note incident has this cause.

Removing the gate or migrating every company to ON would override saved choices. Instead:

- All PRA order-note editors (cart, payment, restaurant and waiter) reveal the saved KOT note status when a nonempty note is present.
- Authorized Customize users get an explicit shop-wide enable button. Other staff get an owner/manager instruction.
- The dedicated idempotent endpoint changes only this boolean on the authenticated current company. Request-supplied company IDs and other settings cannot redirect/expand the write; the existing admin/custom-access and KOT feature gates apply.
- Failed/offline saves retain OFF and show an error. Multiple editor copies update together after confirmed success.
- Enabling does not reprint previously sent tickets or resend a changed note; the UI says so. Automatic resend could duplicate meals. Existing explicit KOT resend permissions/routing remain authoritative.

## Compatibility matrix

| Configuration | Required result |
|---|---|
| Order note + saved OFF | Visible warning, explicit recovery; no silent setting change |
| Order note + saved ON | Notes still render through existing shared KOT template |
| Per-item note + order notes OFF | Per-item instructions still render |
| Owner/admin or active Customize grant | Explicit enable permitted |
| Plain cashier / restricted manager | Enable denied; company unchanged |
| Second tenant | Current-company-only update, including spoofed request company ID |
| Compact/center/customer/margin/print-on-hold choices | Unchanged after enable and retry |
| Nine KOT styles; persisted order and transaction shim | Escaped multiline order notes visible once ON |
| Existing agents, routing and multiple printers | No payload, routing, queue, dedupe, version or printer changes |
| Already printed ticket / late payment note | Clear manual kitchen instruction; no automatic second slip |

## Local evidence

- `php vendor/bin/phpunit tests/Feature/PosKotNoteVisibilityTest.php --testdox`: **4 tests / 309 assertions PASS** on extracted PHP 8.3.6 + SQLite. Reproduces order note hidden OFF and per-item instruction visible; explicitly enables through the real authenticated HTTP route, retries, compares every other company attribute and the second company, then renders all nine designs for both order/shim contexts. Tests role denial and editor recovery visibility. Fixture failures were corrected before this result.
- Existing kitchen layout/settings set: **24 tests / 187 assertions PASS** (the combined earlier run had two failures in the then-unfinished new fixtures; those new cases subsequently passed as above).
- `node --test scripts/tests/customer-bulk-print-flows.test.mjs scripts/tests/b03-table-commit.test.mjs`: **9 tests PASS**.
- PHP syntax, Blade compilation, Node syntax, Vite asset build and whitespace check pass.
- Full `php artisan test` was started; unsupported PHP 8.3 fails the unchanged Composer platform contract. The run was then blocked by automatic approval review because it unexpectedly contacts an external PRA fiscal endpoint, outside the local-only test boundary. It was not resumed or rerun; there is no full-suite PASS. External fiscal calls must be isolated/faked in the verification environment before that lane can be safely accepted. Targeted local tests remain the completed application evidence.
- Readiness follow-up: the existing sale-screen release preflight was reproduced failing on 20 hardcoded staff-discount, proof-bill and bulk-rider labels. These labels now use en/rur/ur translation keys while preserving the English wording and behavior. `php scripts/pos-i18n-check.php` now **PASS**: PRA 377 baked keys / FBR 289, no missing keys or dynamic-access problems. No guard was changed.
- Readiness Node rerun including rider selection: **12 tests PASS**; syntax and whitespace checks pass.
- Browser bootstrap attempted: pinned Playwright download repeatedly returned truncated archives; separately extracted Google Chrome starts its version command but actual headless launch through the repository helper exits SIGSEGV in this sandbox. **No local Chrome UI pass or native MariaDB pass is claimed.**

## Required hosted acceptance

A new independent `kot-note-visibility` disposable MariaDB fixture/journey is part of mandatory browser acceptance at desktop 1366/mobile 390. It uses the real Kitchen Settings form to reproduce OFF for each viewport, types into the actual cart note editor, clicks explicit enable, checks the HTTP success and visible ON state, reads the original ticket with auto-print disabled, and verifies persisted ON after page reload. It never creates a sale, print job or regulator request. Existing browser diagnostics still fail on unexpected errors.

PR CI must verify the exact published HEAD with supported PHP 8.4, native MariaDB, browser, full suite, asset/Node and final validate checks. Opening this PR does not mean green, physical-paper verified, deployed or live verified. Owner-controlled release remains separate.
