---
name: POS language system
description: Per-user Roman Urdu/English language system — locale resolution, catalogs, conversion phases
---
Shipped 30 Jul 2026 (foundation live). Owner wants EVERYTHING localized: POS + FBR POS + receipts.

- Locale: `SetPosLocale` middleware (web group, after StartSession) — guard chosen by URL prefix (`/fbr-pos/*`→fbrpos, `/pos/*`→pos), NEVER blind pos??fbrpos fallback (both guards can coexist in one session). Priority: users.language → companies.default_language → 'ur'. Whole body try/catch (schema-drift safety).
- 'ur' = Roman Urdu (existing wording), 'en' = pure English. Catalogs `lang/ur/pos.php` + `lang/en/pos.php` — MUST stay key-for-key in sync.
- Per-user picker: profile dropdowns in layouts/pos-app + fbr-pos-app (POST pos.set-language / fbrpos.set-language). Company default: card on /pos/customize (isPosAdmin-gated route). Cashiers CAN set personal language, CANNOT set company default.
- String conversion pending — follow-up tasks: sale screens (fragile files!), panel pages, receipts. Receipts: silent-print renders server-side — locale must be set at render time from bill's company/user, not web session.
- Sale-screen JS strings need a baked @json translations object (Alpine is client-side).

**Why guard-by-prefix:** architect flagged dual-guard sessions picking the wrong user's language.
