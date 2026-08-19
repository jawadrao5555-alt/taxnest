# Task 1315 — Invoice 689 cigarette UoM fix (FBR error 0099) — 19 Aug 2026

## What FBR error 0099 means
FBR restricts which units of measurement (UoM) may be reported per HS code. Error
0099 ("Provided UoM is not allowed against the provided HS Code") means the invoice
line's UoM string is not in FBR's allowed list for that HS code.

## What FBR wants for cigarettes
FBR's own reference API (`GET https://gw.fbr.gov.pk/pdi/v2/HS_UOM?hs_code=2402.2000&annexure_id=3`,
queried with company 22's token) returns exactly two allowed UoMs for HS 2402.2000:

- `KG` (uoM_ID 13)
- `Thousand Unit` (uoM_ID 79)

The owner's Annexure-A (Philip Morris supplier report) uses **"Thousand Unit"** — that
is the industry convention for cigarettes and the value applied here.

## Data fix applied on live (company 22, invoice 689 = 3120180085013DI00002)
- Items 716–720 (all HS 2402.2000): `default_uom` changed
  `"Numbers, pieces, units"` → `"Thousand Unit"`. **Nothing else touched** —
  quantities, prices, MRP, discounts, taxes, totals (6,190.96 / 1,157.14 / 7,348.10),
  WHT 183.78 and the invoice number are all unchanged.
- Rebuilt payload verified read-only before any submit: all 5 lines
  `uoM="Thousand Unit"`, retail base 6,428.53 + tax 1,157.14 (per the task-1307
  amount semantics), pre-submission validation clean.

## FBR validation result and production acceptance after the fix
- **Sandbox validator** (`validateinvoicedata_sb`, scenario SN027): **statusCode 00,
  status "Valid", all 5 items Valid** — the 0099 is fully resolved; the payload shape
  is FBR-approved.
- The initially stored production-token field contained the old sandbox token, so
  production returned 0401. The owner supplied Al Rehman's real production token
  securely; it was saved to company 22's encrypted Production Token setting.
- **Production validator:** statusCode **00**, status **Valid**; every one of the
  five lines is Valid.
- **Production submission:** accepted successfully at 21:06. FBR assigned
  **`3120180085013DI0AABI8607193`** (fbr_logs id 366, status success).
- Final invoice state: `locked` / `production`, processing flag clear, integrity
  hash and QR data saved, and one invoice ledger entry created. Original invoice
  number remains `3120180085013DI00002`; total remains 7,348.10 and WHT 183.78.

Forensic note: the earlier "production 0099" (fbr_logs 364, 20:00) actually went to
the **sandbox** endpoint — its logged payload contains `scenarioId: "SN027"`, which
`buildPayload` only adds when the company environment is sandbox at build time. The
`environment_used=production` label on that log was misleading.

## Prevention shipped (deployed to live, commit bcb2562e)
1. `FbrService::getValidUomsForHsCode` — live HS→allowed-UoM lookup via FBR's
   reference API, cached 7 days (+10-min negative cache).
2. `buildPayload` auto-corrects an invalid UoM to an FBR-valid one (preferred pick
   per product family, e.g. 2402 → Thousand Unit).
3. `validatePayloadPreSubmission` now fails locally with the exact allowed list
   ("FBR only accepts: KG, Thousand Unit…") instead of a production 0099 round-trip.
   Verified live.
4. `/api/hs-lookup` (invoice form) corrects `default_uom` and returns `valid_uoms`,
   so new items default to an FBR-valid UoM; "Thousand Unit" added to the invoice
   UoM dropdowns.
