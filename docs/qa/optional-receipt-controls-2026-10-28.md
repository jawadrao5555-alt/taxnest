# Optional receipt details and layout — 28 October 2026

Owner request: make the visible Pizza Master receipt details and presentation configurable without automatically changing saved designs. Video frames were inspected; audio was not transcribed. Implementation follows the owner's subsequent explicit list of optional controls.

## Scope and compatibility

PRA POS customer receipts: actual 80mm/58mm templates used by browser, Agent and PDF renderers. Independent Local/Fiscal overrides live in `invoice_display_prefs.receipt_options.{pra,local}`. Every new select defaults to Keep current; missing keys preserve the legacy effective design. No migrations or settings rollout. Existing locked fresh-row merge preserves missing/unrelated/unknown settings and other tenants. Resetting one option to Keep current removes only that override.

Controls: header/contact visibility, activity/website, bill date/format, terminal/payment/cashier/closed-by/waiter/table/floor, order type and extra matching code, item row numbers/quantity/rate/comments/deal contents, bill notes, subtotal/menu reference, logo position/size, extra non-fiscal QR size/caption, footer/escaped contact note/printed-at time, font/item/heading/TOTAL size and weight, spacing. Existing tax/footer/paper/margin/order-number controls remain. Paper width/alignment/margins stay printer-wide. Floor follows table visibility. The simple payment row toggle never suppresses delivery collection/prepaid/settlement details.

This is separate from nine-style #281/#304. No KOT routing, invoice numbering, fiscal submission or financial-record changes. Fiscal identity/status, item names/amounts, payable and fiscal verification QR remain. Extra QR sizing only targets `.receipt-extra-qr`. Common overrides resolve through `posReceiptPrefsFor()` so the public bill page respects business-name hiding too.

| Case | Required behavior |
|---|---|
| Existing company | Exact effective appearance inherited; GET/preview do not save |
| Fiscal/local/exempt/reporting-OFF | Existing stream predicate; independent overrides |
| Cached/partial POST | Missing options preserve stored settings |
| Other tenant/product/cashier | Tenant/product settings unchanged; existing permission denial |
| 58mm/80mm browser/Agent/PDF | Same actual templates; full names and unchanged payable |
| All switches hidden | Identity, fiscal QR, items and payable still visible |
| Unsafe CSS/HTML | Enumerated values only; contact note escaped |

## Verification and limits

Original real-template regression failed before the change: hiding terminal still printed SYNTHETIC-COUNTER. Local PHP 8.4.27 tests cover real HTTP save/validation/permissions, stale saves, second tenant, independent streams, real templates, escaped note, unchanged bill attributes and fiscal identity/QR. Receipt regressions and actual preview state-factory tests pass; exact final totals are recorded in the PR. Asset build and locked Composer audit pass. PhpSpreadsheet 5.10.0 security patch matches #309/#305; all other dependency entries/content hash unchanged.

Local Playwright browser download was truncated; separately downloaded Chrome segfaults before reporting a version. Local Chrome acceptance has NOT passed. Extended the existing actual authenticated desktop/390px CI journey to exercise preview, no-save reload, explicit Save, stream isolation and Keep current restore. Current-head full PHPUnit, all eight mandatory CI jobs and clean mergeability remain required. Physical thermal printer, installed Windows Agent and live rollout acceptance are not claimed.

Shared CI fixture correction: #309/#305 failed a delivery-board fixture after midnight. Reproduced at 00:30 Asia/Karachi: calendar-date fixture rows missed the business-day-filtered history tab. Seed `PosBusinessDay::current()` and exercise midnight/daytime with the existing lazy-loading and isolation assertions. Production filtering is unchanged. Local delivery suite: six tests/36 assertions pass.
