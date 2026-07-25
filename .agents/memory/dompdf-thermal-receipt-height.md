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

## A4 PDF Download mode (customer video, 25 Jul 2026)
- Downloaded/shared receipt PDFs (exact 80mm-wide page) print SHIFTED-RIGHT + clipped on regular office printers: PDF viewers center the small page on the driver's A4 canvas; narrow tray paper catches only the left part.
- Fix = opt-in per-company 'PDF Download Paper' on /pos/receipt-settings (prefs pos_style.pdf_paper 'thermal'|'a4'). 'a4' = real A4 setPaper + late !important style block pinning the receipt strip (72mm/54mm) TOP-LEFT — block must come AFTER the pdfMode width:auto override to win the cascade.
- Applies ONLY to the two DomPDF paths (download + share link); HTML print paths (screen/restaurant/agent silent print) untouched — those feed real thermal printers.
- Known minor edge: A4 mode page 2+ starts at y=0 (offset lives on body, @page margin 0) — first overflow line may clip on printers with a top dead zone. Acceptable; fix via @page margin if ever complained.
