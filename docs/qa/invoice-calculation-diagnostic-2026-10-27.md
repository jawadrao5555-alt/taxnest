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
