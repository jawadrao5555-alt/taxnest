---
name: Vite arbitrary Tailwind classes need a rebuild
description: New Blade components using Tailwind arbitrary-value classes render invisibly until npm run build is re-run.
---

# Vite arbitrary Tailwind classes need a rebuild

This app ships CSS via compiled Vite assets (`public/build/assets/app-*.css`), loaded
through `@vite([...])` — NOT a Tailwind CDN. Tailwind JIT only emits classes it sees
in the content globs at build time (`tailwind.config.js` scans `resources/views/**` and
`storage/framework/views/*.php`).

**Rule:** Any NEW Tailwind utility class not already present elsewhere in the scanned
content — this includes plain standard utilities (`font-black`, `tracking-widest`,
`shadow-emerald-600/40`, `ring-offset-2`) the JIT hasn't emitted yet, AND arbitrary-value
classes like `bg-[#25D366]`, `z-[60]`, `max-h-[90vh]` — renders with NO styling
(invisible / unstyled) until you run `npm run build`. The element is in the served HTML
but has no CSS rules. It is NOT only about arbitrary values.

**cPanel deploy implication:** `public/build/` is git-tracked. The standard deploy
one-liner does `git pull` + artisan caches — it does NOT run `npm run build` on the
server. So after editing Blade with new classes you MUST `npm run build` locally and
get the regenerated `public/build/assets/*.css` + `manifest.json` committed and pushed
to `origin/main`, or production serves stale CSS and the new classes are invisible live.

**Why:** I added a floating WhatsApp button; the anchor was present in HTML (grep found
`wa.me`) but it never appeared on screen. The arbitrary classes simply weren't in the
pre-compiled CSS.

**How to apply:** After adding/changing Blade markup with new Tailwind classes, run
`npm run build` (node/npm + node_modules are available in dev). `view:clear && view:cache`
alone is NOT enough — that only recompiles Blade→PHP, not the CSS. Verify with a fresh
app-preview screenshot, not just an HTML grep.

**Related trap — undefined custom colors:** classes like `text-saffron` / `bg-saffron/20`
silently emit NO CSS even after a rebuild if the color isn't in `tailwind.config.js`
theme colors (a `--saffron` CSS variable in `:root` does NOT make Tailwind utilities
work). No build error, no warning — elements just render unstyled (e.g. black star
icons, invisible blobs). After any design-subagent rewrite, grep the BUILT css
(`grep -c <token> public/build/assets/app-*.css`) for novel color names; replace with
standard palette utilities (amber-400 etc.) or extend the config, then rebuild.
