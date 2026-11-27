# Standalone POS to ERP upgrade credit

For the first migration path, a SaaS super-admin opens a Hospital Collections POS company and selects **Preview Hospital ERP price difference**. Choose a Healthcare package with inpatient care and a supported billing period. The estimate shows the destination price, the unused source value, the applied credit, and the difference.

## Estimate calculation

- The destination uses its existing package price for the selected billing period. This is the full selected destination term.
- The source credit comes from the unexpired part of the verified, allocated **base package** payment. The estimate prorates by calendar days in the application timezone, counts the paid end date as exclusive, and rounds the unused value down to the nearest paisa. The credit cannot exceed the destination price. A source overpayment does not increase the credit.
- Require one active term and its immutable subscription purchase, paid package invoice, verified proof and receipt. A trial, override, unpaid or partial invoice, future term, missing evidence, multiple active terms, or a recorded source adjustment requires separate review. An early renewal whose recorded term does not match the purchased cycle also requires review. The estimate does not infer prior-term credit from an overlapping or ambiguous purchase.

## Review, payment and cutover

1. Check the company owner's authorization and freeze the estimate against an active Hospital ERP destination with the same verified owner. Its package, cycle, source evidence and exact credit are fixed for the quote date.
2. Review the bank transfer reference and record only the difference payable. If the credit covers the package, explicitly record a zero settlement. Each source charge can fund one upgrade.
3. Review the destination and match each active source campus to its destination campus. Record approval for the cutover, then activate the destination package. The destination invoice shows the package value, applied credit and net charge. A receipt records only a positive bank transfer.

The original company, Agent pairing, issued receipts and fiscal history remain accessible at the source. The campus mapping records the operational move. Import of historical clinical or financial datasets requires a separately verified source-to-destination mapping and reconciliation.
