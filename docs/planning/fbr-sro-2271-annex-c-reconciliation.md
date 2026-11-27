# FBR SRO 2271(I)/2026 and Annex-C reconciliation

## Scope

FBR's [23 November 2026 notification](https://download1.fbr.gov.pk/SROs/2026112315111835527SRO2271.pdf) replaces the STR-7 return under Rule 14(1), including the domestic sales Annex-C. The notification does not specify the first tax period available in IRIS. The published [FBR Digital Invoicing API specification](https://download1.fbr.gov.pk/Docs/20257301172130815TechnicalDocumentationforDIAPIV1.12.pdf) is the baseline for invoice submission. Any proposed API change requires separate published FBR/PRAL instructions or a reproducible API issue.

## Verify when the return draft opens

- [ ] Start with the November 2026 return draft once IRIS makes it available. Record the tax period, observation date and whether revised Annex-C columns appear. If November still shows the earlier layout, record the result and confirm the first live period through FBR/PRAL guidance or the next available draft.
- [ ] The account owner selects an existing FBR-accepted TaxNest invoice for that same period. Match it in IRIS Digital Invoicing under **Manage Your Invoices**, then in the draft return under **Annex-C (Sales)**. If the row is absent, use **Load Invoices** if offered, as described in the [FBR return guide](https://e.fbr.gov.pk/SOP/IRIS/Filing_of_New_Sales_Tax_and_Federal_Excise_Return.pdf). Check for duplicates. Use the draft for reconciliation and leave filing to the account owner.
- [ ] Match the invoice number and date, buyer type, sale origination and destination provinces, sale type, HS code, quantity, UoM, taxable value and sales tax. Check retail price and other tax amounts where applicable. For revised fields such as buyer verification status, additional sales tax and APR reference, record whether IRIS fills them, requires entry or leaves them blank in the observed case. Confirm ambiguous field mappings against FBR/PRAL guidance.
- [ ] If an accepted invoice appears in IRIS Digital Invoicing but is missing from Annex-C, record the discrepancy privately and confirm the tax period and loading behavior before proposing a TaxNest change. Avoid entering a duplicate or resubmitting an accepted invoice to run the check.

## Decide whether TaxNest needs a change

- [ ] Confirm whether TaxNest generates an Annex-C export or a reconciliation report used for filing. If it does, compare its columns, values and format against the live IRIS template or official instructions for the confirmed period.
- [ ] If a reproducible export gap exists, scope the fix to the affected report or export. Use fictional invoice fixtures to cover the revised mapping, the previous export behavior where supported and tenant isolation. Review the test results against the confirmed IRIS format.
- [ ] Record the verified period, matching outcome and whether an export fix is warranted. Keep credentials, taxpayer identifiers, live invoice details and original screenshots out of GitHub. The account owner retains any live evidence privately.

**Status:** Pending owner-side IRIS verification.
