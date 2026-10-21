# Reports analytics: active shop day after midnight

Owner video 80a46865-acab-4a69-93da-7330342bce78.mov shows the analytics calendar defaulting to the new calendar day with zero values while the shop is still trading the previous business day. The analytics query already groups/filter sales by business_date; resolveReportRange instead defaults to calendar today. This differs from the existing company-specific PosBusinessDay cutoff/day-close policy.

Fresh PRA analytics (no from/to) now resolves both defaults through that existing policy. Add a Business day preset beside the existing calendar Today preset. Explicit/partial dates, reversed-range swapping, maximum window, company/cashier/stream/branch access and saved cutoff remain unchanged. The same resolver serves analytics PDF. CSV with explicit dates stays consistent; its no-range last-30-days contract is deliberately unchanged. Do not alter tax reports' legal created_at filtering, PRA timestamps, sales, saved cutoff, day-close records or FBR behavior.

Added supported application regressions for midnight before/after custom cutoff, a second tenant with a different cutoff, already-closed previous day, unchanged saved cutoff and explicit/partial/reversed/capped ranges. Existing legacy no-company reflection and FBR defaults remain valid. Real Chrome reports journey checks actual rendered default dates against server-resolved company day, captures desktop/390px screenshots and submits explicit dates that must remain selected.

PHP 8.3 syntax-only, JS syntax, whitespace pass locally. Required PHP 8.4/application/database/browser CI pending: local supported PHP/app/browser sockets unavailable. No independent live acceptance claimed.

## Overnight CI follow-up

Latest full PHPUnit found existing return-netting fixtures deliberately dated to the calendar day but queried through the newly changed default. Make that test explicitly request its fixture date, retaining every netting assertion. Additionally move the same fictional rows to the active business day and check a fresh no-query report retains two sales/gross1655/tax255. New default/cutoff/tenant/explicit-range tests remain required.

Local environment recheck: loopback binding is available in this run, but supported PHP 8.4 is absent and Chrome installation failed (truncated/non-zip download). Local pure JS/syntax checks are limited evidence; supported real browser/database/full PHP acceptance remains GitHub CI, not customer production acceptance.
