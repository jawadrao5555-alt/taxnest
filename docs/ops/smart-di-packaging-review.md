# CCI packaging and deposit review compatibility

The sanitized complete pilot contains 90 empty/deposit movements described
with IMPLIED EMP or IMP EMP, and 12 damage rows using DMG/DMGD abbreviations.
The old classifier routed none of those 90 lines to packaging review; negative
deposit returns were overwritten as damage adjustments, so a reviewed paired
deposit could never become an approved sale draft.

Recognize these explicit description tokens as review candidates only. Preserve
negative empty/deposit rows in the packaging review queue. Each charge/return
still requires its own human exclusion reason, complete original invoice
financial reconciliation and verified buyer. Nothing is automatically excluded,
netted, classified for tax, or submitted. Ordinary RB beverage descriptions
remain products; unknown or negative beverage movements remain blocked. No
source SKU/company hard-coding or default HS/MRP assumptions are introduced.

| Configuration | Expected behavior |
| --- | --- |
| Explicit positive/negative empty deposit | Separate reasons; full source audit; excluded from sale items |
| Missing either reason | Whole original invoice blocked |
| Paired deposits net zero | Both original movements retained; sales-only draft totals unchanged |
| Damaged/negative beverage | Separate adjustment review, no automatic credit-note posting |
| Ordinary returnable-bottle beverage | Verified product mapping still required |
| Other tenant / unknown SKU-buyer | Existing isolation and complete-invoice gates unchanged |

Before-fix controller/service regression failed with unknown packaging SKU and
negative-adjustment blocker. Full pilot classifier regression found 0 rather
than 90 packaging rows. Post-fix tests require 90 packaging, 12 adjustment and
311 product reviews; explicitly reviewed pairs create one local sale item and
one unique receipt whose audit keeps all three original rows, including the
negative deposit. Draft has no FBR number. Existing real authenticated source
journey now includes paired deposits and explicit reasons on desktop/390px.

Fresh full CI/MariaDB/browser validation is required before owner release.
Real SKU, buyer, MRP and VAT evidence remains HOLD; #185 is not complete.
