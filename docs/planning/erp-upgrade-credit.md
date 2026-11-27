# Standalone POS to ERP upgrade credit

For the first migration pilot, a SaaS super-admin opens a Hospital Collections POS company and selects **Preview Hospital ERP price difference**. Choose a Healthcare package with inpatient care and a supported billing period. The estimate shows the destination price, the unused source value, the credit applied to the estimate, and the estimated difference.

## Estimate calculation

- The destination uses its existing package price for the selected billing period. This is the full selected destination term.
- The source credit comes from the unexpired part of the verified, allocated **base package** payment. The estimate prorates by calendar days in the application timezone, counts the paid end date as exclusive, and rounds the unused value down to the nearest paisa. The credit cannot exceed the destination price. A source overpayment does not increase the credit.
- Require one active term and its immutable subscription purchase, paid package invoice, verified proof and receipt. A trial, override, unpaid or partial invoice, future term, missing evidence, multiple active terms, or a recorded source adjustment requires separate review. An early renewal whose recorded term does not match the purchased cycle also requires review. The estimate does not infer prior-term credit from an overlapping or ambiguous purchase.

## Follow-up settlement and migration

The estimate keeps the company's numeric ID, Agent pairing, active term, payments, and records in place. It generates no invoice and does not grant any entitlement. A later settlement workflow must recalculate against locked source and destination records at cutover, store a frozen quote and one durable allocation per source charge, and approve only the verified difference. A zero difference needs explicit credit settlement evidence. A failed or repeated request must keep the allocation, receipt, activation and migration consistent, and already allocated credit cannot be used again.

Data migration still requires owner approval, a destination company and package, explicit branch mapping, and repeat-safe reconciliation. The original Agent and issued historical fiscal documents remain on the source account and copied historical records cannot be dispatched by ERP.
