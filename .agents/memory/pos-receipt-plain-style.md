---
name: POS thermal receipt plain style
description: Customer-approved receipt typography + logo placement rules for receipt_80mm/receipt_58mm — what must stay plain and where bold is allowed.
---

# Thermal receipt plain style (customer request, Jul 2026) — SUPERSEDED in part

**SUPERSEDED (21 Jul 2026):** whole-receipt BOLD + large CENTERED logo are now the UNIVERSAL
DEFAULT (owner: "universal kr do") via `Company::posReceiptStyle()` — see
`pos-provisional-and-receipt-rules.md` (Receipt PRINT STYLE section) for the current rule.
The plain look below is still selectable per company (bold OFF + logo 'side' on
/pos/receipt-settings) and its BASE typography rules still apply.

Still-valid base rules: NO `font-style:italic` anywhere, no decorative extra weights beyond
the bold-style override. In the non-bold (plain) style, bold appears ONLY on: business name
(h1), invoice/fiscal numbers (`.inv-value`, `.pra-number`), grand total row, PRA badge title,
and PROVISIONAL BILL label. Sizes/margins deliberately trimmed (80mm body 11px, tighter
separators) so the slip prints shorter.

Logo in 'side' style sits to the RIGHT of the business name in a 2-cell TABLE row (never
flex — DomPDF); 'center' style = large centered logo above the name (42mm/80mm, 30mm/58mm).
Either way only when `$logoDataUri` exists; no-logo fallback = plain h1.

**Why:** one live customer's handwritten feedback wanted the plain drafting look; a pizza
shop wanted bold+big logo. Owner first made it opt-in, then flipped the default to bold ON
for everyone — the plain-look customer can opt out.

**How to apply:** never re-add italic; keep the plain style intact as the opt-out; both
templates + the `$pdfMode` DomPDF path share the same markup — test both after edits.

**Paper-saving trims (owner + Pizza Master photo, 22 Jul 2026):** center-logo img is
`display:block` inside a `line-height:0; margin:0` wrapper (kills the inline-descender gap under
the logo — don't revert to inline img); separators 3px margins, header mb 3px, footer mt 4px;
badge/box letter-spacing max 1px (2px+ read as "phaila hua" waste). Serial badge sits at TOP,
PAYMENT box is delivery-only — see pos-provisional-and-receipt-rules.md for the branch rules.

**Font family (owner, 21 Jul 2026 "laser pe clear print ho"):** receipt font = Arial /
Helvetica sans stack, NOT 'Courier New' — Courier's hairline strokes print faint/thin on
laser printers (some shops print receipts on laser, not thermal). Don't revert to a
monospace body font; DomPDF maps Arial → Helvetica core font (same Unicode limits as the
old Courier mapping, no regression).

Related: iframe print focus — after the hidden print iframe's dialog closes, focus can stay
inside the iframe and the parent document's keydown shortcuts (P reprint / Enter / Esc) go
dead. `_printViaIframe`'s fireOnce must blur the iframe + `window.focus()` — keep that
recovery in any new hidden-iframe print path.
