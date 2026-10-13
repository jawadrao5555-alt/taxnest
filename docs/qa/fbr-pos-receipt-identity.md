# FBR POS receipt identity correction

The previous final fiscal receipt respected optional tax/verify toggles and omitted POSID, STRN and the FBR logo. The correction forces fiscal fields on submitted invoices with an actual fiscal number. Local/pending/held invoices remain visibly distinct. No QR/acceptance is invented for a held bill.

Company profile gains explicit STRN and tax-office fields, separate from existing NTN/CNIC and business registration. New final invoices retain seller-profile snapshots. POS identity reads the branch/terminal snapshot from #200 when present; historical receipts use the available company profile and explicitly show missing fields. Existing saved display preferences are preserved for ordinary bills. Missing real registration data still needs the company owner; code cannot make an incomplete profile legally complete.

Sources checked 13 October 2026:
- https://download1.fbr.gov.pk/Docs/202551615541769POSBooklet.pdf
- https://www.fbr.gov.pk/pos-invoice-verification/163085/163142
- https://fbr.gov.pk/pos-legal-provisions/163085/163086

Required fiscal number, software/POS registration identity, FBR logo, QR and verification route are retained. Item taxes print from stored invoice values, without recomputing the tax. The existing official print-logo asset is embedded locally, and QR is locally generated. Central verification remains a separate action; a local IMS number does not prove central receipt.

Validation: 19 rendered-receipt tests / 253 assertions on local PHP 8.4.26, covering 58mm, 80mm, A4, disabled display preferences, immutable seller/POS identities and local/pending/offline/held states. Actual browser print-media/logo/QR checks added to the synthetic desktop/mobile CI fixture; their execution and full native MariaDB/CI remain pending at PR creation. No live fiscal API was called.
