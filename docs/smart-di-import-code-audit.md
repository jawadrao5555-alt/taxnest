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

## PR #186 integration slice (11 October 2026)

The controller now scopes all format candidates and editable preset data to the
active company. `FEATURE_SMART_DI_IMPORT_MAPPING` is **off by default** and is
read through `config/features.php`; no tenant settings or saved presets are
migrated or overwritten. Enable only in disposable QA until owner review.

When enabled, a unique normalized mapped-header match prefills the existing
mapping screen (including saved defaults). It does not call apply/process or
FBR. Multiple matches do not select a preset; users choose a preset to review
or map manually. Missing/duplicate source columns remain unresolved. Preset
review submits the visible mapping/defaults rather than `preset_id`, so manual
corrections reach the normal parser and row validation. A saved preset changes
only when the user explicitly opts to save under that name. Matching is a
header-compatibility suggestion, **not** proof of sheet layout, tax treatment,
buyer registration or CCI readiness.

Mapped duplicate normalized headers are rejected by the parser even when a
legacy client posts a preset ID directly. The held upload remains available on
mapping errors. Unknown/renamed columns require manual correction; template
uploads continue to bypass the mapping step. Existing legacy preset-ID clients
remain supported and tenant-scoped.

| Configuration | Expected behavior / regression |
| --- | --- |
| Smart flag off | Existing suggestions and legacy Apply path; no recommendation |
| One company preset, reordered headers | Editable recommendation; defaults retained; no request until confirmation |
| Several compatible presets | No automatic selection; explicit review choice |
| Missing/duplicate mapped headers | No recommendation; duplicate source blocked on parse |
| Second company preset/token | Not listed, recommended, or applicable across tenants |
| Manual correction | Visible mapping parsed into existing validated preview; stored preset retained |
| Template and legacy CSV | Existing parser/validation coverage retained |

Remaining issue #185 work is separate from this slice: CCI repeating invoice
blocks/grouping; verified SKU/pack-to-litre conversion and buyer mapping;
packaging/negative-line audit; source gross/discount/VAT/net reconciliation;
cross-upload source invoice uniqueness and retry-safe draft receipts; FBR
receipt tracking; multi-sheet/layout-version profiles and the full 73-invoice
pilot. **NOT production-ready.**

### Verification evidence for this slice

- Baseline real `bulkImport()` component ignored `recommended_preset_id` and
  left the recommended buyer column unresolved; the updated component prefilled
  the actual reordered/normalized header while issuing no request.
- PHP 8.4: `vendor/bin/phpunit tests/Feature/InvoiceBulkImportTest.php
  tests/Unit/InvoiceImportFormatMatcherTest.php`: **39 tests, 191 assertions pass**.
- `node scripts/tests/smart-di-mapping-ui.mjs`: pass, including corrected payload,
  ambiguity, duplicate headings, saved defaults, flag-off and state reset.
- `npm ci --ignore-scripts --no-audit --no-fund` and `npm run build`: pass.
  Generated build files are excluded from this focused change.
- Disposable MariaDB 10.11 `taxnest_dev` on loopback: actual controller upload,
  tenant-scoped recommendation and foreign preset rejection pass.
- `node scripts/tests/smart-di-mapping-browser.mjs`: **passes with headless
  Chrome**, exercising the real Alpine markup on loopback with fictional
  response fixtures: visible recommended columns/defaults, manual correction,
  ambiguity and explicit confirmation; no page errors. Standard Chrome was
  initially blocked by a Unix process-singleton socket. Headless Chrome enabled
  the real reproduction and exposed an Alpine options-rendering race; explicit
  selected bindings now keep the dropdown consistent with mapping state.
  This is component UI QA; authenticated full Laravel browser journey remains
  outstanding.
- Full PHPUnit suite is running; current results are recorded in PR metadata.
  Passing earlier HEAD checks does not validate this new integration commit.

No production settings, tenant configuration, FBR tokens, invoices or deploy
workflows were changed. PR remains draft pending complete validation and owner
approval.
