# Invoice calculation diagnostic — 27 October 2026

## Reported example

A Pizza Master PRA receipt showed menu lines totalling PKR 1,600, payment method CARD, tax 8% included PKR 110.34 and final total PKR 1,490. Receipt evidence alone strongly matches the existing inclusive-card-save formula but cannot prove the saved bill snapshot.

## Change

Add company-scoped `INVOICE_DIAGNOSTIC` to Live Ops. It accepts one exact POS invoice number and reports only:

- stored payment method, tax-inclusive flag, menu rate and payment rate;
- redacted item-line totals and taxable line totals, without item names;
- stored discount, tax and final totals;
- recalculation from the saved snapshot and an explicit confirmation boolean.

The diagnostic never uses current company settings to reinterpret a historical bill. It returns no customer details, item names, raw regulator payloads, credentials or arbitrary SQL output. It reads archived historical bills without changing archive visibility elsewhere. Audit/report rows remain the existing designed Live Ops writes.

## Safety and compatibility

- Exact company scope is required; the same invoice number in another tenant is excluded.
- Invoice references are limited to 1–80 safe characters.
- Existing company settings, receipt designs, fiscal state, invoices and financial history are not changed.
- Missing legacy columns remain unavailable and prevent confirmation instead of inventing values.
- Diagnosis does not approve a repair, submit/retry a bill, print, merge or deploy.

## Verification required on final head

- Targeted Live Ops tests cover the PKR 1,600 / 16% menu / 8% card / PKR 110.34 / PKR 1,490 case and tenant isolation.
- Admin and runner validation, shell/YAML parsing, PHP syntax, full PHP, MariaDB, actual browser, Node 22, locked dependency audit/build, workflow/release validation and all eight mandatory PR checks must pass.
- Production diagnosis for P2240 remains owner-approved post-deploy acceptance; this PR contains no live customer evidence.


## Final review — 28 October 2026
Three additional real regressions reproduced false card-save confirmation when the saved discount column, line exemption column, or all saved lines were unavailable. The diagnostic now requires complete numeric line/taxability evidence and an actual saved discount, reports evidence gaps, and never substitutes zero for missing evidence. Non-numeric/non-finite values stay unavailable. Duplicate invoice references inside one company are rejected instead of selecting the first row. Existing exact P2240-like saved-snapshot/second-tenant/privacy proof remains covered. No historical data or production settings changed. Fresh final-head checks are required after this correction.


## Browser gate investigation — historical Reprint fixture
Run 40654697340's browser gate failed after historical-date and filter screenshots were captured, at the assertion that resetting filters restores three manually injected rows. The sale screen performs a real startup/background reload; a JS-only list is not durable across that legitimate reload. Replace only those synthetic proof rows with three real, company-scoped rows on the already-closed historical fixture date (cash delivery, debit-card delivery, card takeaway). The real date API and all same filtering/rendered-text assertions remain required; no production code, timeout increase, skipped test, browser gate removal or customer data change. A fresh actual browser run is required to accept this fixture correction.
