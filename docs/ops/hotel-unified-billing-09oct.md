# Hotel unified billing and persistent receipt review

## Intended behavior
Native Hotel Bills and Tax Reports now render one invoice directory (including credit notes and standalone restaurant/counter bills for a native Hotel company), with filters, tax details, CSV/PDF and dashboard Back links. The legacy Tax Reports entry retains its PRA default; Bills starts with all authorized streams. Retail/restaurant report layouts retain their existing behavior. Unbilled stays remain available through Stays.

Check-in and checkout review use a draft receipt before issuance. Edit uses existing scoped date/rate/room and discount forms. Delete/Cancel uses the existing audited stay-correction flow; it is not a database hard delete. Fiscal-locked bills cannot be edited or cancelled through these actions. Confirm mounts the actual issued receipt inside the same review dialog. Only explicit Close dismisses it.

The invoice table opts out of the generic mobile inner-table scroller; the outer wrapper is the single horizontal scroller, keeping Credit Note actions pinned at both scroll edges.

Receipt dialogs have explicit responsive dimensions independent of generated utility CSS, including the credit-note reprint dialog. Saved paper, printer/Agent routing, durable print UUIDs and extension preference remain unchanged.

## Compatibility and isolation matrix
| Flow | Expected behavior |
| --- | --- |
| Native Hotel admin | One table, authorized all/PRA/local streams, signed credit netting; standalone outlet invoices remain in company tax totals |
| Cashier / hidden local | Existing billing and cashier scope; local stream unavailable |
| Partially visible stay | Hidden aggregate payment balance instead of leaking another stream/cashier |
| Other tenant / active branch | Transactions and stays scoped independently; receipt routes stay protected |
| Multiple invoices for one stay | Paid/due cards count the stay once; row amounts are explicitly stay balances |
| Credit note without refund | Reduces invoice totals; does not reduce money received until an actual refund |
| Deposit | Excluded from payment totals |
| Existing Agent / extension / counters | Existing print queue, attempt UUID, printer and unavailable-counter behavior |
| Retail / restaurant | Generic report view and history Back retained |
| Draft preview | No invoice, sequence, payment or fiscal submission created by opening/closing |
| Issued fiscal bill | Actual protected receipt; no draft Edit/Delete |

## Verification
PHP and Composer are unavailable in the scratch runtime. JavaScript syntax is checked locally; the PR's isolated MariaDB/PHP and Chrome desktop/mobile acceptance workflows provide execution evidence. The added regression cases cover credit/refund separation, exports, filters, tenant isolation, navigation, draft actions and fiscal locks. Chrome exercises persistent review, embedded actual receipts, dimensions, editing access, explicit Close and existing print retry journeys.

Physical printer output and actual PRA acceptance require owner live verification after the approval relay. No production access, credentials, merge or deployment is performed by this change.
