# Task 1307 — Live data correction record: DI invoice 689 (`3120180085013DI00002`)

**Date:** 19 Aug 2026 · **Company:** #22 AL REHMAN TRADERS (DI panel, LIVE) · **Scope:** data correction only — **no code changes, no FBR submission**. The owner reviews and submits from the Drafts tab himself.

## Why it failed

FBR **production** rejected the invoice with error **0300** at item #6 (fbr_logs id 363):

> "Provided numeric values are invalid. Numeric values must not be negative, null, empty or string type at item 6 for valueSalesExcludingST"

Item #6 was the free/bonus line **"Morven Classic Foil" (4 CT / 7 PK, Rs 0.00)** — FBR always rejects zero-value item lines.

Beyond the rejection, the data entry did not match the physical distributor receipt
(`attached_assets/WhatsApp_Image_2026-08-19_at_6.13.11_PM_1787148985066.jpeg`):

1. **Free Rs 0.00 line submitted as a taxable item** → guaranteed FBR 0300.
2. **Morven Classic qty 2 instead of 20** (receipt: 2 cartons = 20 packs; only "2" was keyed in).
3. **Tax-on-tax:** receipt line amounts (tax-inclusive, and including Rs 183.78 advance income tax) were entered as ex-ST unit prices, and 18% was charged on top of them.
4. **Standard rate instead of 3rd Schedule:** cigarettes (HS 2402.2000) are 3rd Schedule goods — sales tax is 18% of retail/MRP, not of transaction value.

## What was written (mirrors the panel's own edit/update path)

Applied 19 Aug 2026 via a one-off Laravel-bootstrap script over SSH (deleted after the run), mirroring
`InvoiceController::update()` for a `failed` invoice: header totals + `status='draft'` + `fbr_status='pending'`,
items deleted and recreated through the `InvoiceItem` model, plus `invoice_activity_logs` ('edited', with full
old-item snapshot) and `audit_logs` ('invoice_edited') rows — the same records a panel edit writes.
Invoice number and `is_fbr_processing` untouched (per-company numbering invariant: never renumber).

Corrected line set — 5 lines, all HS 2402.2000, `3rd_schedule` / "3rd Schedule Goods", 18% on retail.
Receipt amounts (sum 7,531.88, incl. Rs 183.78 advance income tax) were stripped proportionally to the
tax-inclusive base 7,348.10; the retail base 1,157.14 / 0.18 = 6,428.56 was allocated proportionally
(receipt shows no per-item MRPs — owner can adjust per-line MRPs in the panel if he has the notified retail prices).

| # | Item | Qty | Unit price (ex-ST) | Line ex-ST | Unit MRP | Retail base | Tax (18% of retail) | Line incl. | Receipt share of 7,348.10 |
|---|------|----:|----:|----:|----:|----:|----:|----:|----:|
| 1 | Crafted By Marlboro | 2 | 139.71 | 279.42 | 145.07 | 290.14 | 52.23 | 331.65 | 331.65 |
| 2 | Morven | 8 | 198.08 | 1,584.64 | 205.68 | 1,645.44 | 296.18 | 1,880.82 | 1,880.78 |
| 3 | Red & White Firm Filter | 5 | 156.61 | 783.05 | 162.62 | 813.10 | 146.36 | 929.41 | 929.41 |
| 4 | Morven Classic | **20** | 140.12 | 2,802.40 | 145.50 | 2,910.00 | 523.80 | 3,326.20 | 3,326.27 |
| 5 | Diplomat | 5 | 148.29 | 741.45 | 153.97 | 769.85 | 138.57 | 880.02 | 879.99 |
|   | **Totals** | | | **6,190.96** | | 6,428.53 | **1,157.14** | **7,348.10** | 7,348.10 |

- Per-line stored `tax` equals `round(mrp × qty × 0.18, 2)` exactly, so `FbrService::buildPayload()`
  (which recomputes 3rd-Schedule tax from `mrp`) reproduces the same **per-line tax figures** — total 1,157.14.
- **FBR payload semantics for 3rd Schedule (by design, this is how the app submits ALL 3rd-Schedule DI invoices):**
  `buildPayload()` sets `valueSalesExcludingST` = retail base (MRP × qty), not the commercial ex-tax value —
  3rd-Schedule tax is levied on retail price. So FBR receives value 6,428.53 + tax 1,157.14 = totalValues 7,585.67,
  while the invoice header/PDF shows the commercial figures 6,190.96 / 1,157.14 / 7,348.10. The physical receipt
  itself confirms this split: its tax 1,157.14 is 18% of the retail base 6,428.56 (NOT of 6,190.96), and its
  customer total is 7,348.10. The 0.03 gap between payload retail 6,428.53 and receipt-implied 6,428.56 comes
  from rounding per-unit MRPs to 2 decimals (DB columns are 2dp).
- **Owner action before submitting:** the per-line MRPs are proportional allocations (the receipt lists no
  per-item retail prices). If the actual notified retail prices are available, edit the draft in the panel and
  replace the MRPs first — tax will recompute from them. Until then the draft stays unsubmitted.
- Free line "Morven Classic Foil" **excluded** from items (noted in the activity log — no notes column exists on invoices).
- Advance income tax Rs 183.78 excluded — not part of the DI payload.
- At 18% no SRO/serial is required for 3rd Schedule (`FbrService` `needsSro` false; `ScheduleEngine` requires only MRP).

## Production verification (read-only, 19 Aug 2026 after correction)

```
invoices id=689: invoice_number=3120180085013DI00002 status=draft fbr_status=pending is_fbr_processing=0
  buyer=HASSAN SUPER STORE (Unregistered) dest=Punjab doc=Sale Invoice date=2026-08-19
  total_value_excluding_st=6190.96 total_sales_tax=1157.14 total_amount=7348.10 net_receivable=7348.10

invoice_items (id | desc | qty | price | line_ex | mrp | retail | tax | schedule | sale_type | rate | hs):
  716 | Crafted By Marlboro     |  2.00 | 139.71 |  279.42 | 145.07 |  290.14 |  52.23 | 3rd_schedule | 3rd Schedule Goods | 18 | 2402.2000
  717 | Morven                  |  8.00 | 198.08 | 1584.64 | 205.68 | 1645.44 | 296.18 | 3rd_schedule | 3rd Schedule Goods | 18 | 2402.2000
  718 | Red & White Firm Filter |  5.00 | 156.61 |  783.05 | 162.62 |  813.10 | 146.36 | 3rd_schedule | 3rd Schedule Goods | 18 | 2402.2000
  719 | Morven Classic          | 20.00 | 140.12 | 2802.40 | 145.50 | 2910.00 | 523.80 | 3rd_schedule | 3rd Schedule Goods | 18 | 2402.2000
  720 | Diplomat                |  5.00 | 148.29 |  741.45 | 153.97 |  769.85 | 138.57 | 3rd_schedule | 3rd Schedule Goods | 18 | 2402.2000

SUM: ex=6190.96 tax=1157.14 incl=7348.10 retail=6428.53
invoice_activity_logs: edited @2026-08-19 19:37:33 (after fbr_failed @19:31:00, submitted @19:30:59)
company 22 drafts count = 1  (this invoice, visible in the Drafts tab)
```

## FBR payload verification (read-only `FbrService::buildPayload()` run on live, 19 Aug 2026)

Rendered via a one-off read-only script on live (deleted after run); **no writes, invoice untouched** —
confirmed `status=draft fbr_status=pending` after the run.

```
PAYLOAD ITEM 1: Crafted By Marlboro     | 3rd Schedule Goods 18% qty=2  value=290.14  tax=52.23  retail=290.14  totalValues=342.37
PAYLOAD ITEM 2: Morven                  | 3rd Schedule Goods 18% qty=8  value=1645.44 tax=296.18 retail=1645.44 totalValues=1941.62
PAYLOAD ITEM 3: Red & White Firm Filter | 3rd Schedule Goods 18% qty=5  value=813.10  tax=146.36 retail=813.10  totalValues=959.46
PAYLOAD ITEM 4: Morven Classic          | 3rd Schedule Goods 18% qty=20 value=2910.00 tax=523.80 retail=2910.00 totalValues=3433.80
PAYLOAD ITEM 5: Diplomat                | 3rd Schedule Goods 18% qty=5  value=769.85  tax=138.57 retail=769.85  totalValues=908.42
PAYLOAD SUMS: valueSalesExcludingST=6428.53 salesTaxApplicable=1157.14 totalValues=7585.67
HEADER: buyer=HASSAN SUPER STORE regType=Unregistered buyerProvince=Punjab sellerProvince=Punjab docType=Sale Invoice date=2026-08-19
PRE-SUBMISSION VALIDATION: PASS (no errors)
```

- Every line's `valueSalesExcludingST` > 0 → the FBR 0300 rejection cause is gone.
- Per-line payload tax equals the stored/header tax exactly (sum 1,157.14 — matches the receipt).
- `salesTaxApplicable` = 18% of `fixedNotifiedValueOrRetailPrice` on every line — 3rd-Schedule consistent.
- No SRO/serial required or emitted (18% rate).
- (`scenarioId SN027` appeared in this render because the company row is currently set to sandbox env;
  a production submission builds the same payload without `scenarioId`.)

## Reconciliation: header 7,348.10 vs FBR payload totalValues 7,585.67 — not a defect of this correction

This is the app's **standing, production-validated 3rd-Schedule convention** (predates this task, used by every
3rd-Schedule DI invoice from this app, sandbox scenario SN008/SN027-passing): `valueSalesExcludingST` is reported
on the retail base because 3rd-Schedule sales tax is levied on retail price, while the invoice header/PDF keeps
the commercial figures. **No data can satisfy both representations simultaneously** — the physical receipt itself
proves it: its tax 1,157.14 is 18% of 6,428.56 (retail), not 18% of its own ex-tax value 6,190.96. Any line set
whose tax matches the receipt (1,157.14) necessarily has a retail-based FBR value of ≈6,428.5x and an FBR-side
totalValues of ≈7,585.67. The fiscally decisive figure — the sales tax — is exact on both representations.
Changing the payload semantics is a code change, explicitly out of scope for this task and it would affect every
other DI company.

**Owner-visible safeguard (no code change):** a `review_note` activity-log entry was added on the invoice
(renders on the invoice page's activity timeline) telling the owner to replace the proportionally-allocated MRPs
with the actual notified retail prices before submitting, and documenting the excluded free line and stripped
advance tax. The task's Done criteria require this invoice to end as a normal Drafts-tab draft that the owner
reviews and submits himself — it is intentionally NOT blocked from submission.

## Re-verify anytime (read-only)

```sql
SELECT id, invoice_number, status, fbr_status, total_value_excluding_st, total_sales_tax, total_amount
  FROM invoices WHERE id = 689;
SELECT description, quantity, price, mrp, tax, schedule_type, sale_type FROM invoice_items WHERE invoice_id = 689;
```

(Run on the live DB — SSH per `scripts/deploy-live.sh` host settings, creds from live `.env`.)
