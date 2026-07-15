---
name: POS Screen Fit (CSS zoom scaling)
description: How the sale-screen Screen Fit feature scales the layout to small displays, and the traps in zoom-based scaling
---

# POS Screen Fit — CSS zoom scaling of the sale screens

Both universal sale screens (PRA + FBR port) have a "Screen Fit" feature: the root
`div` binds `:style="fitStyleStr"` which applies `zoom:F` plus a compensated pixel
height `round((innerHeight-48)/F)px` (48 = fixed top-nav height, which stays OUTSIDE
the zoomed subtree and must remain unscaled).

**Rules / traps:**
- **vh units are unreliable inside a zoomed subtree** (standardized CSS zoom) — that's
  why the height is a computed px value, recomputed on window resize. `F===1` clears the
  inline style entirely so the original `h-[calc(100vh-48px)]` path is byte-identical.
- **Guard against browsers without zoom support**: only apply the style when
  `CSS.supports('zoom','0.9')` — px height WITHOUT zoom makes the root taller than the
  viewport and `overflow-hidden` clips the Pay button (worse than no scaling).
- Auto formula (viewport-based): <1360w→0.9, <1150w→0.85, <1000w→0.8; short-height caps
  (<700/600/520); <768w always 1 (mobile layout takes over); ≥2300x1100→1.15. Manual
  choices clamp 0.6–1.5. Persisted per device in localStorage `tn_screen_fit`.
- **Owner-facing consequence**: auto shrinks previously-fine 1280–1366-wide shops to 90%.
  Deliberate (that width class was the original complaint); cashiers can pick 100% from
  the Fit dropdown in the action bar.
- Fixed `inset-0` modals inside the zoomed root scale uniformly and still span the
  viewport under standardized zoom — verified (Pay modal, fit dropdown) at 0.85. Receipt
  iframe printing unaffected (prints its own document). `calcGridCols` reads computed
  grid-template-columns (media queries use real viewport width — unaffected).
- The action bar is `flex-wrap` so overflowed buttons wrap instead of being clipped by
  the `overflow-hidden` root; product-search container carries `min-width:170px`.
- Keep PRA↔FBR fit code diffable: only accent-color tokens (purple↔blue) may differ.

**Testing note:** the Playwright testing subagent twice lost its notebook session right
after the provisional-save step on the zoomed sale screen (infra flake, not an app bug —
server logs showed the flow completed and the bills were created). If it recurs, verify
that segment via curl + DB instead of burning more test runs, and clean leftover
provisionals via POST `/pos/api/provisional-bills/{id}/delete` with the session cookie +
`X-CSRF-TOKEN` from the page meta.
