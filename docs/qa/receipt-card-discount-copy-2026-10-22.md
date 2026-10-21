# Receipt: remove the separate Card Discount line

Owner audio AUDIO-2026-10-21-00-51-59.m4a and receipt image 32c63433-ffbd-4fc4-bc0e-d8708caa1e9c.jpeg explicitly request retaining Tax (8% incl.) while removing Card Discount. Change only customer receipt presentation in both 80mm/58mm templates. Keep Menu Total as the original menu-price reference (530 in the supplied example), stored inclusive tax 31.03 and stored final Card total 499. No recalculation of item/menu prices, discount/tax snapshots, payment/rounding, PRA payloads, history/reports or tenant/printer/fiscal settings. Ordinary cashier-entered Discount remains visible. No physical print.

Added actual receipt HTTP rendering regression for both paper sizes, the supplied monetary pattern, retained 8% inclusive tax/menu reference/payable, ordinary discount and complete saved-attribute immutability. Existing broader exclusive/inclusive/card-save/history/CSV/PDF regression remains intact. Real Chrome customer-money acceptance asserts the actual receipt has no Card Discount, retains its stored payable and captures desktop/390px screenshots.

Local PHP 8.3 syntax-only/JS syntax/whitespace pass. Supported PHP 8.4/application/browser unavailable locally; actual rendering/full-suite/visual CI pending. No original live or Windows printer acceptance claimed. This does not change taxation or implement a new discount policy.
