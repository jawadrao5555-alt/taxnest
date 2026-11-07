# Smart UOM & Packing — Issue #265

**Status: design specification only. Implementation and tests pending. Do not deploy this draft PR as a finished feature.**

## Product Master
Preserve existing FBR HS code, UOM, tax category and legal reporting rules. Add optional product-specific selling packings; each has a label, positive exact decimal conversion to the validated FBR UOM, and optionally its own price/barcode. Support multiple packs and distinct sizes (e.g. 500ml and 1.5L bottles). Unconfigured products work unchanged.

## Invoice Entry
After selecting a product, offer its base UOM and configured packs in a single `Sell By` dropdown. Cashier enters quantity and rate in the selected selling unit. Convert to base/FBR quantity automatically. Preserve existing printed FBR-style invoice with legal reporting quantity. Save original selling quantity/unit and conversion snapshot for audit and stock.

## Examples
- Sona DAP: 1 Bori x 50 KG = 50 KG.
- Rice: 2 Bags x 25 KG = 50 KG.
- Cooking oil: 3 Cartons x 12 Bottles x 0.5 L = 18 L.
- Cooking oil: 2 Bottles x 1.5 L = 3 L.
- Biscuits: 1 Box x 12 Nos = 12 Nos.

## Safety & acceptance
Use decimal-safe quantity and money arithmetic. Verify HS/UOM compatibility and statutory Third Schedule price/tax basis; do not blindly convert unrelated dimensions. Handle tax-inclusive/exclusive rates, discounts, returns, multiple lines, rounding and inventory. Align with #262 exact-total rounding fix. Ensure FBR payload, persisted values and PDF totals match, and prior reported invoices remain immutable. Tenant isolation, audit trails and tests required.

Implementation must be committed and CI validated before draft status is removed.
