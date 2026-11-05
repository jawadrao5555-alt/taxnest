# PRA + IMS: original bill reporting, fee and stored-fact recovery

## Observed failure and reproduction
The owner’s newly completed restaurant bill was PRA-accepted, but IMS
preparation returned `Stored line tax snapshots are missing. Legacy amounts
will not be guessed.` No IMS record or fiscal POST resulted. The restaurant
pay path did not populate the fiscal base fields added for ordinary checkout.
Local HTTP tests now exercise that actual pay route and assert frozen facts.

A synthetic counterpart uses Rs460 base, Rs73.60 stored line tax, Rs74 stored
header tax and Rs534 paid total. Its completed PRA-accepted source has missing
new split columns. Recovery and original-bill reporting now produce exactly
Rs74 tax / Rs534 goods total without changing the source rows. This is local
synthetic evidence, not acceptance by the owner's Windows IMS or central FBR.

PR #345 merged only the earlier catalogue-PCT compatibility fix. The original-bill policy, fee and recovery below belong to a separate follow-up based on main 94c009f8c460d18c6f640ed6116994aa133cf630.

## Final behavior
- IMS catalogue PCT may be blank. Reuse standalone FBR POS's existing transport
  fallback `00000000`; keep the wire field present and strictly eight digits.
  This is compatibility behavior, not a statutory classification or proof of
  acceptance by every IMS installation. DI still requires its HS code.
- Blank IMS reporting percentage selects explicit `tax_source=original_bill`,
  not zero and not omission of tax fields. Use the source's stored rates and
  amounts, reusing its deterministic original PRA rounding projection. Existing
  explicit percentage rules keep their existing meaning. DI requires its rate.
  This disclosure policy does not add customer tax or declare an exemption.
- A complete, confirmed Production IMS setup charges one separate Rs1 fee on
  new fiscal final bills. No fee for DI, Sandbox, unconfigured/ambiguous setup,
  deliberate provisional, or reporting-OFF new bills. Cash/card quotes, both
  checkout paths, promotions, receipt and Day Close use the stored fee snapshot.
- Fee is outside item/PRA tax math. PRA's rounding adjustment cannot absorb
  it into tax. Existing standalone FBR payload behavior also excludes the
  separately collected service fee from goods/tax totals.
- A full return reverses the original collected fee once, on the final partial
  return, never on every partial; no second fee is charged for a return. This
  is an application refund policy for owner review, not a legal conclusion
  about refund entitlement. Source fee and tax remain auditable separately.
- New restaurant finalization freezes ordinary and versioned-deal facts.
  Return slices preserve frozen original base amounts by cumulative cents.
- Legacy recovery is narrowly limited to complete stored, PRA-accepted,
  undiscounted ordinary sale facts with known tax mode/rates and reconciled
  original totals. Discounted/unknown/ambiguous legacy money stays blocked.
  Recovery does not rewrite the sale, items, stock, payment or PRA reference.
  The IMS encrypted snapshot carries provenance, source reference and a hash.
- Saving reporting policy never backfills old bills. Recovery prepares a
  reviewable record; it does not itself send it. Existing cross-channel,
  tenant, device, exclusive-claim and unknown-outcome protections remain.

## Compatibility matrix
| Configuration | Proof / invariant |
| --- | --- |
| Restaurant hold/pay and counter finalization | Real HTTP routes freeze facts and collect one fee |
| Missing catalogue code | Reviewed IMS rule saves; wire PCT remains valid |
| Blank IMS percentage | Original tax/rate/total retained; no numeric zero substituted |
| Explicit existing IMS percentage | Existing mapping remains unchanged |
| DI missing/invalid HS/rate | Existing required validation retained |
| Accepted stored bill missing new split columns | Copy complete original money into separate IMS snapshot |
| Legacy discount, inconsistent money, unknown rate | Recovery refused, source preserved |
| Mixed versioned deal | Frozen component taxable/exempt rates preserved |
| Second tenant or wrong device | Existing scope/claim tests retained |
| Repeated checkout/dispatch | One sale, fee and reporting intent |
| Partial then full return | Goods/tax conserved; original fee reversed once |
| Legacy offline or signed Local Core packet | Never re-price already-collected money; absent fee remains zero |
| Fee-bearing immediate Local Core UI path | Uses ordinary durable submission until Core supports the separate fee snapshot |
| All 9 receipt designs, 58/80mm | One separate fee line, unchanged tax, separate authority references |
| Old paid bills and Z reports | Additive default-zero migration; no historical amount rewriting |

## Validation
- Focused Laravel run: **150 tests / 1,361 assertions passed**, PHP 8.3.6.
  PosFederalAutomationTest, PraDiSandboxTest, PosKotOnFinalPayResponseTest,
  PosPraReturnFlowTest, PosOnlinePaymentAwaitedTest,
  PosDayCloseReturnNettingTest, PraReceiptSerialWithFiscalTest,
  FbrPayloadPrecisionTest, FbrPosReturnCreditNotePayloadTest.
- Focused Node run: **29 passed**: actual Agent JSON/SOAP/claims/unknown-outcome
  flows plus production cart/held-order fee getters and mixed fulfillment.
- Chrome, loopback only: Laravel-rendered original-recovery record at 1280px
  and 390px; source Rs534 / tax Rs74 visible, queued state/replay guards shown,
  no pageerrors. This is rendered-view coverage. HTTP save/recovery/dispatch
  and actual checkout are covered by the feature tests.
- Hosted browser workflow extended: real IMS mapping save with supplied code,
  then blank code AND blank rate; verify blanks are preserved and no old bill
  is queued. Prepare a separate stored-original recovery fixture at both widths
  and assert that recovery did not dispatch. No fiscal submit action is used.
- New Node fee regression added to the existing egress-blocked required lane.
- Full local suite is NOT a pass: local PHP 8.3.6 fails the unchanged ^8.4.1
  runtime contract, and automatic approval review blocked continuation after
  detecting an external PRAL IMS HTTPS request. No bypass/retry of that access.
  Required hosted PHP 8.4, MariaDB and browser checks must pass the updated HEAD.
- Native MariaDB unavailable locally. Required hosted native migration/concurrency
  lane remains unchanged. No tests, platform constraints or safety checks weakened.

## Release and actual customer acceptance
No production settings/credentials/database edits, new sale, fiscal submission,
merge or deploy performed for development. The earlier UI preparation attempt
was blocked before an IMS record/fiscal POST. The existing bill remains intact.
Owner approval and deployment are separate. Only after deployment may the real
bill be prepared/reviewed and submitted once; local acknowledgement is still
not proof of central FBR synchronization. Never blindly resend an uncertain bill.

## Browser CI zero-fee correction
The occupied-table browser journey failed when saving a provisional delivery:
`forCheckout` compared float request zero with integer fallback zero using strict
identity. Use a float fallback so equal fees pass; retain numeric/range validation
and rejection of an actual stale fee. A real `/pos/invoice/store` regression
reproduced the exact 422 before the fix and passes afterward, checking stored
zero fee/unchanged total, rejection of fee one, and absence of a second sale.
Local PHP 8.3 focused files passed separately: KOT/final pay 10 tests / 85
assertions; federal automation 20 tests / 130 assertions. Running those two files
in KOT-first order in one process exposed three IMS fixture failures; federal
passes independently. Updated hosted full suite and browser checks remain the
authoritative PHP 8.4 validation. No checks or stale-fee guards were weakened.
