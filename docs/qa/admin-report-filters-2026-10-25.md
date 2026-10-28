# Admin dashboard company IDs and product-specific reports — 2026-10-25

## Status
Owner-authorized scope record only. No application fix, migration, browser acceptance or deploy readiness is claimed by this commit. Implementation and fresh mandatory CI remain required.

## Reported evidence and confirmed source
Owner screenshots show dashboard DI/PRA/FBR company tabs without an explicit Company ID, and report pages with a shared generic filter/table.
At baseline 6e23a10665494f557e7435b357932deb402acf13:
- resources/views/saas-admin/dashboard.blade.php shows company name/email and NTN or POS ID, without an explicit Company ID.
- resources/views/saas-admin/dashboard-detail.blade.php exposes all five product choices on product-specific invoice reports, combines status/role and fiscal fields, and shows subscription dates on invoice rows.
- POS mode and fiscal status are free-text inputs.
- app/Services/AdminDashboardDetail.php already restricts invoice queries by company product. Preserve this isolation, count/revenue definitions and CSV safety.
- DI row fiscal_status currently reads fbr_status; the screenshot's production value must not be relabelled as successful reporting without tracing its model meaning.

## Required correction
1. Display explicit Company ID in all three dashboard company tabs, including mobile. Keep NTN and POS ID separate and unchanged.
2. Add a Reports link for each company, opening its matching DI/PRA/FBR report with company_id already selected. Do not impersonate the company.
3. Fixed-product reports must show their fixed product rather than offer unrelated DI/PRA/FBR/Health/ERP choices. Generic cross-product admin reports retain their valid selector. Preserve server-side product isolation for crafted URLs and exports.
4. Replace POS Invoice Mode and Fiscal Status text boxes with selectable filters using actual supported values and clearly labelled All. Audit stored legacy/null values and current enums before choosing options; do not silently conceal legitimate historical values.
5. Use relevant labels: Invoice status for DI, Transaction status for POS, Role only for user reports, and subscription status only for subscriptions.
6. Separate transaction/invoice lifecycle, reporting status, fiscal number and configured invoice mode. FBR/PRA mode or completed/locked alone does not prove reporting. Keep unknown/missing/inconsistent historical evidence explicit, without changing stored data.
7. DI Production/Sandbox is environment, not reporting success. Trace actual DI fields and submission evidence before defining its filters. Label its fiscal number clearly as FBR invoice number.
8. Hide subscription dates on invoice/transaction reports; retain them where relevant. Use matching table headers, cells and empty-state colspans.
9. Give DI/PRA/FBR report-specific explanatory text. Keep relevant search, company, date, amount, status, sort, row-count and filtered CSV controls.
10. Make Company ID explicit on detail rows rather than relying on di #7 / fbrpos #27 shorthand.

## Acceptance matrix before release readiness
- DI, PRA and FBR dashboard tabs at desktop 1366 and mobile 390 show correct explicit IDs, preserve NTN/POS ID and route to the correct company report.
- Each invoice report exposes only relevant controls/columns; selected values persist through Apply, pagination, CSV and Reset.
- Reported, local, pending, failed, null/legacy and inconsistent fiscal evidence are tested using fictional fixtures and actual model semantics.
- A second company in each product and deliberately mismatched product/company URLs cannot leak rows, totals, counts, CSV or invoice previews.
- Existing all-thirteen-screen AdminDashboardDetailTest behavior, admin guard, read-only preview, formula-safe CSV, aggregate semantics and date/amount validation remain covered.
- Original screenshot failures are reproduced before and retested after implementation using the actual rendered Laravel view in loopback Chrome at 1366/390; screenshots and diagnostics recorded.
- Focused PHP tests and fresh exact-head mandatory eight CI lanes, including own validate, must pass. Do not substitute documentation-only CI for functional acceptance.

## Boundaries
No fiscal submission, invoice rewriting, archive/deletion, tax/stock/numbering/settings change, production access, merge or deploy is authorized by this report cleanup. Notification targeting is a separate task/PR. Existing local/fiscal decision and consent-archive design remains separate. No owner release approval is implied.
