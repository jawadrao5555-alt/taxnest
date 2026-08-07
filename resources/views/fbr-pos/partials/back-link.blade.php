{{-- Universal "Wapas" back link for FBR POS pages (owner request, Aug 2026 —
     mirrors resources/views/pos/partials/back-link.blade.php from PRA POS).
     history.back() with a dashboard fallback (direct link / new tab / PWA cold-open).
     Include at the very top of any FBR POS page that has no back affordance of its own. --}}
<button type="button"
    onclick="if (window.history.length > 1 && document.referrer) { window.history.back(); } else { window.location.href = '{{ route('fbrpos.dashboard') }}'; }"
    class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition mb-3">
    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    {{ __('pos.back_word') }}
</button>
