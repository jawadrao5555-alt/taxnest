# Nine selectable KOT styles — owner contract, 25 October 2026

Status: specification and approved visual direction only. No runtime implementation, functional acceptance or deploy readiness in this commit.

## Explicit owner decision
Keep the existing KOT design as the first option (Existing / Current). Add eight OPTIONAL styles, for nine total:
1. Existing / Current (the tenant's current saved configuration)
2. Simple
3. Bold Items
4. Space Saver
5. Order First
6. Table First
7. Quantity First
8. Checklist (quantity must remain visible beside each checkbox)
9. Large Text

The reviewed compact preview board is a visual direction, not a pixel-perfect production template or proof of printer output.

## Compatibility is mandatory
No company changes style automatically. Missing/null/new style field must resolve to its existing KOT renderer and existing saved font, size, paper width, header, printer and routing settings. No bulk reset, inferred migration, shared browser-storage preference or change to historical transactions. Company chooses a preview and explicitly Saves before its style changes; preview/cancel is non-mutating. Persist with existing authorized company settings flow and server-side style allowlist. A second tenant must be unaffected.
Read the actual current receipt-settings and kitchen-settings KOT controls and unify behavior without conflicting settings. Existing arbitrary legacy typography must remain intact. Existing KOT design remains selectable even after choosing a new design.

## Content rules common to all nine
- Preserve order/KOT identity, date/time, table when applicable, item/deal identity and options, quantities, kitchen routing and add-on/cancel/reprint semantics.
- Whole-order TAKEAWAY or DELIVERY belongs in the header once. Do not automatically repeat PACK / TAKEAWAY beneath every ordinary item.
- Explicit item fulfilment overrides remain visible only for the relevant item/split quantities. Do not remove real mixed Dine-In/Parcel/Delivery instructions or infer them from free-text notes. Trace PosFulfillment metadata and current KOT renderers before editing.
- Item notes are shown only where entered, immediately with the correct item; no empty note row. The example chest+thigh belongs only to Al Baik Deal No. 1.
- Long names, Urdu where supported, options and multi-line notes must wrap without truncation or overlapping quantity.
- Content-fit paper length, compact header/margins and no unnecessary blank footer/feed. Retain driver-required safe cut/feed. Do not claim measured physical paper saving from an image preview.
- Add-on tickets contain only newly sent quantity, never resend already sent items. No bill/tax/price/stock/payment/fiscal changes and no automatic print from preview.

## Preview and acceptance
Use the same content/rendering contract for settings preview and print. Show sample fictional items, note/no-note, Takeaway, Dine-In/table, Delivery, mixed override and Add-on. A preview is not a real print job.
Before ready: reproduce the repeated Pack label, retest its removal and retention of explicit overrides; cover all nine styles, legacy saved settings, two tenants, role validation, invalid style rejection, reload persistence, cancelled preview, reprint/hold/recall, quantity and routing. Actual rendered desktop1366/mobile390 preview and generated print output require verification. Cover supported paper widths without changing saved width. All mandatory fresh exact-head CI lanes including own validate must pass. No live customer data or physical printers needed for local/CI checks; disclose remaining hardware limits.

## Customer receipt scope
Owner also wants current customer receipt + eight new styles (nine total), with the same explicit-save/legacy-preservation rule. Receipt samples are to be reviewed separately first. Do not implement receipt styling in this KOT PR. Preserve real discounts, tax/fiscal fields, total and existing receipt options when later implemented.

Source anchors: resources/views/pos/receipt-settings.blade.php; resources/views/pos/restaurant/kitchen-settings.blade.php; resources/views/pos/restaurant/kitchen-ticket.blade.php; app/Support/PosFulfillment.php. Notification/report PRs278/279 remain separate.
