# Restore single-type bills with Parcel item notes

## Owner request and decision

The owner now wants the earlier simple order flow: no per-item Serve Here / Parcel / Delivery portions. Write `Parcel` on the relevant item using its existing note button. Updated kitchen slips may print, but already-sent food must not be prepared twice. Delivery remains a separate invoice.

This is a focused correction on owner-deployed #272 main `7a12107f078997003ab04b46c073fc538c5ce13a`, not a full revert. A full revert would also remove stable KOT/recall safeguards and the explicit-zero-tax quote correction. #275 Agent IMS/version work is separate and is not included.

## Original reproduction

The actual production `addToCart` function merged a second product click into a row whose note was already `Parcel`: one row, quantity 2, note Parcel. That would label the extra pizza as parcel too. The same function after this correction produces a one-pizza Parcel row and a separate clean row for subsequent ordinary pizzas. The old portion selector/split dialog was present on main; it is removed rather than hidden behind another toggle.

## Final behavior

- Preserve whole-order Dine In, Takeaway and Delivery selection, table/customer flow and existing note button. Remove new-item portion controls, split buttons/dialog/state/methods. New item rows and delivery fee rows carry no per-item fulfillment mode.
- A plain unsent product/deal row still merges matching clicks. Noted or already-sent rows never absorb a new product click. One pizza with note Parcel plus two subsequent pizzas produces quantity 1 + 2, total unchanged. Deal-choice separation and tax/discount fields remain intact.
- A note belongs to the whole line's quantity. Write it before sending to the kitchen. Sent-line note editing is refused with an explicit order correction/cancellation message, avoiding an implicit cancel/re-fire of already prepared food.
- Keep printed-quantity carry, stable held order identity and delta KOT logic. Example: first send 2 plain pizzas; recall, add 1 pizza and write Parcel; the add-on has only the 1 Parcel pizza, while the 2 original pizzas remain stamped as sent.
- New delivery bills use their own address, fee and legacy whole-bill collection. No mixed counter/rider selector appears on new single-type bills.
- Server validation rejects newly mixed service input from stale or crafted clients, not just hidden controls. Historical exceptions are resolved from company-scoped persisted order/line identity or an explicitly passed company-scoped existing transaction; a client flag, new UID or foreign recall ID is insufficient.
- Existing mixed held bills keep read-only line service labels, stored modes, payment/receipt/return/rider compatibility and (only where needed) the historical mixed payment selector. No schema rollback, backfill, saved setting rewrite or prior transaction rewrite.

## Compatibility matrix

| Configuration | Evidence / expected result |
|---|---|
| Dine In with one Parcel note | New single-type bill; note on one line; KOT add-on only new quantity |
| Ordinary unsent no-note rows | Matching clicks still increase quantity normally |
| Separate Delivery | Contact snapshot, KOT, payment and saved receipt pass; rider collection stays nullable legacy whole-bill contract |
| Prior mixed snapshot | Existing kitchen labels, replay, mixed cash/card/fee allocation and partial/return tests retained |
| New stale-client mixed request | Validation error before creating the order |
| Foreign company recall / forged legacy UID | Rejected; original company/order/items remain intact |
| Legacy unlabelled already-sent line | KOT sent quantity carried when recalling and adding a Parcel-note row |
| Desktop / 390px mobile | Actual pointer note/cart/payment flows; containment, visible name bounds and no horizontal overflow assertions |

## Local evidence

- PHP **8.4.27**, clean `scripts/rc-safe-run` targeted KOT, fulfillment, rider partial/returns and counter drawer: **74 tests / 418 assertions PASS**; the final targeted rerun also includes existing responsive/accessibility contracts (final count recorded in PR body).
- Node **22.23.2**, production-function mixed/notes, address state, phone search and caller/order-type regressions: **35/35 PASS**.
- Actual Chrome Headless Shell **145.0.7632.6**, fictional loopback Laravel app and fully migrated disposable SQLite database under `/tmp/taxnest-simple-app`: **Dine In and separate Delivery PASS at 1366 and 390px**. Actual product clicks, first hold/KOT, occupied-table or existing held-order recall, Parcel note entry, one-item add-on, payment-modal pointer cash action, persisted receipt note and real HTTP rejection of a new forged mixed delivery request pass.
- Browser pageerrors are empty. Each viewport recorded 8 console errors / failed requests; this is not a zero-console-error claim. The driver blocks every non-loopback request and the PHP process uses the existing egress guard.
- Visual review caught an accidentally removed cart wrapper in the first local draft. It was restored before publishing; final screenshots show readable rows/quantity/Parcel notes, and the actual browser workflow now fails if rows escape the cart list, names leave the viewport or the page overflows horizontally. An early mobile test selector matched a hidden quick-create button; final workflow uses the real existing mobile cart/back controls, not forced clicks.
- `npm run build`: PASS; generated build outputs are verification-only and excluded from this Blade/controller correction.
- `php artisan view:cache`: PASS; `scripts/pos-i18n-check.php`: PRA 363 / FBR 289 baked keys, zero missing/dynamic issues.
- Announcement parser/check/dry-run: PASS, new unique title, POS-only audience and seven food-service categories. Seven ordinary-language points describe notes, quantity example, delta KOT, sent-line correction and separate delivery. Prior announcements/read state are not modified.
- `git diff --check` and changed PHP / browser script syntax checks PASS.

The fresh local environment initially lacked PHP. A private PHP 8.4.27 runtime/extensions were extracted for local verification; package manifests/locks are untouched. SQLite fixture attempts in the workspace hit transient readonly/malformed-file errors. A newly migrated disposable `/tmp` database passes the real app journeys. This is SQLite browser evidence, not MariaDB evidence; unchanged mandatory CI must still run genuine MariaDB 10.6.23.

The first full-suite run completed 5568 tests with two existing exact-markup contract failures after adding stable browser selectors. Attribute order was restored to preserve those contracts; the original tests were not weakened. The affected suites pass, and a fresh full-suite rerun is required before readiness.

Full-suite completion and fresh exact-HEAD mandatory check URLs/results are recorded in the PR body. No old green checks authorize this change. No production credentials/customer account/data, physical printer, real installer, merge or deployment was used. Stop for separate exact-HEAD owner admin-panel approval and Approval Relay after readiness gates pass.
