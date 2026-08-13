{{-- TN-FASTPAINT: async app.css + branded splash so slow connections see brand color instantly instead of a white screen. --}}
{{-- Include this INSTEAD of @vite([...css, ...js]) and add <div id="tn-boot">...</div> right after <body>. --}}
@php($tnAppCss = \Illuminate\Support\Facades\Vite::asset('resources/css/app.css'))
<link rel="preload" as="style" href="{{ $tnAppCss }}">
<link rel="stylesheet" href="{{ $tnAppCss }}" media="print" onload="this.media='all'; document.documentElement.classList.add('tn-css-ready')">
<noscript><link rel="stylesheet" href="{{ $tnAppCss }}"><style>#tn-boot{display:none !important}</style></noscript>
@vite(['resources/js/app.js'])
<style>
    #tn-boot{position:fixed;inset:0;z-index:2147483000;background:{{ $bootBg ?? '#052730' }};display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,'Segoe UI',sans-serif}
    #tn-boot span{color:#FFFFFF;font-size:30px;font-weight:700;letter-spacing:.4px}
    #tn-boot b{color:#E7BF3B;font-weight:700}
    html.tn-css-ready #tn-boot{opacity:0;visibility:hidden;pointer-events:none;transition:opacity .3s ease,visibility 0s .35s}
</style>
<script>
    (function(){var ok=function(){document.documentElement.classList.add('tn-css-ready');};window.addEventListener('load',ok);setTimeout(ok,6000);})();
</script>
