# Sanitized CCI Voyage pilot

`cci-voyage-sanitized.json` retains the observed block structure, 73 invoices,
413 item rows, 31 SKU codes, original supply dates, quantities, product labels
and financial values from the owner-supplied `7170.xlsx`. Invoice/outlet IDs,
buyer names, addresses, distributor/route and report metadata are replaced or
removed. The original workbook is never committed.

The layout has item VAT percentages and reported item Net Total, but no
explicit line VAT amounts. Parser leaves line discount/VAT/final net unset.
An administrator must verify allocations against source evidence and complete
company SKU/outlet mappings. No source percentage is converted automatically
into tax. All 73 footer gross-discount+VAT=net identities hold; this does not
certify item tax treatment or approve FBR posting.
