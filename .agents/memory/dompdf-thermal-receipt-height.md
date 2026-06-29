---
name: DomPDF thermal receipt page height
description: Why thermal-roll receipt PDFs clip, and the rule for sizing the DomPDF page so long receipts print/download in full.
---

# DomPDF ignores `@page { size: 80mm auto }`

Thermal receipt Blade templates declare a continuous roll via `@page { size: 80mm auto }`, but **DomPDF does not honor `auto` height**. The controller must give DomPDF an explicit page size via `setPaper([0,0,$widthPt,$heightPt])`.

**The bug:** a *fixed* height (e.g. hard-coded `1200`) clips any receipt taller than that — long item lists, long notes, PRA badge + QR all push content past the page and it silently disappears ("slip poori nahi hoti").

**The rule:** compute a *content-sized* height before `setPaper`. Sum: fixed chrome (header/totals/footer) + logo block (if any) + per-item lines (account for item-name **wrapping**: chars-per-line ≈ 18 for 80mm, 12 for 58mm) + discount row + **length-scaled** notes (notes wrap to many lines — never assume one) + reserved space for badge/QR/caption + a safety tail, with a sane floor.

**Why over-estimate, never under:** if real content still exceeds the page, DomPDF *paginates* — and for a single-slip thermal workflow a 2nd page reads as "cut off / incomplete". Blank tail (whitespace) is strictly better than clipping, so bias the estimate high.

**How to apply:** any change that adds/removes content rows on a thermal receipt (new totals line, extra badge, longer free-text field) must be reflected in the height estimator, or that content can clip. Wire the same estimator into **every** PDF path (download + public/share), not just one.
