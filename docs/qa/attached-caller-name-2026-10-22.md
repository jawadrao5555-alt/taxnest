# Save the attached caller's name

Owner source a0fc2d7b-2d8d-43e8-b23a-650f29532ce3.mp4: after attaching an unknown caller, the Current Order card repeats the phone as its name and has no save-name action. The popup already has an existing save-customer form but disappears after Bill.

Expose the same save flow on the attached unsaved customer card. Prefill the phone, leave a phone-as-name fallback blank, preserve a real caller name, focus the existing name field. Saved customers cannot enter this create path. The existing company-scoped phone-deduplicating customerStore endpoint remains unchanged; no new create endpoint or historical invoice rewrite. Product-grid ON/OFF retains the existing top customer form/toolbar.

Local actual-method regressions: 3/3 pass (blank/real name, formatted phone, focus/cart, saved/missing identity). Syntax/whitespace/workflow contract pass. Real Chrome delivery journey now clicks the actual attached-card action, fills the existing name field, saves through the actual local API, checks attached saved identity and captures desktop/390px evidence. Supported local PHP/app/browser unavailable; full CI/browser pending, not independently live verified. Order-type consistency is separate #240; existing-customer name/phone editing is separate work.
