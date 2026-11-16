# Collect missing CCI pilot evidence

The offline audit now returns `evidence_requests`: sheet, original evidence
row, optional source row and missing field names. It copies no customer name,
CNIC/NTN/phone, invoice number, SKU or amounts into this work list. Use the row
locator in the owner's private original workbook to collect the evidence.
Existing aggregate totals, money comparisons, hashes and HOLD status remain.

Run only on two owner-supplied local XLSX files, through the existing sandbox:

```bash
bash scripts/rc-safe-run -- php scripts/cci-pilot-evidence-audit.php \
  --source=/path/to/source.xlsx --evidence=/path/to/evidence.xlsx
```

For each requested cell collect the actual printed retail price/pack evidence,
reviewed classification/tax mapping, positive litres-per-case conversion when
required, buyer status evidence, SKU approval and invoice VAT review. The
work list is a locator, not permission to mark data VERIFIED without records.
Blank source VAT is not zero and cannot be allocated silently. Packaging and
damage/return partitions still need their separate existing review.

Rerun after updating the private evidence workbook. Fewer missing requests
still do not authorise import or fiscal submission: current company mappings,
source reconciliation, staged draft review and explicit batch approval remain
mandatory. This command never bootstraps the application, writes the source
files, changes company configuration, creates an invoice or calls FBR/PRA.

The 13-Oct evidence inventory is historical: 311 sales lines lacked printed
MRP/verified HS/tax/buyer evidence, 83 lacked conversion and 73 invoice gates
were held. These are not asserted as today's data. The actual confirmed inputs
must come from the owner; this PR does not invent them or clear the pilot.
