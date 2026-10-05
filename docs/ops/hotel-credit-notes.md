# Hotel fiscal credit notes — draft implementation and evidence

Status: Draft; fiscal issuance and linked money refunds are deliberately disabled in `config/hotel_credit_notes.php`. Owner review screens are available. The implementation now includes issuance and separate refund services, but sandbox and final-head runtime reconciliation evidence are still required before enabling the feature. The fiscal lock from PR #153 remains in force.

## Legal and technical evidence (4 October 2026)

PRA Adjustment of Tax Rules 2012, Rules 8–11 distinguish reductions for registered recipients, credit notes for unregistered guests, supplementary invoices for increases, and pre-service cancellation under intimation to the jurisdictional Commissioner. Rule 8's 90-day wording concerns the tax period in which modification is required; do not encode it as 90 days from original invoice date. Consolidated later amendments and the applicable return-period calculation still require verification.

Official rules: https://pra.punjab.gov.pk/Downloads/SalestaxOnServicesRules/4-Rules-Adjustment-of-tax.pdf

PRA's official POS Component manual, Version 1.0 issued 7 January 2026, includes Hotel as a sector, InvoiceType 1/2/3 (new/debit/credit), and RefUSIN for the merchant's original invoice number. This establishes generic support, not acceptance of TaxNest Hotel full/partial notes.

Manual: https://e.pra.punjab.gov.pk/templates/POS_COMPONENT_and_eIMS_User_Manual.pdf

PRA return FAQ Q22 distinguishes Invoice Management and Annexure I adjustments; registered recipient debit-note claims are part of its procedure. Monthly-return revision is separately described in Q39. API acceptance must not be labelled return adjustment completed.

FAQ: https://e.pra.punjab.gov.pk/templates/PRA_Iris_Sales_Tax_Return_FAQs.pdf

## Compatibility and accounting contract

| Case | Expected result |
| --- | --- |
| Confirmed fiscal Hotel bill | Owner can review original quantities and tax snapshots; no write |
| Partial unused night/service | Credit selected quantity, not the whole stay |
| Full erroneous/duplicate invoice | Full remaining bill adjustment; replacement separate |
| Deposit/excess payment, sale unchanged | Existing payment/deposit refund; no sales credit note |
| Existing local correction | Preserve its existing audited reversal |
| Pending/offline/failed/ambiguous original | Reconcile original before review/issuance |
| Pending/failed child credit | Resolve same child; do not create another |
| Other tenant/stay | Reject; never expose bill data |
| Legacy agent | Preserve existing PRA queue contract; no new payload fields without compatibility proof |
| Day closed | Preserve current return policy; no automatic bypass |

## Implemented workflow

Owner-only, company/active-branch scoped Hotel screens review full remaining bills or selected original item quantities. Review does not write. Issuance requires an explicit reason, confirmation, unique request key and unchanged review fingerprint. Stay and original invoice/item locks serialize attempts. Durable request identity handles repeats, including JSON numeric representation/order differences.

New Hotel invoice items store `hotel_folio_entry_id`; existing deployments without the column preserve billing. Historical rows are not guessed by name or order: missing/ambiguous mappings and bill-level discounts remain review-only. Credit lines use original tax/pricing snapshots; last remaining quantities consume residual original line values and header rounding, while cumulative value/tax are capped.

Credit issuance creates an audited fiscal return and negative charge adjustments without cancelling the stay, releasing the room, restoring inventory, or paying money. Original payment mode is retained for PRA payload classification, while the stored `hotel_credit_note` marker is excluded from cash/card/other payment buckets and the compact day-close summary. Submission happens after commit using existing cloud/agent routing; failed/pending children block another adjustment.

A separately confirmed money refund requires accepted credit status/fiscal number, a unique request key, two-decimal cash/card amount, and both remaining document credit and available guest funds. It posts an audited folio refund; it cannot create another fiscal note. Deposits stay separate. Receipt uses the existing POS receipt route.

Linked credit refunds stamp their settlement business date, branch and selected cash drawer. Day-close, X reports and payment summaries deduct the actual cash/card settlement on that date; drawer expected cash deducts cash refunds only. Sales and tax change only through the credit document. Refund-only days can be closed, closed days/drawers reject new refunds, and retries of an already posted request remain idempotent. Historical refunds are not assigned guessed settlement dates.

## Remaining release gates

1. Run canonical PHPUnit, native MariaDB and desktop/390px browser CI on the final head. Local PHP/Composer are unavailable; local diff checks are not runtime verification.
2. Complete actual Hotel full/partial credit-note sandbox acceptance, original RefUSIN matching, duplicate/retry recovery and legacy-agent compatibility evidence. No production credential or live invoice experiment.
3. Verify final-head refund reporting regressions: selected drawer, settlement date, tenant/branch/user scope, refund-only day close and retry after close. The integration is implemented; runtime evidence is required before enabling either write action.
4. Verify monthly-return/Annexure I procedure and applicable consolidated adjustment rules independently; API acceptance is not monthly-return completion.
5. Browser evidence for enabled issuance/refund and translated desktop/mobile flows; the default gate is not enabled by CI success alone.

Regression tests cover read-only review, company/cashier isolation, original acceptance, pending child, excessive quantity, safe legacy mapping, idempotent partial issuance, accepted separate refund and refund cap, full credit without stay cancellation, default gate, stale preview and HTTP review. Synthetic regulator status is test setup, never sandbox acceptance evidence.
