---
name: POS language system
description: Per-user Roman Urdu/English language system — locale resolution, catalogs, conversion phases
---
Shipped 30 Jul 2026 (foundation live). Owner wants EVERYTHING localized: POS + FBR POS + receipts.

- Locale: `SetPosLocale` middleware (web group, after StartSession) — guard chosen by URL prefix (`/fbr-pos/*`→fbrpos, `/pos/*`→pos), NEVER blind pos??fbrpos fallback (both guards can coexist in one session). Priority: users.language → companies.default_language → 'ur'. Whole body try/catch (schema-drift safety).
- 'ur' = Roman Urdu (existing wording), 'en' = pure English. Catalogs `lang/ur/pos.php` + `lang/en/pos.php` — MUST stay key-for-key in sync.
- Per-user picker: profile dropdowns in layouts/pos-app + fbr-pos-app (POST pos.set-language / fbrpos.set-language). Company default: card on /pos/customize (isPosAdmin-gated route). Cashiers CAN set personal language, CANNOT set company default.
- Sale screens fully converted; panel pages & receipts still pending. Receipts: silent-print renders server-side — locale must be set at render time from bill's company/user, not web session.
- Sale-screen JS strings: baked `<script type="application/json" id="tn-pos-i18n">` (full __('pos') via safe json_encode) + `window.TXT` parse at top of BOTH universal blades; JS/Alpine reads `window.TXT.key` (works in inline @click too).
- Conversion traps learned: (1) a quoted literal inside a Blade `{{ $x ? '..' : '..' }}` is PHP, not JS — replacing it with window.TXT 500s; (2) lang values must hold REAL chars (—, …, →), never &mdash; entities — {{ }} double-escapes them; (3) validation harness = view:cache + php -l compiled + node --check stripped JS + byte-diff the rendered ur page against a pre-change baseline (ur = old wording, so diff must be only i18n plumbing).
- Offline-first cache: users.updated_at is already in the boot fingerprint (covers personal language flips); companies.default_language added to posConfigRev whitelist so a company-default change refreshes cached sale screens.

**Why guard-by-prefix:** architect flagged dual-guard sessions picking the wrong user's language.
