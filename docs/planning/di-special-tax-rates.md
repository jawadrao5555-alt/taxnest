# DI Phase 4 — Verified per-unit and mixed-rate invoice handling

Builds on the Phase 2 reference checks and [#455](https://github.com/jawadrao5555-alt/taxnest/pull/455) product profiles. The invoice picker reads [DSI 1.0.44](../../resources/data/fbr/dsi-1.0.44.json). Its worksheet lists rates, sale types and units separately, so adjacency or similar names do not establish a valid combination.

## Workbook inventory

The picker currently accepts 58 percentage entries, seven sale types and ten unit labels. The remaining 56 rate labels, 20 sale types and 20 unit labels are reference-only. The table accounts for all 56 rate labels. Whitespace at the end of a workbook label is omitted here.

| Reference-only rates | Count | Unresolved requirement |
| --- | ---: | --- |
| `18% along with rupees 60 per kilogram` | 1 | Section below identifies the statutory rule and scenario. Confirm the exact accepted HS, unit, schedule, serial and line amounts. |
| `17% along with rupees 60 per kilogram`, `17% along with rupees 90 per kilogram` | 2 | Historical versions of Eighth Schedule Table I serial 56. Confirm applicable invoice date, eligibility and whether FBR supports the historical reference in DI. |
| `18% and Rs. 80 per Liter` | 1 | Identify the charging provision, covered HS codes and component allocation before treating the amount as tax, excise or levy. |
| `50/SqY`, `100/SqY` | 2 | Confirm the notification, taxable area basis, HS or service context and FBR unit. |
| `200/bill` | 1 | Confirm what constitutes the taxable bill and whether the regulator allocates the charge across lines. Multiplying by line quantity would change the basis. |
| `Rs.4/KWH`, `Rs.7/KWH`, `Rs.9/KWH`, `Rs.13/KWH` | 4 | Match the exact electricity scenario and dates, confirm whether the measurement is supplied energy or manufacturing consumption, and confirm the reporting scale. |
| `Rs.425/MT`, `Rs.700/MT`, `Rs.5600/MT`, `Rs.5862/MT`, `Rs.6700/MT`, `Rs.9500/MT`, `Rs.10400/MT` | 7 | Match each slab to the effective notification and sale type. Confirm whether it is a tax amount or a valuation basis and how the metric-ton quantity is reported. |
| `Rs.250/IMEI`, `Rs.300/IMEI`, `Rs.650/IMEI`, `Rs.1000/IMEI`, `Rs.1500/IMEI` | 5 | Confirm product and value-band mapping, charge type, effective dates and treatment of a device with more than one IMEI. |
| `Rs. 16500 per KG` | 1 | Confirm the matching excise or sales-tax provision, HS codes, dates and DI scenario. The per-kilogram text alone does not identify the tax type. |
| `Rs. 2000 per Fan` | 1 | First Schedule Table I serial 60 of the Federal Excise Act covers energy-inefficient fans that fail the notified standard. Confirm product eligibility and how DI expects this FED and any sales tax to be reported together. |
| `Rs.2`, `Rs.3`, `Rs.5`, `Rs.10`, `Rs.130`, `Rs.200`, `Rs.250`, `Rs.1000`, `Rs.13.9`, `Rs.1680`, `Rs.1740`, `Rs.3.38`, `Rs.3.60`, `Rs.4.72`, `Rs.4.76`, `Rs.5.58`, `Rs.5400`, `Rs.9.36`, `Rs.9.63`, `Rs.9.89`, `Rs.9270`, `Rs.10.58`, `Rs.10.65`, `Rs.12.89`, `Rs.14.48`, `Rs.18.47`, `Rs.18.57`, `Rs.25.16`, `Rs.29.57` | 29 | These labels omit the denominator. Require the charging provision, applicable sale type, HS and reporting basis before interpreting any value. |
| `DTRE`, `Exempt` | 2 | These labels describe treatments. Existing exempt calculation is selected through Sale Type. Confirm the DTRE eligibility, sale type and DI representation separately. |

The reference-only sale types are Petroleum Products, Electricity Supply to Retailers, SIM, Gas to CNG stations, Mobile Phones, Rerollable scrap by ship breakers, Processing/Conversion of Goods, Goods (FED in ST Mode), DTRE goods, Cotton ginners, Electric Vehicle, Cement /Concrete Block, Telecommunication services, Steel melting and re-rolling, Ship breaking, Potassium Chlorate, Non-Adjustable Supplies, Goods as per SRO.297(|)/2023, CNG Sales and Toll Manufacturing. Obtain the effective FBR rate and any schedule/serial for the specific scenario. SN025's use of “fixed” describes the non-adjustable drug scenario and is insufficient evidence of a rupee-per-unit rate.

The reference-only workbook unit labels are `MT`, `Bill of lading`, `SET`, `NO`, `1000 kWh`, `KWH`, `40KG`, `SqY`, `Bag`, `MMBTU`, `Carat`, `Gram`, `Gallon`, `Pound`, `Timber Logs`, `Pair`, `Square Foot`, `Mega Watt`, `Foot` and `Barrels`. Verify the exact HS UOM response and unit factor. A selling pack can be converted only through a defined factor into a compatible reporting unit. A short unit label alone is insufficient.

## First regulatory scenarios

- **Mixed sales tax, SN022.** The FBR consolidation to 30 June 2026 gives 18% plus PKR 60/kg under Eighth Schedule Table I serial 56 for import and supply of potassium chlorate, with an exception to the fixed component for the specified Ministry of Defence Production organizations. The DI spec shows rate reference ID 734 with a mixed descriptor and a numeric rate value of 18. The numeric value captures only the percentage. DI v1.12 identifies the sale type and scenario. The parties' eligibility and the rate in force on the invoice date still need confirmation for each accepted combination.
- **Per-unit excise, SN021.** Federal Excise Act First Schedule Table I serial 13 has PKR 4/kg for the specified hydraulic cements (PCT heading 25.23) in the consolidation to 30 June 2026. SN021 covers cement and concrete blocks. Confirm the applicable goods and tax basis independently of the scenario label, including the sales-tax basis for retail-packed cement under the Third Schedule. An accepted scenario trace must show whether the per-unit charge is in `fedPayable` or reported in ST mode, and the exact value used in `fixedNotifiedValueOrRetailPrice`.
- The published DI schema expects the rate descriptor in `rate`, the FBR reporting unit in `uoM`, the reporting quantity in `quantity`, the sales value before sales tax in `valueSalesExcludingST` and the tax amount in `salesTaxApplicable`. Even with the statutory amount established, the generic schema leaves scenario-specific treatment to be confirmed. Use the applicable date, origin, HS, transaction and FBR reference lookups before validating the line. Record the scenario's `totalValues`, `fixedNotifiedValueOrRetailPrice`, `salesTaxApplicable`, `fedPayable` and SRO/serial values from the accepted request. Keep the evidence private and redact company identifiers and credentials.

Sources
- [FBR Sales Tax Act, amended to 30 June 2026](https://download1.fbr.gov.pk/Docs/20267171373418951SalesTaxAct1990updatedupto30.06.2026.pdf)
- [FBR Federal Excise Act, amended to 30 June 2026](https://download1.fbr.gov.pk/Docs/20267171373633512FEDAct2005updatedupto30-06-2026.pdf)
- [FBR DI API v1.12, invoice fields and sections 5.8, 5.9 and 9](https://download1.fbr.gov.pk/Docs/20257301172130815TechnicalDocumentationforDIAPIV1.12.pdf)

## Verification and release

- [x] Inventory the checked-in workbook, isolate two statutory cases and record the scenario-specific evidence gaps.
- [ ] Obtain authorized Sandbox reference and validation evidence for SN022 and SN021. Use a matching taxpayer profile in the Sandbox and verify both header and item results. Capture validation separately from a numbered Sandbox submission if one is required. Do not put raw responses or tokens in GitHub.
- [ ] Implement the accepted combinations in `DiInvoiceMath` and use the persisted fiscal values for both the invoice and the payload. The current payload builder emits a percentage label for taxable lines and derives monetary fields from percentage math. A mixed rate must preserve its verified label. FED and sales tax need separate persisted amounts wherever the scenario requires them. Preserve issued snapshots and the existing reference-only treatment for unverified combinations.
- [ ] Compare Create and Edit, persisted values, PDF and payload. Use fictional examples with no further tax, withholding or discounts to isolate the components. Standard 18% on PKR 1,000 is PKR 180. The SN022 candidate on PKR 1,000 at 2.5 kg gives PKR 180 ad valorem and PKR 150 fixed, for PKR 330 tax. Use equivalent reporting quantities entered directly and through packing to verify the same result. The SN021 candidate on 2.5 kg gives PKR 10 FED before any sales tax. Verify decimal quantity, rounding at the half-cent boundary, and the sales-tax basis with accepted scenario data.
- [ ] Run DI, browser and fiscal payload regression checks on the implementation head before requesting owner approval.

**Status:** Research updated. The statutory examples are identified, and scenario-specific Sandbox evidence is still pending. Runtime implementation and release acceptance remain open. IRIS Annex-C verification remains tracked in [#442](https://github.com/jawadrao5555-alt/taxnest/pull/442).
