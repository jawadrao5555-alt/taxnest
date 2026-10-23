# Recalled additions before final payment

Owner report: a bottle added after recalling an order appeared on the final bill
although Send to Kitchen was not pressed. The current product deliberately bills
all cart lines; KOT is a separate save/print action. Silently removing a line would
change the charge and could lose an actually supplied bottle.

This change warns about new/increased kitchen-eligible cart quantities relative to
the recalled snapshot BEFORE payment edits/holds/network writes. Cancel leaves the
cart unchanged; accept keeps every amount and the existing payment/KOT chain.
The acknowledgement is scoped to order, baseline and current fingerprint; a new
quantity/order asks again. Split equivalent rows are aggregated. Kitchen-skipped
fees, unchanged/reduced carts, plain retail/no-KOT and fresh direct counter sales
retain their existing behavior. It does not claim physical kitchen delivery.

Validation: `node --test scripts/tests/recalled-additions-confirm.test.mjs` 4/4,
actual production methods in VM; JS syntax and whitespace pass. The existing
real table-order Chrome journey now accepts/dismisses the actual native dialog at
both supported widths, restores the fictional in-memory fixture and retains all
original table/caller/address/payment assertions. Full PHP8.4/MariaDB/app Chrome
acceptance remains fresh CI pending. Local PHP8.4 is absent. No production or
printer was accessed. This is a draft, not a release-ready claim.
