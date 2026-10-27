# Recent Bills: view before printing

Reported flow: on the PRA sale screen, selecting a Recent Bills chip immediately
sends the saved bill to the printer. The chip must open the existing bill preview;
the cashier then chooses an existing popup action or the dedicated Reprint flow.

## Cause and change

The chip calls `reprintBill(bill)`, which immediately selects the saved silent
Agent route or browser print fallback. Change only that chip to call the existing
`openReprintPreview(bill)` and label its tooltip as a preview. The existing popup,
its receipt URL without `auto_print`, popup actions, dedicated Reprint drawer,
bill printing settings and current sale are retained. No new popup or printer
contract is introduced; no company settings, bill or KOT records are written.

## Compatibility and evidence

| Configuration | Expected behavior | Evidence |
| --- | --- | --- |
| PRA restaurant, silent printing ON | Chip previews; explicit Print invokes existing Agent boundary | Node behavioral test; actual browser journey added |
| PRA restaurant, silent printing OFF | Chip previews; explicit Print retains browser fallback | Node behavioral test; actual browser journey added |
| PRA retail, either print setting | Existing retail receipt route without automatic print | Node behavioral test |
| Desktop pointer or keyboard | Recent chip opens existing popup | Actual desktop browser journey added |
| 390px mobile | Strip stays hidden; existing drawer preview and Print remain | Actual mobile browser journey added |
| Multiple printers / older Agents | Routing unchanged; no printer command until explicit Print | No routing, persisted configuration or Agent contract changes |

Before the change, executing the actual chip expression against the real
extracted handlers failed all four tests because no preview opened. After the
change, all four pass. `node --test scripts/tests/recent-bill-preview.test.mjs
scripts/tests/print-extension-bridge.test.mjs`: 10 passed. The initial extension
test attempt lacked two files in the partial checkout; after retrieving those
unchanged files, all six extension regressions pass. `node --check
scripts/rc-browser-acceptance.mjs` and scoped `git diff --check` pass.

The browser extension to the existing occupied-table journey uses a real newly
saved fictional bill and its real receipt iframe. It checks no print boundary
is invoked by viewing, current cart/customer are unchanged, and explicit Print
still selects silent/browser output. Printer boundaries are spied to avoid
physical output. It runs in the mandatory MariaDB desktop/390px acceptance lane.

## Verification limits

This host has no PHP, Composer, MariaDB server or Chrome executable; a full local
application/browser reproduction and `php artisan test` cannot run here. No
local Chrome/MariaDB/full-suite result is claimed. PR remains draft until actual
current-head browser acceptance, full suite and all required checks succeed.
Production behavior is unverified; owner approval/deployment and Actions live
verification remain separate. The deploy process already bumps the service
worker version; this patch does not modify pending PR #287's cache recovery.
