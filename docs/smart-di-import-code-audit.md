# Smart Digital Invoicing Bulk Import — Code Audit & Implementation Plan

Issue: #185. Branch: `feature/smart-di-import-format-learning`.

## Verified existing architecture (main branch, 11 Oct 2026)

- `app/Http/Controllers/InvoiceImportController.php`: XLSX/CSV upload, mapping-needed flow, saved presets, held files, row validation, batch processing, import history and AI-assisted suggestions.
- `app/Models/InvoiceImportMapping.php`: per-company mapping/default presets already persisted.
- `app/Services/InvoiceImportService.php`: common row parser and FBR prevalidation, 10,000-row cap, aliases, actual invoice-date field, Third Schedule MRP field, and draft creation.
- `app/Jobs/ProcessInvoiceImportBatchJob.php`: queued draft generation; single attempt to avoid duplicates after partial crashes.
- `app/Http/Controllers/CsvImportController.php`: legacy CSV path; preserve it.

**Conclusion:** extend the existing importer and presets. Do not create a Coca-Cola-specific parallel invoice engine.

## Critical gaps to verify before implementing automation

1. **Source invoice boundaries.** Existing legacy CSV `process()` groups by buyer name + NTN + sale date. The new smart import must also use distinct original source invoice IDs to avoid merging multiple same-day orders for one outlet. Preserve the source invoice ID even though FBR issues its own invoice number.
2. **Idempotency.** A batch-level queued/processing transition does not prevent a repeated new upload from creating a second set of drafts, nor safe resume after partial processing. Add tenant-scoped source-key uniqueness and source-file fingerprint, linked to created draft and FBR receipt.
3. **Actual CCI structure.** A detailed Voyage invoice export contains repeating invoice headers/totals and multiple SKU lines. A column-only flat mapping is insufficient without invoice-block parsing and header inheritance. Detect report signatures without relying on filename or row position alone.
4. **SKU conversion.** Existing invoice row model expects quantity, unit EX-tax price, tax; CCI has cases/packs, source gross/discount/VAT and deposits. Convert cases to litres using verified SKU pack rules and keep source values in immutable audit records. Do not invent rates, HS codes or MRP.
5. **Exclusions.** User requests empty bottles excluded from sales. Separate packaging movements from FBR item lines, but preserve them in a non-tax audit ledger; block invoices with unexplained per-invoice deltas. Damage/negative items require explicit handling/credit note determination.
6. **Third Schedule.** Source August purchase Annex-A shows beverage `2202.1010` and water `2201.1010`, litres and 18%. This does NOT conclusively classify every October SKU; printed MRP and actual outward tax scenario require confirmation.
7. **Buyers.** Outlet code is a stable internal key only, not proof of NTN/STRN or registered/unregistered status. Require buyer details/status validation before submission.
8. **Approval.** Recognized format can auto-fill the review screen, never directly auto-submit an FBR invoice. Keep current FBR validation and approved draft lifecycle.

## Suggested delivery sequence

### PR A — format recognition (low risk)
- Add deterministic, versioned format fingerprints to existing per-company presets.
- Auto-suggest one uniquely matching preset, using normalized worksheet/header signatures and strict confidence threshold.
- Always allow manual correction, record last-used/success/approval, and reject stale or ambiguous matches.
- Tests: tenant isolation, reordered columns, renamed columns, malicious/malformed upload, template and legacy CSV regression.

### PR B — supplier-style invoice block normalization
- Add pluggable parser for repeating invoice headers, totals and nested SKUs.
- Persistent company-scoped SKU conversion/tax mappings.
- Preserve original invoice number, actual date, all source rows and excluded packaging lines.
- Per-invoice source-to-staging gross/discount/VAT/net reconciliation; ambiguous rows block submission.

### PR C — idempotent drafts and tracking
- Import receipts with unique company+source+invoice source ID/date fingerprints.
- Atomic duplicate checks across uploaded batches; partial failure retry safe.
- Map to current DI FBR validation, review/approve, submission receipt tracking.

### PR D — CCI acceptance and production guard
- Fixture-based validation using sanitized Voyage examples, sample 73-invoice dataset, expected 31 SKU codes and documented discrepancy buckets.
- Regression suite, feature flags, full test run, review and explicit approval before deployment.

## Non-goals / operational safeguards
- No production or configuration changes; no deployment.
- No silent addition of 18% on top of source VAT or wholesale prices.
- No automatic FBR posting without explicit approval.
- No assumption that every report titled `7170.xlsx` is identical.
