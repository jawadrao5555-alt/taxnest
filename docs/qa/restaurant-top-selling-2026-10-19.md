# Restaurant Top Selling View All

Report: dashboard preview exists but View All does not show the full matching
item list/details. Uploaded screenshot path was unavailable. Code inspection
shows the restaurant preview uses completed restaurant order items but linked
to POS transaction-item Reports with a fiscal/local filter and a different
calendar range. The populations differ, especially for local or unlinked orders.
No claim of reproducing a real customer's data.

Both preview and full restaurant list now use one completed-order query and the
same business-day cutoff. Preview remains eight rows; View All uses a full
paginated ranking (50 per page, no final item cap). Selected-item drilldown shows
order date, quantity, line subtotal and an authorized invoice link when present.
Totals are explicitly item subtotals before bill-level discount and tax, not tax
or revenue-ledger totals. Existing retail/fiscal Reports behavior is unchanged.
The restaurant dashboard and its new report are owner/admin/manager company-wide
views; cashiers cannot access the full report. No new branch interpretation or
fiscal stream classification is applied to historical orders.

Compatibility: current business day, custom dates up to 366 days, midnight cutoff,
16 items versus 8-row preview, line quantities/amounts, completed vs cancelled,
old dates, another tenant and cashier denial. Existing restaurant item-name
grouping is retained. Retail top-item Reports keep their existing filters.

Added real backend query/controller regression and isolated MariaDB/Chrome View
All -> sixteen items -> selected-order details -> date filter flow, desktop and
390px mobile. Local PHP/Chrome unavailable, so these tests require fresh CI;
JavaScript syntax and whitespace checks pass locally. Not production verified.

## Refresh after WhatsApp deployment — 2026-10-20

Merged main `725070a55b55419c237e4956f5d063abd150b617` into this change. Kept notification product targeting, independent preflight users, WhatsApp checks and public website checks. Local Node regression checks: 36 passed; browser runner syntax and staged whitespace checks passed. PHP and real Chrome are unavailable locally; exact-head CI must pass before owner approval.
