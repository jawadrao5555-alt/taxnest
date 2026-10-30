# PRA B02 implementation evidence

PR #317, owner-authorized implementation of the complete B02 contract.

## Compatibility matrix established before edits

| Area | Failure / new outcome | Existing behavior to preserve | Negative / concurrency cases |
| --- | --- | --- | --- |
| History | Deleted detail must not appear as zero activity; business-day attribution | Save/Delete/carry/finalize choices; optional support consent remains default OFF | Other tenant, branch and cashier scope; expired/purged copies; repeat close |
| Cancellation | Reason, partial preparation and explicit unknown evidence | Legacy NULL remains unknown; KOT sent is not food prepared | Unsent/sent KOT, paid order refusal, repeat cancellation, foreign line IDs |
| Shop cash | Selected eligible unassigned deliveries received exactly once | Rider settlement and card/prepaid behavior; delivery timestamp separate | Other tenant/branch/stream; unpaid/returned/settled; stale preview; concurrent receipt |
| Deals | Mixed exempt/taxable components reconcile | Existing pricing modes, whole-rupee bill total, immutable older snapshots | Fixed/choice/Special, stale cart, master edits, refunds and stock reversal |
| Promotions | Scheduled all/selected product percentage offers | No campaign means unchanged pricing; no automatic setting reset | Boundary time/timezone, overlaps, no stacking, forged/expired/offline claims |
| Stock | Explicit receiving, valid conversions, frozen costs | Catalogue import remains stock neutral; existing unit meanings and branch guards | Duplicate upload/post, stale cancelled batch, revoked module, foreign item |
| Reports | Category/product/size and components reconcile | Existing branch/role/stream access and return/fiscal identities | Unknown cost, missing detail, invalid dates, CSV/PDF parity |

No production data, credentials or customer-specific fixtures are used. Actual
reproduction, test runs and remaining gaps are recorded below as they occur.

## Evidence

- Baseline: `2a6c626385e66c7b0048baac7b8e2f85440a5439`, main
  `b8ca7fb92cb868573b38b4d7fc3e7bc667697dba`. #312 changes are present.
- Initial inspection: existing optional support-copy consent and catalogue
  preview are reused. No feature completion is claimed by this initial record.

### First implementation checkpoint (30 October 2026; incomplete bulk)

- Reproduced stale stock batch posting after cancellation and repeated order
  cancellation overwriting the original actor. Both regression tests failed
  before their fixes; the initial two affected files passed 38 tests afterwards.
- Cancellation adds nullable reason/partial prepared quantity, keeps unknown
  distinct from zero, preserves legacy explicit made-item submissions, and
  serializes retries without repeating kitchen-void enqueue. Reports identify
  prepared **sale value**, not inventory cost or a cash refund.
- Receiving serializes batch mutations/reference checks, converts compatible
  ingredient quantity/rates, shows and verifies the staged unit snapshot, and
  keeps catalogue imports stock neutral. Incompatible dimensions still fail.
- Reproduced recipe return losing the original waste quantity. Returns now use
  effective frozen quantity and sale-time cost; new consumption cost has six
  decimal precision. Unknown older cost is not replaced with today's price.
- Selected shop-cash receipts reuse the existing settlement ledger with a null
  rider, immutable shop kind and replay key. Final/unassigned/cash eligibility,
  exact selected amount, tenant/stream/branch and stale-selection checks precede
  an atomic receipt. Receipt does not finalize or mark delivery complete.
- Day-close rechecks its winner under the tenant lock and preserves unsettled
  unassigned delivery evidence. Receipt reconciliation uses business-day windows
  and allocation scope instead of calendar-only settlement timestamps.
- Guarded focused suite: **58 tests, 290 assertions PASS** (cancellation,
  receiving, recipe, ingredient unit edits/HTTP and shop cash). Rider/day-close
  affected suite: **30 tests, 219 assertions PASS**. Announcement + receiving:
  **69 tests, 339 assertions PASS**. Blade view compilation passed. These are
  checkpoint runs, not the final exact-head acceptance matrix.
- Full unguarded exploratory PHPUnit run was interrupted by automatic approval
  review due to an external PRAL endpoint risk. It is **not a pass**. It was
  replaced with the repository's unchanged `scripts/rc-safe-run` (clean env,
  loopback-only DNS/connect guard), with external fiscal test flag enabled and
  empty fiscal tokens. That guarded full run is pending at this checkpoint.
- Local native DB attempt used genuine MariaDB 10.6.24 but stopped at the
  repository's unchanged guard: `root startup requires the mysql account`.
  No guard or version check was weakened. Native and browser acceptance remain
  pending in a suitable isolated runtime / required CI.
- **Still open:** full historical availability/reconciliation, mixed-component
  deal allocation and lifecycle, promotions, product/size grouping, additional
  branch/concurrency/browser acceptance, complete stock unit UX and final-head
  required CI. No whole B02 completion, ready-for-review, merge, deployment or
  live verification is claimed.
