@php
    $backCompany = auth('pos')->user()?->company;
    $hotelReportBack = request()->routeIs('pos.reports', 'pos.tax-reports', 'pos.hotel.folios')
        && \App\Services\HotelShell::isNativeCategory($backCompany)
        && \App\Services\HotelAccessService::canFrontDesk(auth('pos')->user());
@endphp
@if($hotelReportBack)
<a href="{{ route('pos.hotel.dashboard') }}" data-hotel-report-back="1" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 mb-3">← {{ __('pos.back_word') }}</a>
@else
{{-- Universal "Wapas" back link (customer suggestion, 21 Jul 2026 — ZFC Pizza Point:
     "suggestion page open kiya lekin back janay ka option ni h").
     history.back() with a dashboard fallback (direct link / new tab / PWA cold-open).
     Include at the very top of any POS page that has no back affordance of its own. --}}
<button type="button"
    onclick="if (window.history.length > 1 && document.referrer) { window.history.back(); } else { window.location.href = '{{ route('pos.dashboard') }}'; }"
    class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    {{ __('pos.back_word') }}
</button>

@endif
