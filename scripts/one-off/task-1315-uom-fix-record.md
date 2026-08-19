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

## FBR validation result after the fix
- **Sandbox validator** (`validateinvoicedata_sb`, scenario SN027): **statusCode 00,
  status "Valid", all 5 items Valid** — the 0099 is fully resolved; the payload shape
  is FBR-approved.
- **Production**: submit attempt (fbr_logs id 365, 20:38) and two read-only
  `validateinvoicedata` probes all returned **0401 "Unauthorized access … the
  authorized token does not exist against seller registration number"** — with the
  seller as CNIC 3120180085013 AND as NTN 2824108.

## Why production still refused (NOT a UoM problem)
The company's stored "production token" is byte-identical to the sandbox token
(e4a651…bf23). It authenticates fine on sandbox and on the reference APIs, but FBR
production does not recognise it → 0401. Al Rehman Traders has never had a real
production token stored.

Forensic note: the earlier "production 0099" (fbr_logs 364, 20:00) actually went to
the **sandbox** endpoint — its logged payload contains `scenarioId: "SN027"`, which
`buildPayload` only adds when the company environment is sandbox at build time. The
`environment_used=production` label on that log is misleading. Production auth had
never actually been exercised before tonight.

## What unblocks acceptance
The customer (Al Rehman Traders) must generate their **production** Digital
Invoicing token in IRIS/PRAL (available once FBR enables production for their
registration — their sandbox scenarios now validate clean, which is the usual
prerequisite) and it must be saved in the company's FBR settings (Production Token
field). Then hit **Retry** on invoice 689 — the payload already builds correctly;
the invoice number and amounts will not change.

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
