---
name: DI per-company invoice numbering
description: Rules and accepted risks for the per-company sequential DI invoice number scheme
---

- Format: `{7-13 digit registration/NTN identifier}DI{NNNNN}` (5-digit zero-padded per-company sequence from `companies.next_invoice_number`, bumped under `lockForUpdate` inside the invoice-store transaction).
- **Never renumber FBR-submitted invoices.** Legacy timestamp-format numbers (13-digit ms suffix) coexist safely — suffix lengths differ, so no collision by construction.
- Composite unique index is `invoices(company_id, invoice_number)` — scoped per company. **Accepted risk:** two companies sharing the same NTN prefix could, under true concurrent commits, mint the same number (global existence check is a non-locking read). Deemed acceptable; fix would be a global unique on invoice_number.
- `peekNextNumber` (preview on create form) does not replicate the taken-bump loop — the previewed number can differ from the final one. Cosmetic, by design.

**Why:** renumbering FBR-submitted rows would break regulatory traceability; the per-company scope was the whole point (owner complaint: serials leaked across companies).
**How to apply:** any new code path creating DI invoices must go through `InvoiceNumberingService::generateNextNumber()` — never hand-roll numbers or reuse the old timestamp generator.
