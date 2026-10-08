# Hotel check-in and receipt popup
Owner-reported flow: Check in opened a separate Walk-in page; bill/reprint opened a stay statement with repeated paper choices. A receipt link alone did not start a print.

Changes:
- Front-desk Check in and vacant-room Check in open the existing scoped form in a dialog. The normal page remains an accessible fallback; real booking uses the same service and idempotency key.
- Bill confirmation opens the actual linked invoice receipt in a dialog. Print invokes the loaded receipt frame directly, or the canonical Agent enqueue engine according to saved settings. Close returns to the bill actions. Stay/bill receipt links also use this popup.
- The selector distinguishes historical invoice numbers and the non-fiscal stay statement. Statements do not impersonate an issued invoice. Reported fiscal references/QR are rendered by the existing receipt.
- Paper size is saved in Hotel Settings once. Selecting 58mm/80mm explicitly changes the shared company receipt width. A4 is a Hotel-only flag and uses browser printing, retaining the previous thermal size. Printer/device routing and silent-print opt-in remain in existing Printer Settings.
- Printing never issues another bill or another fiscal submission. UUID retries reuse the canonical job; no automatic browser copy after an uncertain response or Agent failure. Only definite enqueue rejection allows a safe explicit browser fallback. Agent success means the Agent reported success, not independently inspected paper output.

Compatibility matrix:
| Configuration | Behaviour |
| --- | --- |
| Browser-only/default OFF | Same thermal width; popup Print invokes receipt frame |
| Silent receipt printing ON | Canonical company/default route and UUID dedupe |
| Explicit counter assignment | Its printer; unavailable counter refuses cross-counter fallback |
| Legacy/unassigned user | Existing company printer path |
| Second tenant | Cannot preview/enqueue/read another tenant's stay/job |
| A4 Hotel preference | Browser printing; other saved routing/thermal choice retained |
| Old direct statement/receipt links | Still render; embedded statement hides toolbar |
| Lost enqueue response | Same UUID retained within page, including popup close/reopen; no blind fallback |

Local PHP/Composer are unavailable in this sparse workspace. Owner screenshots and code established the pre-change flow; canonical disposable CI provides real Laravel, MariaDB and Chromium verification. Physical printer/PRA production behaviour remains owner verification; no live access is used.
