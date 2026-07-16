---
name: Thermal receipt printable width
description: Why browser-printed thermal receipts clip on the right edge and the width rule every receipt template must follow.
---

# The rule
In `@media print`, NEVER force `body { width: <physical paper width> }` on thermal receipt templates. Use `width: auto; max-width: <paper>mm;` (+ tiny padding) so the body fills whatever printable width the printer driver reports.

**Why:** owner's live shop (Jul 2026) reported right-edge clipping on a real 80mm thermal bill — "Cash"→"Cas", time/amounts cut. 80mm paper has only ~72mm printable width (58mm → ~48mm) because of printer hardware margins; a forced 80mm-wide body pushes the right ~8mm into the non-printable strip. `max-width` cap keeps A4/letter test-prints from stretching.

**How to apply:** every browser-printed thermal template (PRA 80mm/58mm receipts, restaurant receipt, kitchen ticket, FBR receipt) carries this fix with a "PRINTABLE-WIDTH FIX" comment. Any NEW receipt/ticket template must copy the pattern. Special case: FBR receipt's `$paperSize === 'a4'` branch deliberately re-fixes `width: 80mm` (centered on the big page — no clipping risk there). This is the browser-print sibling of the DomPDF issue (DomPDF uses media "screen", separate `$pdfMode` override already handles it).
