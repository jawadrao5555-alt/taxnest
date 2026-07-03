---
name: Approved landing/brand color formula
description: Owner-approved "non-AI-look" recolor formula for public landing pages — teal primary, gold micro-highlights, flat confined product colors.
---

Owner approved this formula (July 2026) to make public pages NOT look "AI-generated". Apply it to any future public/marketing page work.

**The rule:**
- Primary brand: deep teal `#0A4D5C` / mid `#0F6171`, hover `#063B47`. Replaces ALL emerald on marketing surfaces.
- Gold `#E7BF3B` ONLY as tiny highlights (one word in a headline, a hairline on a dark banner, one badge, one table-header accent). Never large areas.
- Product identity colors stay FLAT and CONFINED to their own card/button: PRA POS = purple-700, FBR POS = blue-700, DI = the teal primary. No 500-shade gradients.
- NO gradients on cards, buttons, badges, or icon boxes. Hero background orbs/washes and teal-on-teal monochrome hero CTAs are exempt (owner-approved).
- All non-product icon boxes uniform teal tint: `bg-[#0F6171]/10` + `text-[#0A4D5C]`.
- Dark sections: flat `#07333E` (not gray-900 gradients); checkmarks inside dark sections = `#2EA0B3`.
- Remove absolute inset-0 gradient overlay divs on cards — owner explicitly hates corner washes (also in replit.md).

**Why:** Owner said rainbow emerald/purple/blue gradient mix looked "AI-based"; approved this analysis with "jo tum behter smjhty ho bana do".

**How to apply:** Any new/edited public landing, product page, or marketing section. Contrast verified WCAG AA (#0A4D5C on white ≈9.4:1, #E7BF3B on #07333E ≈8.3:1). Arbitrary Tailwind classes require `npm run build` (see vite-arbitrary-classes.md).
