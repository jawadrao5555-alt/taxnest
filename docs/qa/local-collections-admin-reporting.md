# PR #393 section 6: local collection reporting

## Source requirement and delivered adapter

User consolidated attachment section 6 requests School/Hospital collections, payments, balances and saved closings in admin usage/dashboard and reporting diagnostics, without confusing them with retail/fiscal or TaxNest subscription money.

- `LocalCollectionReports` reads principal, received payments, as-of outstanding and immutable cash closing snapshots as **separate** quantities, in paisa. Payments are aggregated per account before joining principal. School pre-account receipts count once as fully paid legacy local cash; they are not converted or assigned an invented balance.
- Principal date is account/invoice creation; collection date is receipt `collected_at`; closing date is saved `report_date`. Date ranges are stored calendar dates in the configured application storage timezone. Business-day/terminal/method slicing is rejected rather than applied incorrectly. Positive campus IDs must belong to the selected company; explicit campus 0 means unassigned.
- Outstanding is as-of the end date over all earlier principal/payments, independent of the window's new invoices. Receipt balances and closing rows are never rewritten. A future installment cannot change the earlier as-of balance.
- Missing tables/columns produce `UNAVAILABLE` and null quantities. No saved close produces `NO_RECORDED_CLOSE`, not zero cash. Known zero remains zero. Missing counted-cash/variance fields remain unknown. Payments without matching company/campus principal make outstanding unknown and expose the mismatch count.
- Admin Company Usage supports company/campus/calendar-date filters for local collections, keeps existing PRA usage semantics, and renders the separate local quantities. Admin dashboard shows bounded visible-company local cards, distinct from its retail ledger tile. The existing credential-bearing company-detail template is intentionally untouched; Company Usage supplies the accessible detail report.
- Existing reporting connector summary, payments and day-close operations route local-category tenants to this ledger adapter after exact company/connection scope resolution. Other retail bill/item contexts explicitly reject local-category misuse instead of silently reporting an empty retail ledger.
- Diagnostic registry adds School accounts/receipts/closings and Hospital invoices/payments/closings, category validation and exact closing campus filtering. Evidence exports only IDs/timestamps, never patient/student/payment-reference text.

## Evidence

Executed isolated SQLite PHPUnit with committed loopback-only `scripts/rc-safe-run`:

- `tests/Feature/Reporting`, `AdminUsageAccuracyTest`, `AdminDashboardDetailTest`: **108 tests / 1,429 assertions passed**.
- Includes synthetic partial payments, multiple receipts per invoice, as-of balance, foreign-company/foreign-campus exclusions, legacy School cash, immutable closings, unknown vs zero, orphan payment warning, exact connector grants, source category and source field privacy.
- Existing PRA/DI reporting regressions included in that suite.

Browser/native MariaDB acceptance and final full CI remain parent integration work; no claim is made here that the new admin cards were browser-verified. No production data, settings, bill, payment, close, fiscal submission, merge or deploy was performed.

## Explicit limits

The report is a partial retained-data observation, not a health certificate, seven-day retention guarantee or physical cash verification. Standalone local refund/reversal events are not implemented by the current ledger; no zero-refund claim is made. A future refund implementation must add an independently reconciled reversal source rather than subtracting invoice principal twice. Bank/card amounts mean recorded receipts, not acquiring/bank-settlement evidence. Fiscal reporting remains blocked.
