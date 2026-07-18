---
name: POS payment-method card bucket
description: Card sales are STORED as 'debit_card' (universal screen normalizes) — any card-vs-cash aggregation must whereIn the full card alias set, never ='card'.
---

# POS payment-method card bucket

**Rule:** The PRA universal sale screen (and PosTaxRule rate lookup) normalize the UI's "Card" choice to `payment_method='debit_card'` before saving. Any aggregation splitting cash/card/other (day-close Z-report card_amount, analytics, PDFs) must use `whereIn('payment_method', ['card','debit_card','credit_card'])` for the card bucket and `whereNotIn(cash + card set)` for Other — never `where('payment_method','card')`.

**Why:** Jul 2026 live test: Z-report Card total was always Rs 0 (card sales fell into "Other") because four aggregation sites matched `='card'` while the store path saved `'debit_card'`. Fixed in both PRA and FBR day-close controllers.

**How to apply:** When adding any new report/aggregation touching payment_method, copy the inclusive card set from the existing day-close code. `qr_payment` stays in Other on purpose. FBR POS stores `cash,card,bank_transfer,online` — inclusive set is a harmless superset there. Historical day-close rows keep old (wrong) splits; not backfilled.
