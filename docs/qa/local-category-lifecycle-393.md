# PR 393: local collection category and lifecycle coverage

## Category and commercial boundary

FBR School / Education and Hospital / Clinic now resolve to their administrative collection desks, collection/outstanding/receipt/closing tiles and category-specific setup sequence, not the Salon / Workshop work-order landing. The mapping excludes the default `service_jobs` / retail inventory modules for these standalone workflows. PRA's education/clinic service-work-order profiles remain unchanged.

This is a presentation/relevance correction, not a package migration. No pricing, limits, feature flags, subscription rows, purchased add-ons, manual overrides or grandfathered grant data are overwritten. Existing explicit extras remain recognized by the entitlement resolver. An old extra is not permission to bypass standalone workspace/fiscal route guards. Existing reports, analytics, access and other core plan gates continue to be resolved by their original subscription services.

Public FBR signup and admin New Company already expose product/category and requested package/trial choices, validate product-scoped available packages and preserve bounded trial versus paid intent. Existing transition guards are retained. This module does not assert that every requirement of billing PRs #316/#337/#374 is completed; their payment, renewal and commercial evidence belongs in the consolidated coverage register.

## Company bin and purge

The existing recoverable bin and seven-day hold remain authoritative. Moving to bin and restoring do not rewrite School/Hospital accounts, invoices, receipts, services, fee heads or closing snapshots. Browser sessions are revoked by the existing restore routine; no local financial records are recalculated.

The audited purge transaction now deletes local collection child/parent tables scoped by `company_id` before company deletion. The deletion manifest includes their row counts. Immutable SaaS audit/deletion evidence and the anti-reuse ledger retain their existing policy. If durable deletion evidence is unavailable and local collection rows exist, purge fails closed instead of leaving silent orphans. Cleanup requires an active transaction; rollback restores every deleted row. No live company or invoice is touched by implementation/tests.

## Evidence and limits

- `LocalCollectionCategoryEntitlementsTest`: local versus PRA mapping; explicit and grandfathered grants; original attributes unchanged.
- Existing category/profile/extra/URL guard tests: regression coverage.
- `AdminCompanyTypeViewerBinPostsSmokeTest`: synthetic bin, hold refusal, restore, purge, tenant isolation, retained audit metadata and transaction rollback for local collection tables.
- These are isolated SQLite endpoint/service tests. They do not constitute a production backup restore drill, physical printing proof, or official fiscal/sandbox acceptance. Native MariaDB/full final-head CI remains required for consolidated acceptance.
