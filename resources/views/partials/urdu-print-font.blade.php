{{-- Jameel Noori Nastaleeq @font-face for STANDALONE print/receipt pages
     (Task 1287). Emitted as raw CSS — include INSIDE an existing <style> block,
     inside the template's @if($urduScript) branch only, so en/rur renders never
     reference the font. URL is ABSOLUTE (url()) because the Desktop Agent loads
     receipt HTML as a data: URL in Chromium — relative /fonts/... would not
     resolve there. Browser print + agent silent print both fetch it (then HTTP-
     cache it, .htaccess 30d); offline agent renders gracefully fall back to the
     Naskh stack listed after JNN in each template. unicode-range keeps Latin
     digits/labels on the template's existing Latin faces in Chromium; mPDF
     ignores @font-face + unicode-range entirely and instead resolves the
     'Jameel Noori Nastaleeq' family via its registered fontdata key
     (see MpdfRenderer). Bump ?v= on any font-file change. --}}
@font-face {
    font-family: 'Jameel Noori Nastaleeq';
    src: url('{{ url('fonts/jameel-noori-nastaleeq.woff2') }}?v=1') format('woff2');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
    unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF;
}
