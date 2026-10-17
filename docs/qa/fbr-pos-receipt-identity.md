# FBR POS receipt identity correction

The previous final fiscal receipt respected optional tax/verify toggles and omitted POSID, STRN and the FBR logo. The correction forces fiscal fields on submitted invoices with an actual fiscal number. Local/pending/held invoices remain visibly distinct. No QR/acceptance is invented for a held bill.

Company profile gains explicit STRN and tax-office fields, separate from existing NTN/CNIC and business registration. New final invoices retain seller-profile snapshots. POS identity reads the branch/terminal snapshot from #200 when present; historical receipts use the available company profile and explicitly show missing fields. Existing saved display preferences are preserved for ordinary bills. Missing real registration data still needs the company owner; code cannot make an incomplete profile legally complete.

Sources checked 13 October 2026:
- https://download1.fbr.gov.pk/Docs/202551615541769POSBooklet.pdf
- https://www.fbr.gov.pk/pos-invoice-verification/163085/163142
- https://fbr.gov.pk/pos-legal-provisions/163085/163086

Required fiscal number, software/POS registration identity, FBR logo, QR and verification route are retained. Item taxes print from stored invoice values, without recomputing the tax. The existing official print-logo asset is embedded locally, and QR is locally generated. Central verification remains a separate action; a local IMS number does not prove central receipt.

Validation: 19 rendered-receipt tests / 253 assertions on local PHP 8.4.26, covering 58mm, 80mm, A4, disabled display preferences, immutable seller/POS identities and local/pending/offline/held states. Actual browser print-media/logo/QR checks added to the synthetic desktop/mobile CI fixture; their execution and full native MariaDB/CI remain pending at PR creation. No live fiscal API was called.

## 17 October: refresh after deployed PR 200

Merged original f6c86d4ccab489191b5f346356ad5c4a21124d07 with deployed main 41fa0272215121ddccd4a2d40f2ae796180ab441, preserving both histories. Model creation combines seller profile and mapped POS/device capture; browser seed combines the new fiscal receipt journey with owner recovery, branch identity, reporting and actual production-CSP admin preview journeys. Snapshot header review found the live company name still printed above the stored seller; all header/logo-alt name paths now read the original fiscal seller. This discrepancy was identified by source review; local PHP/Chrome reproduction is unavailable.

Added actual DB regression for simultaneous seller + branch POS snapshots, profile/mapping changes, second-tenant isolation and local bill behavior. Render regression excludes changed live name/address/NTN on fiscal reprints. Extended early combined PHP preflight with identity/receipt regressions. Existing 58mm/80mm/A4, disabled toggles and held/pending/offline/local coverage retained. No saved preferences are reset; no fiscal/amount recomputation.

Local PHP/MariaDB/Chrome executables unavailable. Fresh exact-HEAD CI must execute combined PHP preflight, native migrations, full suite and actual fiscal image/print-media desktop/mobile browser tests. Node checks and static proofs do not replace those runs. Agent input/version stays 1.13.19. No production/regulator request or merge/deploy from the agent.
