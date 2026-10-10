# Customer payable backlog review — 9 October 2026

## Finding
Issue #46 remains open, but current checkout/history/Transactions already use the persisted final total, with existing cash/card discount regression coverage. This PR verifies that existing behavior instead of recalculating historical invoices from current tax settings.

No application code, monetary snapshots, PRA payloads, numbering, stored discounts or company settings are changed.

## Compatibility matrix
| Case | Check |
|---|---|
| Exclusive cash discount | Real checkout; saved tax/payable, quick view, history and Transactions agree |
| Inclusive cash percentage discount | Same surfaces agree; included tax retained |
| Inclusive card-save discount | Menu subtotal and card saving remain separate from payable |
| QR/prepaid card-save | Added to real checkout matrix, not inferred from the cash path |
| Cash with no discount | Existing unchanged case remains required |
| Both 58mm/80mm receipts | Real protected receipt routes render saved payable |
| CSV and PDF | Actual export contains saved payable; PDF renders a real document |
| Read operations | Before/after monetary and fiscal snapshot fields remain identical |
| Other tenant/cashier | Existing access-denial coverage remains mandatory |

## Browser reproduction
A separate allowlisted loopback fixture contains a discounted synthetic QR bill (menu 330, ordinary discount 14, payable 316) and unchanged cash bill (330). Chrome clicks each real history row, checks its modal, opens the actual receipt and Transactions row, and downloads CSV/PDF at desktop 1366 and mobile 390 widths. These manually seeded snapshots prove display/export agreement; they are not a claimed reproduction of the historical shop's exact card-tax configuration. The independent HTTP checkout matrix exercises real persistence and card-save calculations.

## Evidence and limits
- Local PHP/Composer are unavailable; the added PHP and real Chrome journeys run only in isolated PR CI.
- Browser runner `node --check`: PASS.
- Full CI must pass before owner approval. No actual PRA submission, production data rewrite, merge or deployment.
- Historical rows cannot safely be repaired from a receipt screenshot alone; this PR does not alter historical amounts.
