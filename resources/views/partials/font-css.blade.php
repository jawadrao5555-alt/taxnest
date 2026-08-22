{{-- ════════ WEB FONT LOADER — the one place a font stylesheet is linked ════════

     USE THIS ON EVERY NEW PAGE / LAYOUT:
         @include('partials.font-css', ['fontFamilies' => 'inter:400,500,600,700'])

     RULE 1 — never write a plain <link rel="stylesheet" href="https://fonts...">.
     A bare font link is RENDER-BLOCKING: the browser paints nothing at all until
     the font host answers, so a slow, filtered or unreachable font service shows
     the visitor a pure white screen with no text and no error (customer report,
     25 Jul 2026 — fixed in the POS panel then, carried everywhere in Task 1492).
     media="print" keeps the stylesheet off the critical path and onload flips it
     to "all" the moment it lands; <noscript> covers JS-off browsers; display=swap
     paints the text in the system fallback meanwhile and swaps in place.

     RULE 2 — ONE font provider for the whole site: fonts.bunny.net. Never add
     Google Fonts or any second host. A second provider costs 2 extra DNS+TLS
     round-trips per fresh visit (300-500 ms from Pakistan) for the same families.

     Pass only the family list; display=swap is appended here.
     (layouts/pos-app.blade.php keeps its own inline copy of this same pattern.)
     ═════════════════════════════════════════════════════════════════════════ --}}
@php($tnFontHref = 'https://fonts.bunny.net/css?family=' . $fontFamilies . '&display=swap')
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link rel="preload" as="style" href="{{ $tnFontHref }}">
<link rel="stylesheet" href="{{ $tnFontHref }}" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="{{ $tnFontHref }}"></noscript>
