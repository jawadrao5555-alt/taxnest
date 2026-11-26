# Laboratory ERP — approved implementation roadmap

## Architecture
One laboratory core inside the existing TaxNest ERP Healthcare vertical. Hospital-owned and standalone laboratory organizations use the same domain services, tables and permission checks. Standalone is an organization/package preset, not a new product discriminator or duplicated clinical backend. Existing hospital records and subscriptions must remain compatible.

## Phase boundaries
1. Foundation: audit existing schema/routes; organization and entitlement boundaries; shared laboratory workspace; Modern sidebar, Classic quick launch, Fast Reception workspace; operator preferences and existing light/dark and language support.
2. Patient and test catalogue, rates, packages, reception, billing, partial payments, refunds, cash closing.
3. Sample accession, barcode, collection, rejection, recollection, transport and worklists.
4. Result parameters, validated reference ranges, verification separation, amendments, critical alerts, report templates and PDF.
5. Panels, reference labs, collection centres, inventory, purchases and consumption.
6. Shared double-entry ledger, department costing, receivables, payables, financial statements.
7. Fiscal and tax: applicable IMS route, reconciliation, tax classification, withholding and income tax working. Keep dispatch disabled until authorized category contract and acceptance evidence exist.
8. Compatible analyzer interfaces, quality control, secure patient portal, PHC records.
9. Universal external integration: authenticated API, webhooks, staged imports, mapping, replay protection and reconciliation. PathCare adapter only after authorized API documentation; not a dependency for phases 1–8.
10. Integrated and standalone acceptance, regression, security, performance and printing.

## Initial source findings (main inspection)
- App/Support/NestErps.php registers Healthcare under the existing ERP product.
- App/Support/HealthPanel.php recognizes lab and hospital organization types.
- App/Services/HealthModuleService.php already defines lab module, lab/account defaults for standalone lab, and package/owner intersection.
- App/Services/HealthAccessService.php includes lab technician and lab capabilities; verify actual action-level enforcement and add independent verification permissions before clinical workflows.
- App/Services/HealthChargeIngestService.php currently ingests visits, pharmacy, admission charges and operations; laboratory charge ingestion is a later explicit integration, not an existing implementation.
- tests/Feature/HealthPilotJourneyTest.php provides an existing hospital journey and reconciliation reference.
- Issue #344 mandates isolated fictional fixtures, test-first validation and owner-controlled release. Coordinate with #378 and current fiscal backlog; do not assume standalone Hospital POS acceptance proves ERP lab fiscal acceptance.

## Phase 1 acceptance
- Same laboratory workspace for entitled hospital and standalone lab; standalone navigation excludes irrelevant hospital modules unless explicitly entitled and enabled.
- Deny direct routes when module disabled, package disallows access, tenant/branch mismatches or role lacks capability.
- Three layouts share routes and authorization; layout choice never grants capabilities. Preserve existing preferences and language behavior.
- No destructive migrations, historical backfill, live patient data, regulator calls, or changes to unrelated products.
- Fictional hospital and standalone fixtures; verify entitlement ON/OFF, downgrade, direct URL denial, tenant separation, desktop/mobile navigation and existing hospital journeys.
- Record exact tested commit and test results before opening reviewable phase PR; each later phase gets its own focused PR. No merge or deployment without owner approval.

Phase 1 introduces the shared Lab workspace and three session-scoped layouts. It does not create orders, samples, results, or payments. Clinical work starts in Phase 2.
