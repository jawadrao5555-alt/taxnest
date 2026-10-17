# Real CCI pilot: source reconciliation complete; fiscal evidence incomplete

Tracking: #194. This PR does not authorize or transmit any invoice.

The owner-supplied original `7170.xlsx` and confirmed-rules staging workbook were read as actual XLSX bytes. Their SHA-256 hashes are retained in the accompanying sanitized JSON audit. Customer names, addresses, outlet/invoice identifiers and source files are not committed. The source date is 10 October 2026.

| Check | Result |
|---|---:|
| Source invoices / distinct outlets | 73 / 73 |
| Product movement lines / SKUs | 413 / 31 |
| Beverage-sale staging | 311 lines |
| Packaging retained separately | 90 lines |
| Damage/return review | 12 lines |
| Evidence coverage | 413 of 413 lines |
| Missing/duplicate evidence lines | 0 |
| Changed source dates, quantities or amounts | 0 |
| Source invoice footer mismatches | 0 |
| Gross | PKR 861,596.41 |
| Discount | PKR 75,727.01 |
| Source VAT | PKR 8,819.09 |
| Source net | PKR 794,688.49 |

All 73 original footer identities reconcile in integer paisa: gross − discount + VAT = net. This proves accounting reconciliation, not the tax classification or permission to report source VAT as FBR sales tax.

## Remaining real evidence

- Printed retail price is missing on all 311 beverage-sale rows. Obtain the SKU/unit/batch-specific printed MRP and the sales-tax basis. A carton sale price is not automatically the printed unit retail price.
- All 31 SKU mappings lack verified HS/tax approval. The workbook contains proposed **text** codes `2202.1010` / `2201.1010`; extracted-text numeric representations can drop their final zero, so the audit uses XLSX bytes. Proposed HS and provisional third-schedule treatment are not approved mappings.
- 83 beverage-sale lines lack case-to-litre conversion. Supplier evidence is needed for RB/ambiguous packs. Known conversions are still provisional until unit and price/MRP bases are confirmed together.
- Buyer status is not verified on the sales rows; source invoice buyer NTN/CNIC and registration columns are empty. Check all 73 outlets individually. Do not assume all are unregistered or treat income-tax ATL as sales-tax registration evidence.
- The source has VAT percentages and invoice VAT totals, but no explicit item VAT amounts for 413 lines. Do not allocate invoice VAT by ratio or treat a zero source percentage as an exemption. Obtain a supplier allocation/tax explanation and preserve the original amounts.
- Packaging is excluded from the beverage staging but retained for reconciliation. The 12 damage/return lines require an actual adjustment/credit-note decision and must not be silently deleted or netted into a sale.

The actual `SmartInvoiceImportReview` was run against the original source in a disposable SQLite in-memory application, with no verified mappings and HTTP stray requests prohibited. It reviewed **413 rows / 73 invoices**, found **0 valid rows / 0 invoices without errors**, created **0 drafts**, and sent **0 fiscal requests**. The HOLD is therefore an observed application result, not a spreadsheet-only label.

## Repeatable tooling

`scripts/cci-pilot-evidence-audit.php` accepts local source/evidence XLSX paths through `scripts/rc-safe-run`. It reports aggregate coverage, exact-paisa footers and evidence gaps without printing buyer/source identifiers. It never bootstraps production configuration, accesses a DB, writes drafts, approves mappings or contacts FBR. Even filled evidence cells cannot authorize an import; normal company mappings and explicit reviewed batch approval remain required.

Local command: `bash scripts/rc-safe-run -- php scripts/cci-pilot-evidence-audit.php --source=/local/7170.xlsx --evidence=/local/confirmed-rules.xlsx`.

Validation: 8 tests / 450 assertions including existing parser coverage, changed/duplicate/missing evidence, conflicting footer rejection, identifier omission and no automatic release after filled cells. Real source/evidence audit ran successfully with full line coverage. Full CI remains pending at PR creation. This backend-only audit has no UI flow.

After evidence arrives: verify company-specific mappings → reconcile source item VAT against the DI payload → owner-reviewed draft-only pilot → inspect original dates, units, MRP, buyer identity and totals → explicitly approved sandbox/live submission through the normal owner release path. Code changes cannot replace missing supplier or registration evidence.

## 17 October deployed-main refresh

Merged original audit HEAD 41f525c327b20a405d679bc7c58837b8ec3b1875 onto successfully deployed and Actions-live-verified main 5436f1c7ef07040c4cd5208488396e7b588b80c7 (#202), preserving both histories. Clean merge, no audit implementation/test/source-evidence changes. The current fiscal receipt, branch identity, claim/recovery, read-only reporting and preview protections remain present. Audit/parser regressions are included in the early Smart DI preflight.

The real-source figures and observed HOLD above are historical 13 October evidence, not a new audit of a potentially changed workbook. This refresh does not re-read, expose or modify customer workbooks. Fresh CI validates code/regressions only. No new verified supplier, MRP, conversion, buyer-registration or item-VAT evidence was supplied in this task, so real fiscal pilot signoff remains pending. Audit PR readiness is not permission to create or submit fiscal invoices.

Local PHP/MariaDB/Chrome executables unavailable. Run fresh exact-HEAD targeted audit/parser tests, native migration/concurrency, browser acceptance and full PHPUnit before approval readiness. Agent inputs/version stay 1.13.19. No production/customer/regulator access or agent merge/deploy.

Initial refresh CI correctly rejected unsupported announcement audience `di`. Changed metadata to supported `all`; local announcement parser/freshness/SHA-title checks PASS. No audit implementation or customer evidence changed. Fresh exact-HEAD CI required after this correction.
