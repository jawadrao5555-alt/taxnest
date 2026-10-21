# Save the attached caller's name

Owner source a0fc2d7b-2d8d-43e8-b23a-650f29532ce3.mp4: after attaching an unknown caller, the Current Order card repeats the phone as its name and has no save-name action. The popup already has an existing save-customer form but disappears after Bill.

Expose the same save flow on the attached unsaved customer card. Prefill the phone, leave a phone-as-name fallback blank, preserve a real caller name, focus the existing name field. Saved customers cannot enter this create path. The existing company-scoped phone-deduplicating customerStore endpoint remains unchanged; no new create endpoint or historical invoice rewrite. Product-grid ON/OFF retains the existing top customer form/toolbar.

Local actual-method regressions: 3/3 pass (blank/real name, formatted phone, focus/cart, saved/missing identity). Syntax/whitespace/workflow contract pass. Real Chrome delivery journey now clicks the actual attached-card action, fills the existing name field, saves through the actual local API, checks attached saved identity and captures desktop/390px evidence. Supported local PHP/app/browser unavailable; full CI/browser pending, not independently live verified. Order-type consistency is separate #240; existing-customer name/phone editing is separate work.

## Overnight CI follow-up

Latest CI exposed the real 390px cart flow: the test now enters the cart through its actual button; Save returns to the menu pane where the existing name form lives, before its next-tick focus. Actual-method regression checks pane transition, cart preservation and invalid/saved-customer no-op. Browser/full exact-HEAD CI must be repeated.

Local environment recheck: loopback binding is available in this run, but supported PHP 8.4 is absent and Chrome installation failed (truncated/non-zip download). Local pure JS/syntax checks are limited evidence; supported real browser/database/full PHP acceptance remains GitHub CI, not customer production acceptance.

## Fresh full-run follow-up

Run 39689743761 passed the actual attached-name save and subsequent address/table journeys on desktop and 390px, full PHPUnit, MariaDB, Node, workflow and release-chain checks. The browser lane remained red only on two rider-name assertions after a separate tracking-page navigation: its actual API-upload/automatic Leaflet poll journey had passed, but the new document's asynchronous rider cards were read before mounting. Wait at that precise navigation for the actual fixture rider and required visible marker, bounded at 15 seconds, then retain the original body/main marker, upload ACK, five-second map update, console/network and every other assertion. No production tracking code, timer, duty rule or test gate changed. Local supported PHP8.4/Chrome remain absent; real browser recheck requires a new exact-HEAD CI run. Shared Electron audit/validate still require separately ordered #235.
