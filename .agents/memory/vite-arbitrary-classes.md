---
name: Vite arbitrary Tailwind classes need a rebuild
description: New Blade components using Tailwind arbitrary-value classes render invisibly until npm run build is re-run.
---

# Vite arbitrary Tailwind classes need a rebuild

This app ships CSS via compiled Vite assets (`public/build/assets/app-*.css`), loaded
through `@vite([...])` — NOT a Tailwind CDN. Tailwind JIT only emits classes it sees
in the content globs at build time (`tailwind.config.js` scans `resources/views/**` and
`storage/framework/views/*.php`).

**Rule:** Any NEW Blade view/component that introduces Tailwind utility classes not
already used elsewhere — especially arbitrary-value classes like `bg-[#25D366]`,
`z-[60]`, `max-h-[90vh]` — will render with NO styling (invisible / unstyled) until you
run `npm run build`. The element is in the served HTML but has no CSS rules.

**Why:** I added a floating WhatsApp button; the anchor was present in HTML (grep found
`wa.me`) but it never appeared on screen. The arbitrary classes simply weren't in the
pre-compiled CSS.

**How to apply:** After adding/changing Blade markup with new Tailwind classes, run
`npm run build` (node/npm + node_modules are available in dev). `view:clear && view:cache`
alone is NOT enough — that only recompiles Blade→PHP, not the CSS. Verify with a fresh
app-preview screenshot, not just an HTML grep.
