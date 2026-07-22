---
name: POS feature upgrade backlog
description: Owner-deferred NestPOS PRA upgrade ideas (22 Jul 2026) + split-payment tax design options already discussed
---

# NestPOS PRA upgrade backlog (owner: "baad mein dekhenge", 22 Jul 2026)

Owner reviewed this list and deferred ALL of it. Do not build any item until he explicitly picks one.

1. Udhaar / Customer Khata — credit bills, ledger, wasooli record (agent's top pick)
2. Split Payment — one bill, part cash + part card (tax design below)
3. Return / Refund flow
4. WhatsApp Receipt to customer
5. Day-close summary to owner via WhatsApp/SMS
6. Kharcha (expenses) tracking → real profit on day-close
7. Low-stock alert + supplier/purchase module
8. Cashier shift system (shift-wise cash recon)
9. Barcode label printing
10. Loyalty points

Also pending idea: FBR POS has NO What's New popup/bell system (audience hardcoded 'pos', popup only in pos-app layout) — potential port.

## Split-payment tax design (discussed, NOT decided)
PRA constraint: tax rate depends on payment method (cash 16% / card 8%) and PRA payload carries ONE payModeCode per invoice — a mixed bill can't be reported as mixed.
- Option 1 (agent recommended, safest): 8% only if 100% card/digital; any cash → whole bill 16%, report cash. Receipt still shows cash/card breakdown.
- Option 2: dominant method decides whole-bill rate (loophole: 51% card halves tax).
- Option 3: auto-split into two bills (cash bill 16% + card bill 8%), each reported separately; 2 receipts, items can't be half-split.
Owner deferred the choice — re-present these options when he returns to split payment.
