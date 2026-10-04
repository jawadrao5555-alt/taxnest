# Hotel fiscal credit notes — draft implementation and evidence

Status: Draft. The read-only HotelCreditNotePolicy prepares original-line review data; it cannot issue or submit a credit note, refund money, or cancel a stay. This is not the complete user-facing feature. The fiscal lock from PR #153 remains in force.

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

## Remaining implementation before ready for owner approval

1. Explicit owner reason, confirmation and durable idempotency; server-side preview recheck under stay, bill and item locks.
2. Unique original-line/folio linkage. Historical bills lack direct item-to-folio IDs; never match by display name or assume row order. Migrate new linkage and define a safe legacy review route.
3. Calculate final header/discount/rounding from original immutable values. The current review exposes shares only, not a final authorized amount. Cumulative partial credits must never exceed original value or tax.
4. Separate accounting credit from cash refund. Generic POS returns carry a payment method and completed status; reuse only after Hotel ledger and day-close reporting correctly distinguish unpaid guest credit from actual refund.
5. Keep original bill and service history. A partial/full credit must not implicitly cancel a stay or release its room. Booking cancellation is a separate confirmed action.
6. Preserve PRA queue/agent routing. Pending/offline/failed child is visible and retryable with its same identity; submitted status needs regulator acceptance evidence.
7. Simple EN/UR/Roman Urdu owner preview, partial/full actions, receipt and refund action; actual desktop/mobile browser checks.
8. Sandbox acceptance for full/partial notes, reference matching, duplicates and retry recovery; reconcile monthly-return export/Annexure I independently. No production credential or live invoice experiment.

## Verification limitations

Local PHP/Composer are not installed in this workspace, so application tests cannot run locally. Canonical CI is required. The new behavioral test covers read-only partial/full review, tenant access, outstanding fiscal submission and over-quantity rejection. Database, browser, issuance and sandbox evidence remain outstanding. This PR must stay Draft until the complete feature and required evidence are added.
