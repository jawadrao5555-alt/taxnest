---
name: Thermal receipt printable width
description: Why browser-printed thermal receipts clip on the right edge and the width rule every receipt template must follow.
---

# The ordering rule (v6, Jul 2026 — KOT blank-slip regression)
The `@media print` fix block must be the LAST rule set in the template's stylesheet. A media query adds ZERO specificity — a later base `body { width:80mm; margin:0 auto; }` rule silently overrides an earlier print-block fix, making it dead CSS. kitchen-ticket.blade.php had the v5 fix at the TOP of its `<style>` for weeks; prints were still the "right-edge strip on A4 queue" symptom while every grep said the fix was deployed. **When auditing thermal templates, check the print block's POSITION, not just its presence.** Receipts were never hit only because their print block happened to sit after the base styles.

# The rule
In `@media print`, NEVER force `body { width: <physical paper width> }` on thermal receipt templates. Use `width: auto; max-width: <paper>mm;` (+ tiny padding) so the body fills whatever printable width the printer driver reports.

# The side-padding rule (v7 padding bump, Jul 2026 — Pizza Master left-cut regression; template comment says "v6")
v5's `margin: 0` left-align sits the body at paper x=0 on drivers whose page size is the FULL roll width ("Roll Paper 80 x 297mm") — side padding must then cover the head's dead zone ALONE. 3mm sides < ~4mm dead zone cut thin first glyphs ("ITEM" → "TEM") on the 80mm receipt; sides raised to 4mm (80mm template). 58mm template still has 2.5mm sides (dead zone ~5mm on full-58mm drivers) — raise the same way if a 58mm shop reports a left cut.

**Boxed decorations need their own inset:** full-width bordered boxes (invoice-number box, PRA/local badges, PAYMENT box) draw VERTICAL border lines at the exact content edge — even with correct body padding they sit ON the dead-zone boundary and are the first thing eaten (owner video: boxes missing their left border while text survived). 80mm print block insets them 1mm/side (`.invoice-numbers, .pra-badge, .local-badge, .edge-box`); any NEW full-width bordered element on a receipt must join that selector list (or use `.edge-box`). Tables/text keep full width — only elements with vertical borders need the inset.

# The margin rule (v5, Jul 2026)
In `@media print`, body margin must be `margin: 0` — NEVER `margin: 0 auto`. **Why:** shops leave the printer queue's default paper size at A4; `auto` centering then parks the ~72mm body in the MIDDLE of an A4-wide page, so an 80mm head prints only the rightmost ~7mm strip (first 1-2 letters of each line at the paper's right edge) plus a long blank feed — looks like "blank paper" from silent print. `margin: 0` left-aligns, so the bill stays readable even on misconfigured queues; correctly configured 72mm queues are pixel-identical (no free space to center in). **Watch:** on drivers reporting full 80/58mm width, left padding is 3mm/2.5mm — if a printer's left dead zone exceeds that, raise padding to ~4mm (never force width). Advise shops to set the queue paper to 80mm receipt to kill the blank feed.

**Why:** owner's live shop (Jul 2026) reported right-edge clipping on a real 80mm thermal bill — "Cash"→"Cas", time/amounts cut. 80mm paper has only ~72mm printable width (58mm → ~48mm) because of printer hardware margins; a forced 80mm-wide body pushes the right ~8mm into the non-printable strip. `max-width` cap keeps A4/letter test-prints from stretching.

**How to apply:** every browser-printed thermal template (PRA 80mm/58mm receipts, restaurant receipt, kitchen ticket, FBR receipt) carries this fix with a "PRINTABLE-WIDTH FIX" comment. Any NEW receipt/ticket template must copy the pattern. Special case: FBR receipt's `$paperSize === 'a4'` branch deliberately re-fixes `width: 80mm` (centered on the big page — no clipping risk there). This is the browser-print sibling of the DomPDF issue (DomPDF uses media "screen", separate `$pdfMode` override already handles it).
