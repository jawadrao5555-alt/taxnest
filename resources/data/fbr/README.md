# FBR Domestic Sales Invoices reference data

`dsi-1.0.44.json` preserves the independent dropdown lists from the hidden `REFERENCES` worksheet of the FBR Domestic Sales Invoices workbook. The source marks its sample period August 2026. The version and original file SHA-256 travel with the extract. The original file contains Excel macros. The JSON contains only reference values.

| List | Source cells | Nonblank entries |
|---|---|---:|
| Item serial | B4:B732 | 729 |
| SRO or schedule | D4:D99 | 96 |
| Document type | F4:F7 | 4 |
| Unit of measure | H4:H34 | 30 |
| Province | J4:J11 | 8 |
| Buyer type | L4:L7 | 4 |
| Sale type | N4:N30 | 27 |
| Rate | P4:P117 | 114 |
| HS code and source description | R4:R9142 | 9,139 |
| Reason | T4:T10 | 7 |
| Petroleum levy | V4:V7 | 4 |
| Additional sales tax rate | X4:X6 | 3 |

Empty cells are skipped. List order, duplicate entries, source spelling and string whitespace are preserved. Numeric rate values are fractions (0.01 represents 1%). Compound rate entries are strings. Keep HS codes and item serials as text. Source HS entries can contain headings, letter suffixes, duplicate codes and empty descriptions. The cell at S689 duplicates an HS entry and falls outside the dropdown range.

### Using the lists in an invoice

Search the SRO and item serial independently. For example, `EIGHTH SCHEDULE Table 1`, `70` and the numeric rate `0.01` appear in three independent lists. Their presence does not establish a valid combination for an item, date, province or sale type. Use the applicable dated FBR reference and validate the actual payload. Row alignment between these catalogues has no relational meaning.

The workbook lists four DSI buyer classifications, including `Unregistered Distributor` and `Retail Consumer`. Digital Invoice API buyer registration and sandbox scenario selection are distinct fields. The template does not supply scenario IDs. For an invoice under test, review buyer type, identifiers, end-consumer status and the scenario actually sent.

On the invoice sheet, S is Extra Tax and T is Further Tax. The template's saved valid example has a blank S7 and a numeric 4 in T7 for Goods (FED in ST Mode). Its VBA amount check allows blank or nonnegative values in both columns. The API additionally validates the submitted sale type and the serialized field value. Preserve this distinction when debugging FBR error 0091.
