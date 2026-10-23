# Mixed Dine In and Parcel items — owner request, 23 October 2026

**Design draft / queued implementation only. No application fix or acceptance is claimed.**

## Owner-confirmed requirement and priority

Owner supplied video `23064bbb-1da6-4a6f-8377-c66c9b4eb2e6.mov`, then clarified in text:
one Malai Boti pizza was eaten at the table and a second identical pizza was
requested as parcel; the kitchen slip showed quantity 2 under a parcel note.
The owner additionally requires the same flow when both portions are ordered
at the outset. The exact spoken audio was not independently transcribed.

Latest instruction: create this PR and prepare it **immediately after #270**.
This supersedes the previous #266 → #228 preparation order for the next slot.
Do not silently advance those or other drafts ahead of this item.
Release sequence: owner approves/releases #270, exact deployed SHA/live-check
and successful outcome are verified, then refresh/implement/test this PR on
that proven base and stop for a separate fresh owner approval.
No chat instruction here approves either PR for merge/deploy.

## Cashier flow

Keep one Dine In bill and its table. Each relevant food line can be **Serve Here**
or **Parcel**; the entire bill must not switch to Takeaway to parcel one item.

1. Initial mixed order: enter total quantity 2, choose “Parcel: 1”; produce two
   separate lines of quantity 1, one Serve Here and one Parcel.
2. Later addition: first pizza already sent/served, add another and select Parcel;
   only the new parcel quantity goes on the next ADD-ON KOT.
3. Adding an identical item with a different fulfillment instruction must not
   increment/overwrite the existing served or kitchen-sent line.
4. Cashier can split an unsent quantity into serve/parcel portions; validate
   nonnegative portions, allowed quantity precision and exact sum. Reject invalid
   or excess splits server-side. Do not impose food-piece integer rules on all
   product UOMs without reviewing existing support.
5. Show readable per-line badges in the cart, held/recalled order and KOT. KOT
   uses English **SERVE HERE** / **PACK / TAKEAWAY**. No forced packaging charges.
   Keep the ordinary flow fast: default from current order type; extra selection
   only for mixed service.

## Kitchen behavior

When both portions are first sent together:

| Item | Qty | Instruction |
| --- | ---: | --- |
| Malai Boti Pizza S | 1 | SERVE HERE |
| Malai Boti Pizza S | 1 | PACK / TAKEAWAY |

When quantity 1 was already sent, the ADD-ON contains only quantity 1 PACK.
Never resend the old served quantity just because its product ID matches.
Reprint of a previous KOT retains that KOT's actual historical snapshot.
A later fulfillment correction for an already-sent line must be an explicit,
auditable correction/cancellation flow, not a silent replay of an old order.
Payment/receipt reprints must never enqueue another kitchen preparation.

## Required implementation and compatibility

- Inspect actual cart merge, split, held-line persistence, recall, kitchen delta,
  receipt, device print snapshot, Local Core/offline and server validation paths.
- Use stable line identity plus fulfillment and existing deal/variant/note identity;
  product ID alone is insufficient. Free-text special notes remain separate.
- Store/serialize line fulfillment consistently. Old records/clients without the
  field retain existing meaning; no historical quantity/note rewrites.
- Keep one bill/table, existing unit prices, quantity totals, discounts, taxes,
  stock movements, payment, reporting state and fiscal routing unchanged.
- Preserve unrelated line notes, deals/choices, recipes, skip-kitchen rules,
  table-claim/concurrent cashier safeguards, permissions and tenant boundaries.
- Same behavior across compatible restaurant tenants and one/many counters or
  printers; no company/device hardcoding, no real customer data or physical print.

Source observation only: deployed `pos/universal.blade.php:addToCart` currently
matches item ID/type and deal-choice key, then increments a matching row; the
cart's `special_notes` is a row field. This supports investigating merging as
the cause but is **not a completed end-to-end reproduction**.

## Acceptance matrix before Ready for Review

- Reproduce original same-pizza/parcel-note problem on a disposable fictional shop.
- Initial 1 serve + 1 parcel produces two correct lines on first KOT.
- Already-sent 1 serve + later 1 parcel produces only new parcel on ADD-ON.
- Multiple quantities, split/rejoin unsent portions, invalid/excess splits, mixed
  products/deals/notes, quantity edits and cancellation preserve totals/identity.
- Hold/recall/reload, final payment and KOT/receipt reprint do not duplicate KOT.
- Existing all-Dine-In, all-Takeaway and Delivery flows remain unchanged.
- Second tenant cannot read/change another company's held line; concurrent cashier
  and retry/idempotency behavior remains correct.
- Old client/no field, Local Core synchronization and printer routing retain safe
  behavior; do not claim offline compatibility from a web-only check.
- Actual 1366/390 browser journeys/screenshots plus relevant supported PHP 8.4 /
  MariaDB tests, full mandatory exact-HEAD eight checks and clean mergeability.
  Promotion-triggered runs require their own eight checks including final validate.
- Customer announcement in ordinary wording added with actual implementation.
  No feature announcement on deployment of this specification alone.

## Current evidence

This commit changes only this specification. No UI/backend/schema/Agent change,
no application test, no complete bug fix, and no deploy-ready claim.
Implementation is queued behind #270. Never approve/merge/deploy from the agent.
