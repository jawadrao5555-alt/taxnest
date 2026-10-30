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

### Promotions, discounts, recipe cost and native acceptance checkpoint

- History head `7b5b9bbb2f86e3c0ece1b6e07612726b55cc246b` completed the local full run with 5,727 tests / 45,789 assertions and one permission-fixture failure. The fixture now resets User's schema-column cache before rebuilding tables. Its required CI also exposed two identical date filter controls; the duplicate history form was removed so the existing report filter controls both sections. Neither failed run is claimed as green.
- The preceding mixed-deal desktop/390px browser journey passed in Actions run `41057951344`. New journeys still require the next exact-head run.
- Reproduced manual deal discounts missing from the server across exclusive, inclusive and card-saving modes, then fixed canonical line discount handling and cumulative partial returns. Profit reports use frozen discounted ex-tax bases and immutable recipe/deal cost instead of later catalogue prices.
- Scheduled offers now have tenant-scoped CRUD, explicit timezone/UTC boundaries, all/selected active products, priority/percentage/oldest deterministic overlap, no manual discount stacking and bundle-price precedence. Exact quotes are revalidated under the shared tenant lock. Invalid/expired/foreign/tampered quotes reject before writes. Existing accepted ordinary queued/signed history is not repriced; new quoted offers require online payment.
- The sale screen explains the offer and offers an explicit refresh. Recall/refresh/resave handles expired holds; split KOT rows and final consolidation preserve exact discount totals. Receipts, credit notes, financial history and CSV retain original offer amounts. Disabling/editing an offer never reprices a paid bill. New browser journeys use real offer creation, selection, payment, receipt and disabling at desktop and 390px.
- Ordinary product and restaurant settlement costs now freeze compatible ingredient base-unit rates with waste. Missing rates remain NULL. Receipt stock and refunds keep their existing immutable ledger paths; catalogue imports remain stock-neutral. The cart's estimated profit excludes tax and hides incomplete cost rather than inventing zero cost.
- Reconciled PDF totals and product/component groups now use the same scoped financial-history service as HTML/CSV, with explicit available-detail labels for legacy analytics.
- Guarded consolidated affected suite: **150 tests, 1,007 assertions; no failures, two explicit native-only skips**. Blade compilation and inline-script escaping passed. This count predates the additional exact cutoff boundary test.
- New required MariaDB supplemental probes run two independent application workers against the actual shop-cash and receiving services. They test same-key replay, conflicting cash keys and simultaneous batch posting, synchronized by the real tenant lock. SQLite skips do not count as concurrency evidence. Native execution remains pending on the next commit.
- Remaining readiness work: final full suite, required native/browser jobs, review of any failures, exact-head CI including validate, final acceptance/evidence reconciliation. PR remains draft pending those results; no merge, deploy or live claim.

### Required-CI follow-up on `ae3495aa50e235384f555047151ac461d4537cd7`

- Native MariaDB migration/concurrency/DI job `124114770242`, Actions `41071008038`, **passed**. The new actual-service B02 worker races executed with **2 tests / 11 assertions**: identical cash-key replay, conflicting receipt keys, and repeated stock-batch posting. No native skip was accepted. Existing consent/callback/close InnoDB probes also passed.
- Dependency/build, workflow safety and release-chain jobs passed. Full-PHPUnit lane stopped at three WhatsApp restaurant-payment prechecks: the new optional cost lookup assumed a catalogue table in old minimal schemas. Reproduced locally; the lookup now leaves cost unknown when the catalogue is unavailable. **131 affected tests / 920 assertions passed** after the compatibility and precision fix.
- Node lane caught a raw Blade translation inside an extracted offline-method JavaScript test. The translated message is now initialized as component data; all eight real mixed-fulfillment method tests pass without changing/removing their checks.
- A focused reproduction showed recipe cost `0.000110` being rounded to `0.000100` by the legacy four-decimal model cast. The item column is widened to six decimals and its cast matches; rollback does not narrow historical values. Existing cost assertions retain exact fixed-decimal checks at the new precision.
- Browser and local full-suite outcomes on the earlier commit remain pending at this record. Final current-head required CI is still required before ready status. These checkpoint passes do not substitute for the final gate.

### Sale-cache acceptance follow-up

- Head `7d1132ffd24a36a79914b1d30ca535881221b411` passed the guarded local full suite: **5,755 tests / 46,133 assertions, no failures, nine skips and 28 PHPUnit deprecations**. The native-only skips have separate required MariaDB execution. Node 22, dependency/build, native MariaDB, release safety/provenance and both Windows workflows also passed on that head.
- The actual browser offer checkout failed its expected Rs 835 preview on both preceding heads. Reproduced the cache path separately: the successful editor POST left an old sale document cached, so immediately returning to New Sale replayed pre-offer catalogue data. The delayed boot check then preserved the busy cart as designed.
- Successful offer mutations now evict only the baked PRA sale document before returning the response. They do not reprice open carts, reset settings or remove FBR's offline copy. Failed saves preserve the fallback; disabled cache does not turn a successful mutation into a retry. The existing full service-worker smoke failed before this change and passed afterwards, including create/update, FBR preservation and rejected-save coverage.
- The original real desktop/390px checkout assertion remains unchanged; synthetic cart diagnostics were added for any further failure. Final current-head browser/full/validate evidence remains required before draft removal and will be linked in the PR description.

### Exact browser-selection correction

- Head `56c6f037633e909e8499e18036797418461a3fd8` passed required full PHPUnit (**5,755 tests / 46,123 assertions; 11 environment skips, 28 deprecations**), Node, native MariaDB, build, release checks and both Windows workflows. The first browser attempt failed before application execution because a locked dependency download returned HTTP 429; only failed/dependent jobs were retried.
- The retry diagnostics identified the actual remaining checkout failure: the product-name substring matched a deal tile's component description. The cart contained `Synthetic B02 mixed meal`, total Rs 902, rather than the standalone product. Thus the earlier cache reproduction was a separate valid regression; it did not establish the browser failure's complete cause.
- The journey now clicks the exact tile title and verifies the cart contains exactly the intended standalone product before checking the unchanged Rs 835 amount, real POST and frozen receipt. B02 runs before the broad screen sweep; all existing cases and viewports remain included. No expected amount or payment assertion was relaxed. Current-head browser acceptance and final validate remain required.
