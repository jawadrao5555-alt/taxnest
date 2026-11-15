# School / Hospital prospective native concurrency proof

`tests/native/rc_local_pilot_quota_concurrency.php` runs only in the egress-blocked disposable `rc-mariadb` full database and socket fixture. It creates synthetic non-internal School/Education and Hospital/Clinic companies, selected trial followed by paid activation, and frozen package limits. There are no live patients, student records, external fiscal calls, or deployment actions.

Both workers deliberately establish a REPEATABLE READ snapshot before the race barrier, then call the actual collection controller inside that older transaction. Required cases:

- One remaining paid slot, two distinct payment identities: exactly one receipt/invoice and one cash amount.
- Identical identity: both callers recover the same receipt, even after the winner fills the quota.
- Same identity with changed amount: one winner, explicit request-key conflict, original cash unchanged.
- Two installments compete for the remaining balance: one accepted, one amount rejection, no overpayment or lost School paid snapshot.
- Payment versus day-close: either payment wins and stale close is rejected/refreshed, or close wins and new payment is rejected.
- Saved close cash/count match the actual persisted payments. Lost-response retry after close recovers the original receipt; changed-data retry fails without altering receipt rows or closing snapshot.

The runner requires both exact PASS markers. PHP and shell syntax checks are not native execution evidence. Native results must come from the exact integrated commit's required hosted job; do not claim these cases pass from SQLite or source inspection.
