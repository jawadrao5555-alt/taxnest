@props([
    'summary' => [],
    'userId' => 0,
    'companyId' => 0,
])

{{--
    💊 Pharmacy daily expiry alert (Sep 2026).

    Shown inside <main> in fbr-pos-app.blade.php after the trial/payment/bio
    banners. The layout only passes a summary when the shop is a live pharmacy
    with batch tracking, the viewer is owner/manager, the company is not
    pending, impersonation is not read-only, and there is at least one batch
    to talk about — so this file never gates, it only renders.

    Dismissal is per USER per DAY in localStorage (same device-side pattern as
    the subscription-expiry popup): no table, no cron, and tomorrow it comes
    back on its own if the shelf is still the same.
--}}
@php
    $near = (int) ($summary['near_count'] ?? 0);
    $expired = (int) ($summary['expired_count'] ?? 0);
    $days = (int) ($summary['window_days'] ?? 90);
    $storeKey = 'tn_ph_expiry_seen_' . (int) $companyId . '_' . (int) $userId;
    $today = now()->toDateString();
@endphp
<div x-data="{ open: false, key: @js($storeKey), today: @js($today),
        init() { try { this.open = localStorage.getItem(this.key) !== this.today; } catch (e) { this.open = true; } },
        dismiss() { this.open = false; try { localStorage.setItem(this.key, this.today); } catch (e) {} } }"
     x-show="open" x-cloak
     class="{{ $expired > 0 ? 'bg-red-50 dark:bg-red-900/30 border-b border-red-200 dark:border-red-700' : 'bg-amber-50 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-700' }}"
     data-testid="pharmacy-expiry-banner">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
        <div class="flex items-center gap-2 min-w-0 flex-shrink-0">
            <span class="text-base leading-none" aria-hidden="true">💊</span>
            <span class="text-[12px] sm:text-[13px] font-semibold {{ $expired > 0 ? 'text-red-800 dark:text-red-200' : 'text-amber-800 dark:text-amber-200' }}">
                @if($near > 0)
                    {{ __('pos.ph_expiry_alert_near', ['count' => $near, 'days' => $days]) }}
                @else
                    {{ __('pos.ph_expiry_alert_title') }}
                @endif
            </span>
        </div>
        @if($expired > 0)
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-600 text-white text-[11px] font-extrabold">
            {{ __('pos.ph_expiry_alert_expired', ['count' => $expired]) }}
        </span>
        @endif
        <div class="flex items-center gap-2 ml-auto">
            @if($expired > 0)
            <a href="{{ route('fbrpos.pharmacy.reports', ['report' => 'expired']) }}"
               class="text-[11px] font-bold px-2.5 py-1 rounded-lg bg-red-600 hover:bg-red-700 text-white transition">
                {{ __('pos.ph_expiry_alert_view_expired') }}
            </a>
            @endif
            @if($near > 0)
            <a href="{{ route('fbrpos.pharmacy.reports', ['report' => 'near_expiry']) }}"
               class="text-[11px] font-bold px-2.5 py-1 rounded-lg {{ $expired > 0 ? 'bg-white dark:bg-gray-800 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-700 hover:bg-red-100' : 'bg-amber-600 hover:bg-amber-700 text-white' }} transition">
                {{ __('pos.ph_expiry_alert_view_near') }}
            </a>
            @endif
            <button type="button" @click="dismiss()"
                    class="p-1 rounded-md {{ $expired > 0 ? 'text-red-500 hover:bg-red-100 dark:hover:bg-red-900/40' : 'text-amber-600 hover:bg-amber-100 dark:hover:bg-amber-900/40' }} transition"
                    title="{{ __('pos.ph_expiry_alert_dismiss') }}" aria-label="{{ __('pos.ph_expiry_alert_dismiss') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
</div>
