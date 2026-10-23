# Stable category/product PDF order

Owner AUDIO-2026-10-22-10-42-38.m4a: PDF rows move above/below one another, making menu tally difficult. Source confirms revenue-ranked category/detail rows, and a fifteen-product truncation. Menu categories on the sale screen are sorted by name. PDF analytics now uses stable natural name order for category/detail rows and includes every sold product; the on-screen revenue ranking/top15 stays unchanged. Existing company/branch/cashier/stream/date filters, gross/return rules, stored money/tax/profit snapshots are untouched. This does not recategorize deals or rewrite transactions.

Regression added to existing real range-analytics fixture:18 sold products, stable order/fullPDF detail vs unchangedtop15 revenue ranking and identical category totals/tax/profit. Native PHP8.4 absent locally; supported PHPUnit/MariaDB/PDF acceptance remains pending CI. Source diagnosis, not customer-data proof. Keep draft; no production access or deploy.
