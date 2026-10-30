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

### Exact first checkpoint validation

- Commit `300f9ab26813a2f2b0fe117b6255b7eff2b2ad65`: an isolated, unchanged
  checkout passed the guarded full suite: **5,703 tests, 45,600 assertions**,
  with 7 skips and 28 PHPUnit deprecations. The earlier exploratory receiving
  error did not reproduce on this fixed commit. Test output is not silently
  counted as clean of skips/deprecations.
- Actions run `41053038458` passed full PHPUnit, native MariaDB migration and
  concurrency, dependency/build, workflow safety, release chain and Node/Agent
  jobs. Browser and aggregate validate were still pending when recorded here.

### Mixed-deal implementation checkpoint (bulk still incomplete)

- Reproduced wrong exclusive and inclusive/card-save billing on checkpoint
  code using the new HTTP regression: all three modes failed before the fix.
- New component snapshots freeze allocation weights, exemption/Third Schedule,
  known cost, category/size/unit and effective recipe quantity. Deterministic
  paisa shares preserve discounts across components and bill lines. Existing
  non-versioned historical snapshots retain their legacy calculation.
- Browser preview, store/edit/promotion, PRA payload and item tax-report/export
  values use the component split. PRA whole-rupee header conventions remain.
  Partial returns consume cumulative frozen shares and cap final remainders;
  later catalogue/rate/cost changes do not rewrite original payloads.
- New online deals cannot be shown as successfully queued offline when the
  existing server would reject their replay. Retry preserves the payment key.
  This does not migrate or reprice older signed Local Core history.
- Focused guarded PHP tests: **69 tests, 414 assertions PASS**. Pure browser
  calculation test passed three pricing modes and 250 penny-allocation cases.
  Blade compilation passed. A new isolated synthetic tenant/journey exercises
  real deal selection, discount and payment at desktop and 390px; its browser
  execution and new-head CI are pending, not claimed as passed.
- Still open: historical availability/reconciliation and grouping, scheduled
  promotions, remaining stock UX, specific cash/receiving concurrency and
  negative-scope acceptance, remaining deal lifecycle edge acceptance, and the
  final exact-head whole-bulk readiness gate. PR stays draft.

### History and stock follow-up checkpoint (bulk still incomplete)

- Exact mixed-deal head `4c982f12784972e29d35334c9697075f892c2632`
  finished the guarded local full suite with **5,713 tests, 45,714 assertions,
  one failure**: the counter payload source guard still searched for the old
  fresh-UUID expression after payment retries began reusing their key. Updated
  the guard to the actual shared payload while retaining counter assertions.
  This run is not counted as a full pass. Seven skips / 28 deprecations remain.
- Reproduced empty stock counted as both low and out, and an invalid calendar
  movement date being accepted. Both failed before the fix. Health partitions
  are now disjoint, valuation/count scope is labelled, movement entries preserve
  fractional quantities, and invalid/reversed movement dates are rejected.
- Unit defaults distinguish new compatible standard conversions from existing
  saved mappings; unknown pack sizes and incompatible dimensions fail. A
  changed staged factor rejects posting until rematch; repeated post remains
  stock-neutral. Ingredient cost inputs preserve six decimal places.
- Shop-cash retry rechecks current branch and billing scope; read-only roles,
  foreign actors and impersonation are denied. Provisional deal edits retain
  choice IDs/rich snapshots; missing calculator asset blocks new deal payment.
- Reproduced day-close deleting Rs 580 of known final sales and leaving zero in
  available detail. The new same-transaction aggregate capture preserves dated
  branch/cashier/stream/payment/product totals without bill identifiers or
  customer copies. Actual close/repeat-close tests prove unchanged totals and
  no duplicate aggregate. Archived clear uses the same capture. Company purge
  removes its aggregates. Save/Delete and optional support-copy consent are not
  altered; older missing breakdowns are declared unavailable.
- A scoped sales reconciliation and matching CSV distinguish final gross,
  credit notes, net, provisional amounts, known-only history and charges/
  rounding differences. Deal component shares replace bundle revenue; product
  ID/size/category/standalone vs component remain distinct. New product labels
  are frozen and returns copy them. Combined scope is permissioned; page and
  export tab resolution now share one gate. Existing detail analytics remain
  explicitly labelled gross/available-row views, and their previous period
  excludes returns consistently. No whole-company Z totals are injected into
  cashier/product totals.
- Guarded stock/unit/counter compatibility: **125 tests, 636 assertions PASS**.
  Receiving/shop-cash/choice negatives: **36 tests, 152 assertions PASS**.
  History/close/deal/counter compatibility: **75 tests, 541 assertions PASS**.
  HTML/CSV/scope parity group: **33 tests, 232 assertions PASS**.
  Consolidated affected checkpoint: **225 tests, 1,357 assertions PASS**.
- Still open: scheduled percentage promotions, remaining deal/discount/offline
  lifecycle and frozen cost edge checks, specific native cash/receiving
  concurrency, current-head browser journeys, and final required CI/validate.
  Whole-bulk readiness and deployment are not claimed. PR remains draft.
