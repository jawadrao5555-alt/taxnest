# Category-native coverage audit — 15 Sep 2026

This ledger prevents a category name, sample item list, or renamed generic page
from being reported as a completed business workflow. `Hotel / Guest House` is
the accepted reference architecture and is not rebuilt by this change.

## Status rules

- **COMPLETE**: operational lifecycle exists in addition to shared billing,
  inventory, tax and accounting foundations, with contract/integration tests.
- **PARTIAL**: a material native lifecycle is still absent, or an implemented
  lifecycle has not passed every required promotion lab.
- **BLOCKED**: implementation could not be validated safely in the current lab.
- **NOT STARTED**: no category-specific foundation exists.

## Complete operational categories

| Category | Native operational surface | Evidence |
|---|---|---|
| restaurant | tables, KOT/KDS, recipes, kitchen, delivery | existing focused suites |
| cafe | counter/KOT, recipes, kitchen, loyalty | existing focused suites |
| quick_service | counter/KOT, kitchen, delivery | existing focused suites |
| hotel | rooms, stays, housekeeping, folio, day-close | PR #71; preserved unchanged |

## Implemented operational cores — PARTIAL promotion

These are more than renamed profiles: each has a distinct noun, number prefix,
native data fields, ordered lifecycle, tenant/branch-stamped board, append-only
timeline, custom-access gate and CSV report. Lab 1 and isolated SQLite Lab 2
pass. They remain **PARTIAL**, not COMPLETE, until every required promotion lab
is green on the target branch. A terminal work order can now mint **one** NestPOS
fiscal sale via `PosServiceWorkOrderInvoiceService` (`pos_transaction_id`), while
keeping operational `PREFIX-######` job numbers separate from `P`/`L` invoice
series. Cancelled jobs and non-terminal stages refuse billing; re-issue is
idempotent.

| Category | Implemented native operational core |
|---|---|
| salon | appointment → check-in → in-service → completion; stylist/station |
| laundry | receipt → tag → cleaning → ready → delivery; pieces/care |
| workshop | receive → diagnose → approve → repair → ready → delivery; vehicle/registration/fault |
| repair_service | receive → diagnose → approve → repair → ready → delivery; device/serial/fault |
| courier | booking → pickup → transit → out-for-delivery → delivery/return; recipient/destination/weight |
| cargo | booking → receipt → transit → destination → delivery/return; consignee/destination/weight-volume |
| rent_a_car | reserve → check-out → return → completion; vehicle/driver/odometer |
| equipment_rental | reserve → check-out → return → inspection → completion; equipment/serial/condition |
| tailoring | receive → measure → cut → stitch → trial → ready → delivery; garment/measurements/fabric |
| printing | quote → artwork → proof → approval → print → ready → delivery; size/material/copies |
| photography | booking → shoot → edit → proof → approval → delivery; venue/crew/deliverables |
| cleaning | booking → team assignment → work → inspection → completion; site/team/scope |

## Partial categories — exact missing domain layer

| Categories | Existing safe foundation | Material missing workflow |
|---|---|---|
| marquee, catering | KOT, recipes, inventory, delivery, service catalogue | event quotation, venue/calendar capacity, function sheets and staged deposits |
| gym | customers, service billing, loyalty | memberships, attendance, trainer/class scheduling and expiry/renewal |
| event_management | service billing, inventory | event project, vendor/tasks, venue calendar and staged costing |
| travel_agent | customer/service billing | passenger PNR/document checklist, supplier settlement and itinerary lifecycle |
| property_dealer | customer/service billing | property inventory, leads, viewings, deal/commission lifecycle |
| advertising | service billing | campaign/placement schedule, approvals and media spend |
| it_services | service billing | ticket/SLA/project milestones, time entry and recurring support |
| security_services | service billing | guard roster, site deployment, shifts and incident log |
| clinic | service billing, consumable inventory | patient encounter, practitioner schedule, clinical record and privacy model |
| education | customer/service billing | students, enrollment, class/fee schedule, attendance and results |
| consultant, architect | service billing | engagement/project, milestones, time/expense and deliverable approval |
| construction | service billing, inventory | project/site, BOQ, progress measurement, subcontract and retention |
| manpower | service billing | worker roster, placement, timesheet, payroll/vendor settlement |
| warehouse | inventory, barcode, service billing | locations/bins, inbound/outbound jobs, pallet occupancy and storage aging |
| media_production | service billing | production project, shoot/edit/review deliverables and rights |
| entertainment | service billing, loyalty | venue/capacity, ticket/admission validation and sessions |
| financial_services | service billing | engagement deadlines, maker-checker, document vault and compliance controls |
| other_service | generic service billing | intentionally unclassified; needs owner-selected workflow before specialization |
| general | universal modules remain visible | intentionally unclassified; no safe category-native assumptions |

## Retail and pharmacy profiles

| Status | Categories | Notes |
|---|---|---|
| COMPLETE shared retail lifecycle | retail, grocery, wholesale | barcode, purchasing/stock, bulk units/pricing, sale, returns, delivery, reports and day-close are the native lifecycle; the category profile supplies correct defaults/units/examples. |
| COMPLETE specialized | pharmacy | batch/expiry, loose sale, prescription capture, barcode, inventory and pharmacy vocabulary already exist. |
| PARTIAL subtype depth | clothing | size/colour variant matrix and exchange reason analytics absent. |
| PARTIAL subtype depth | electronics | serial/IMEI, warranty and repair/RMA absent. |
| PARTIAL subtype depth | hardware | length/area cut conversion and contractor quotations absent. |
| PARTIAL subtype depth | autoparts | vehicle-fitment catalogue and VIN/compatibility lookup absent. |
| PARTIAL subtype depth | bakery | production batches, shelf-life/waste and pre-order capacity absent despite kitchen/recipe support. |
| PARTIAL hybrid depth | hybrid_cafe_retail | both retail and food modules exist; a unified stock-to-recipe transfer and consolidated operational dashboard remain absent. |

## FBR POS critical track

The local fiscal-device path now records only safe fingerprints/metadata and
shows three independent states: TaxNest requested environment, local IMS
acceptance, and central FBR/Tax Asaan verification. Local `Code 100` plus a
non-empty number is required for `submitted`; missing, malformed or wrong-code
success responses enter a no-resend verification hold. No existing invoice is
backfilled, resent, edited or externally verified by this change. Digital
Invoicing is outside scope and untouched.

## Lab promotion

1. **Lab 1 — contract/schema:** profile uniqueness, lifecycle validity,
   category relevance, custom-access map/backfill and FBR callback matrix.
2. **Lab 2 — isolated integration:** SQLite tenant/branch/series/timeline and
   migration tests; MariaDB parity/concurrency must pass before final promotion.
3. **Lab 3 — browser:** fictional tenants only, desktop and mobile journeys,
   screenshots and error diagnostics; never production/customer data.

Current run result: Lab 1 passed; SQLite integration passed; MariaDB portion of
Lab 2 is BLOCKED by this container; Lab 3 is BLOCKED before page load because
Cloud Browser cannot reach executor loopback. Therefore none of the 12 new
operational cores is promoted to COMPLETE in this ledger. Passing source-level
tests alone is not called live verification.

## WSL promotion command

On a clean WSL checkout of this PR branch, all remaining local promotion gates
are orchestrated by one fail-closed command:

```bash
bash scripts/category-native-wsl-verify.sh
```

The runner requires PHP 8.4.1+, PDO SQLite/MySQL, Composer, Node/npm, local
MariaDB and Chromium. It refuses non-loopback database hosts, creates and
destroys only `taxnest_category_lab`, runs Lab 1, SQLite Lab 2, MariaDB parity,
fictional desktop/mobile browser journeys and finally the repository's full
test command. It does not approve, merge, deploy or contact a fiscal endpoint.
