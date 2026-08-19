{{-- Jameel Noori Nastaleeq — Urdu-script UI font (Task 1287, owner mandate:
     JNN "everywhere" for locale 'ur'). Rendered ONLY when the active locale is
     'ur' so en/rur pages make NO request for the ~5.3 MB woff2 (site perf rule:
     self-hosted, no new font CDNs). unicode-range confines the face to the
     Arabic-script blocks, so Latin text and digits keep falling through to the
     layout's own face (Inter / Figtree). Bump ?v= on ANY font-file change —
     live .htaccess caches woff2 for 30 days; sw.js also caches it
     stale-while-revalidate for the offline sale screen. --}}
@if(app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT)
    <link rel="preload" href="{{ asset('fonts/jameel-noori-nastaleeq.woff2') }}?v=1" as="font" type="font/woff2" crossorigin>
    <style>
        @font-face {
            font-family: 'Jameel Noori Nastaleeq';
            src: url('{{ asset('fonts/jameel-noori-nastaleeq.woff2') }}?v=1') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap; /* text stays readable in the system Naskh fallback while the font streams */
            unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF;
        }
        /* JNN first for Arabic-range glyphs only (unicode-range above); Latin
           skips it and resolves to whichever of Figtree/Inter the layout loads
           (DI/guest = Figtree, POS/FBR/admin = Inter), else system-ui. This *
           rule has zero specificity — any class/element font rule (.font-mono,
           .cmd-kbd, code blocks) still outranks it, exactly like the layouts'
           own existing * Inter rule. */
        *, *::before, *::after {
            font-family: 'Jameel Noori Nastaleeq', 'Figtree', 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        /* ——— Nastaleeq legibility (ur only): JNN hangs far below the Latin
           baseline and stacks ligatures high above it, so Tailwind's compact
           Latin line heights clip descenders/ascenders in nav pills, small
           buttons and table rows. Raise the floors. letter-spacing must be 0 —
           the layouts' negative Latin tracking visibly breaks Urdu joining. ——— */
        body { letter-spacing: 0; line-height: 1.8; }
        .text-xs { line-height: 1.7 !important; }
        .text-sm { line-height: 1.7 !important; }
        .text-base { line-height: 1.8 !important; }
        .leading-none, .leading-3, .leading-4 { line-height: 1.55 !important; }
        .leading-tight, .leading-5 { line-height: 1.6 !important; }
        .leading-snug, .leading-6 { line-height: 1.65 !important; }
        button, [type='button'], [type='submit'], .btn { line-height: 1.6; }
        input, select, textarea { line-height: 1.6; }
        th, td { line-height: 1.7; }
    </style>
@endif
