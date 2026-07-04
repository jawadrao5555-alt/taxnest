---
name: POS buttons — no colored glow shadow
description: Owner reads soft colored drop-shadow glows on POS buttons/pills as "white blur on the sides"; buttons must be clean & solid.
---

**Rule:** POS action buttons and pills must be clean & solid — a plain neutral shadow (`shadow-sm` / `box-shadow: 0 1px 2px rgba(0,0,0,.08)`) or none. Never a colored glow. This is the button-level counterpart of the replit.md card/banner "clean & solid, no corner-glow" rule.

Two forms of the glow to remove:
- Tailwind utility: `shadow-md/lg/xl shadow-<color>-N/NN` (e.g. `shadow-md shadow-purple-600/20`).
- CSS `<style>` block: `box-shadow: ... rgba(<color>)` (e.g. `.pay-btn-premium` green glow, `.cat-pill.active` purple glow).

Also avoid muddy 2-hue gradients on buttons under themed POS: only purple-X is remapped by the theme engine, so `from-purple-600 to-indigo-600` becomes rose→indigo under the `rose` theme. Prefer solid `bg-<color>-600 hover:bg-<color>-700`.

**Why:** Owner said the whole POS ("poray POS mein") has this; the halo reads as the button edge fading to white / blurring. The most prominent PAY button and the category pills carried it via CSS `box-shadow`, NOT Tailwind — so grep the `<style>` block too, not just class attributes.

**How to apply:** Strip only the color token so Alpine `:class` selected-states (ring-2 / scale-105 / border) and keyboard focus rings stay intact. Find them with `rg "shadow-(purple|green|amber|orange|sky|emerald|violet|red|blue)-[0-9]+/[0-9]+"` for utilities and `rg "box-shadow[^;]*rgba"` for CSS. Icon-box gradients and hover accent underline bars stay exempt (same carve-outs as the card rule).
