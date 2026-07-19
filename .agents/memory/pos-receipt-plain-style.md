---
name: POS thermal receipt plain style
description: Customer-approved receipt typography + logo placement rules for receipt_80mm/receipt_58mm — what must stay plain and where bold is allowed.
---

# Thermal receipt plain style (customer request, Jul 2026)

Rule: both PRA POS receipt templates (`receipt_80mm` / `receipt_58mm`) use PLAIN drafting —
NO `font-style:italic` anywhere, `font-weight: normal` as the default. Bold is allowed ONLY on:
business name (h1), invoice/fiscal numbers (`.inv-value`, `.pra-number`), grand total row,
PRA badge title, and PROVISIONAL BILL label. Sizes/margins are deliberately trimmed
(80mm body 11px, tighter separators) so the slip prints shorter.

Logo sits to the RIGHT of the business name in a 2-cell TABLE row (never flex — DomPDF),
only when `$logoDataUri` exists; no-logo fallback = centered h1. 80mm logo max 80x42px,
58mm max 60x32px.

**Why:** a live customer's handwritten feedback — italic/heavy-bold fonts looked messy and
the stacked logo made the slip long. Owner approved this exact spec.

**How to apply:** any future receipt edit must not re-add decorative bold/italic weights or
move the logo back above the name. Both templates + the `$pdfMode` DomPDF path share the
same markup — test both after edits.

Related: iframe print focus — after the hidden print iframe's dialog closes, focus can stay
inside the iframe and the parent document's keydown shortcuts (P reprint / Enter / Esc) go
dead. `_printViaIframe`'s fireOnce must blur the iframe + `window.focus()` — keep that
recovery in any new hidden-iframe print path.
