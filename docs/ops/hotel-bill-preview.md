# Hotel bill preview and explicit confirmation

The reception issue-bill and checkout forms open a read-only preview before a
bill is created. Closing the dialog keeps the booking and recorded payments;
it does not mint a serial, issue a transaction, print or contact PRA. Confirm
uses the acting account's saved reporting setting. Reporting ON offers
`Confirm & Report to PRA`; OFF offers a local confirmation. This is not a
company-wide toggle and does not convert already-issued local receipts.

The preview shows only charges this action can invoice, discounts, canonical
tax, collected money and checkout balance. An outstanding checkout allowed by
the existing owner policy shows its unpaid charges but explicitly issues no
bill. Security and company/branch filters remain on all endpoints.

## Compatibility matrix

| Configuration | Required behavior |
| --- | --- |
| Reporting OFF, local covered folio | L-series, regulator mode PRA, NULL PRA status; no reporting |
| Reporting ON, direct cloud | Only explicit confirmation creates the fiscal bill and invokes the existing submission path |
| Reporting ON, fiscal-device/Agent | Confirmation saves pending; the existing Agent pipeline handles reporting |
| Standalone company with a legacy reporting flag | Local confirmation, never PRA submission |
| Charges, tax, account scope or reporting change after preview | Expiring encrypted fingerprint rejects stale confirmation before any payment/bill write |
| Lost confirmation response / double click | Same stay/account/flow UUID resolves the saved bill; no second transaction |
| Other tenant or active branch | Preview, confirmation and status inaccessible |
| Housekeeping / restricted staff / read-only impersonation | Existing front-desk and write middleware apply |
| Already-working legacy controller clients | Existing routes remain compatible; the new reception forms target token-required confirmation, including when JS fails |
| Local / pending / failed / offline bill | No accepted fiscal QR; status refresh is GET only |
| Submitted bill with a saved PRA number | Display the saved fiscal number and receipt-compatible QR; existing receipt supplies print/download |

`HotelFolioInvoiceService::coveredCharges` is shared by preview and issuance;
tax snapshots, whole-rupee totals and folio coverage retain the existing engine.
Confirmation holds the stay and acting user locks, recomputes the preview,
then invokes existing settlement/checkout services. Encrypted preview tokens
expire after ten minutes and bind tenant, stay, account, payload and financial
state. Audit logging continues in the existing services.

Status `submitted` plus a fiscal number is PRA acceptance. Neither a pending
bill nor a print callback proves physical paper. Status polling is bounded
and does not resubmit. This change provides no credit-note automation and
does not establish Hotel category acceptance by a real PRA endpoint.

## Verification

Feature tests exercise real HTTP preview/confirmation, unchanged folio on
preview, idempotent issue/checkout, stale/tampered tokens, reporting changes,
tenant isolation and fictional pending/accepted/failed status fixtures.
Desktop and 390px browser acceptance opens and closes the actual dialog,
asserts zero confirmation requests on close, preserves card payment inputs,
confirms once, checks local receipt without fiscal QR and opens stay details.
All fixtures are synthetic; no production or external PRA request is used.
