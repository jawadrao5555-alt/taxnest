---
name: POS theme gradient white-fade
description: Why from-purple-X + unmapped 2nd gradient stop fades to transparent/white under non-purple POS themes, and the fix pattern.
---

# POS theme engine gradient white-fade trap

**Rule:** In any view rendered under the POS theme engine (`pos-app.blade.php`, `body[data-theme]`), NEVER pair `from-purple-X` with a second gradient stop outside the remapped set. Remapped stops only: `from-purple-50/100/500/600`, `to-purple-500/600/700`, `via-purple-500/600`. Anything else (`to-violet-*`, `to-indigo-*`, `to-fuchsia-*`, `to-pink-*`, `to-purple-50`, `via-fuchsia-*`) breaks.

**Why:** The theme override remaps `.from-purple-X` with `!important` and, mimicking Tailwind's implicit behavior, also forces `--tw-gradient-to: hsl(accent / 0) !important` (transparent). The unmapped second-stop utility has no `!important`, so it loses the cascade → the element's gradient ends at TRANSPARENT → the white page background shows through = "white shade / side se blur" the owner complains about. Under the default purple theme nothing looks wrong, which is why these ship unnoticed.

**How to apply:**
- Buttons / pills / banners → solid `bg-purple-600 hover:bg-purple-700` (owner rule: clean & solid anyway). Strip colored glows to `shadow-sm`.
- Icon boxes / rank badges / tiles (gradient exempt) → keep gradient but use both stops from the remapped set, e.g. `from-purple-500 to-purple-700`.
- Light washes/chips → solid `bg-purple-50` / `bg-purple-100` (+ dark variants); `to-purple-50` is NOT remapped.
- Reverse case `from-violet/indigo-*` + `to-purple-X`: no white fade (from keeps its own transparent-to, then to-purple remaps) but goes muddy/unthemed → swap the from to a remapped purple too.
- Detection sweep: `rg "from-purple-[0-9]+[^\"]*(to|via)-(violet|indigo|fuchsia|pink|blue)-"` over `resources/views/pos` + pos-app layout. Guest pages (login/register/landing) have no theme engine — exempt.
