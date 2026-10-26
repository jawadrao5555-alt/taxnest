# POS search name and right-aligned price — 25 October 2026

Owner request: retain compact search width; bold product name; bold price at the row's right edge.

## Reproduction and scope

Actual deployed Blade row rendered with Alpine and built CSS in Chromium at 1366px and 390px. Before: short product price was 408px/126px from right edge and name weight 600. After: right gap 0px, name weight 700 and price weight 800. Long names wrap without clipping; original compact input widths remain 640px/358px. Zero page errors. This is an extracted real component with synthetic catalog, not a complete authenticated Laravel browser journey.

No stored company settings, search ranking, keyboard handlers, stock indicators, catalog data, prices, tax, cart or tenant queries changed. Both short/long labels and desktop/mobile checked. Updated existing authenticated acceptance journey to assert the owner's new right-edge placement and both font weights instead of the previous adjacent-price requirement; all other assertions retained.

## Checks

- npm run build: pass.
- Local Chromium component before/after, two widths and short/long labels: pass, zero pageerrors.
- node --check scripts/rc-browser-acceptance.mjs: pass.
- PHP 8.4.27 vendor/bin/phpunit --filter='PosConfinedPortalRenderTest|PosRiderSettleInvariantTest|PosRiderPartialSettlementTest|PosUniversalTableOrderRecallTest': 22 tests, 174 assertions pass; 28 existing PHPUnit deprecations.
- php artisan test wrapper could not inherit the custom PHP ini into its child process; direct PHPUnit above loaded the required extensions successfully.
- git diff --check: pass.

Full local Laravel/MariaDB/browser suite not bootstrapped. Full suite not run: prior repository evidence identifies real external IMS calls in broad tests; use the existing isolated CI lanes. Required exact-head CI remains pending; not deploy-ready or live verified.

## Separate unresolved reported errors

Videos show recurring HTTP 500 across POS navigation. Agent Open POS targets the same /pos/invoice/create path; shared server cause is possible, not established. No speculative exception swallowing/retry/cache deletion or Agent release change included. Read-only ERROR_SUMMARY requested through issue #282; run 40208249306 is waiting for production Environment review. Logs and local reproduction are needed before claiming a root cause/fix. No live credentials, production data, merge or deploy used.
