# Receipt: remove the separate Card Discount line

Owner audio AUDIO-2026-10-21-00-51-59.m4a and receipt image 32c63433-ffbd-4fc4-bc0e-d8708caa1e9c.jpeg explicitly request retaining Tax (8% incl.) while removing Card Discount. Change only customer receipt presentation in both 80mm/58mm templates. Keep Menu Total as the original menu-price reference (530 in the supplied example), stored inclusive tax 31.03 and stored final Card total 499. No recalculation of item/menu prices, discount/tax snapshots, payment/rounding, PRA payloads, history/reports or tenant/printer/fiscal settings. Ordinary cashier-entered Discount remains visible. No physical print.

Added actual receipt HTTP rendering regression for both paper sizes, the supplied monetary pattern, retained 8% inclusive tax/menu reference/payable, ordinary discount and complete saved-attribute immutability. Existing broader exclusive/inclusive/card-save/history/CSV/PDF regression remains intact. Real Chrome customer-money acceptance asserts the actual receipt has no Card Discount, retains its stored payable and captures desktop/390px screenshots.

Local PHP 8.3 syntax-only/JS syntax/whitespace pass. Supported PHP 8.4/application/browser unavailable locally; actual rendering/full-suite/visual CI pending. No original live or Windows printer acceptance claimed. This does not change taxation or implement a new discount policy.

## Overnight CI follow-up

Latest full PHPUnit failed the new whole-row immutability assertion because legacy receipts legitimately mint a missing public QR token. Seed an existing fictional token before the baseline; retain the complete attribute comparison, both paper sizes, exact tax/payable/menu values and ordinary Discount assertion. No production receipt/QR behavior altered.

Local environment recheck: loopback binding is available in this run, but supported PHP 8.4 is absent and Chrome installation failed (truncated/non-zip download). Local pure JS/syntax checks are limited evidence; supported real browser/database/full PHP acceptance remains GitHub CI, not customer production acceptance.
