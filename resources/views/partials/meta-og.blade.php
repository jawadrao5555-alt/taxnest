{{-- Open Graph & Twitter Card meta tags. Include in each public page <head>.
     Required: $ogTitle, $ogDescription, $ogUrl
     Optional: $ogType (default 'website'), $ogImage (default promo-poster.jpg)
--}}
@php
    $ogType  = $ogType  ?? 'website';
    $ogImage = $ogImage ?? asset('images/promo-poster.jpg');
@endphp
{{-- Open Graph --}}
<meta property="og:title"       content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $ogDescription }}">
<meta property="og:url"         content="{{ $ogUrl }}">
<meta property="og:type"        content="{{ $ogType }}">
<meta property="og:site_name"   content="TaxNest">
<meta property="og:image"       content="{{ $ogImage }}">
<meta property="og:locale"      content="en_PK">
{{-- Twitter / X Card --}}
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="{{ $ogTitle }}">
<meta name="twitter:description" content="{{ $ogDescription }}">
<meta name="twitter:image"       content="{{ $ogImage }}">
