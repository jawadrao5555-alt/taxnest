{{--
    💊 Pharmacy — "Expire ho rahi hain" dashboard tile (Sep 2026).

    The near-expiry data always existed as a report nobody opened. This tile
    puts it where the owner lands every morning: batches expiring inside the
    shop's window (count + stock value at cost) and, in red, expired stock that
    is STILL on the shelf. Both numbers link to the batch report pre-filtered.

    Shared by all six FBR dashboard styles: the wrapper includes it BEFORE the
    style partial. $pharmacyExpiry is null for every non-pharmacy shop, for
    batch tracking off, and for cashiers — then nothing renders at all.
    Existing Tailwind classes only (no Vite rebuild on live).
--}}
@php
    $px = is_array($pharmacyExpiry ?? null) ? $pharmacyExpiry : null;
    $pxNear = (int) ($px['near_count'] ?? 0);
    $pxExpired = (int) ($px['expired_count'] ?? 0);
    $pxDays = (int) ($px['window_days'] ?? 90);
    $pxNearCost = (float) ($px['near_cost'] ?? 0);
    $pxExpiredCost = (float) ($px['expired_cost'] ?? 0);
    $pxHot = $pxExpired > 0;
    $pxWarm = !$pxHot && $pxNear > 0;
@endphp
@if($px !== null)
<div class="mb-4 rounded-xl border p-4 {{ $pxHot ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700' : ($pxWarm ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-700' : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-700') }}" data-testid="pharmacy-expiry-tile">
    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="flex-1 min-w-0">
            <h3 class="text-sm font-bold flex items-center gap-1.5 {{ $pxHot ? 'text-red-900 dark:text-red-200' : ($pxWarm ? 'text-amber-900 dark:text-amber-200' : 'text-emerald-800 dark:text-emerald-300') }}">
                <span>💊</span>
                {{ __('pos.ph_expiry_tile_title') }}
                @if($pxNear + $pxExpired > 0)
                    <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full {{ $pxHot ? 'bg-red-500' : 'bg-amber-500' }} text-white text-[11px] font-extrabold">{{ $pxNear + $pxExpired }}</span>
                @endif
                <x-new-badge feature="fbr_pharmacy_expiry_tile" />
            </h3>
            <p class="text-[11px] mt-0.5 {{ $pxHot ? 'text-red-700 dark:text-red-300' : ($pxWarm ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-400') }}">
                @if($pxHot)
                    {{ __('pos.ph_expiry_tile_expired_hint', ['count' => $pxExpired]) }}
                @elseif($pxWarm)
                    {{ __('pos.ph_expiry_tile_near_hint', ['count' => $pxNear, 'days' => $pxDays]) }}
                @else
                    {{ __('pos.ph_expiry_tile_clear', ['days' => $pxDays]) }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('fbrpos.pharmacy.reports', ['report' => 'near_expiry']) }}"
               class="flex items-center gap-2.5 px-3.5 py-2 rounded-lg border bg-white dark:bg-gray-800 {{ $pxNear > 0 ? 'border-amber-200 dark:border-amber-700 hover:bg-amber-100 dark:hover:bg-amber-900/40' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' }} transition" data-testid="pharmacy-expiry-near-link">
                <span class="text-xl font-extrabold {{ $pxNear > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ $pxNear }}</span>
                <span class="text-left">
                    <span class="block text-[11px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.ph_expiry_tile_near_label', ['days' => $pxDays]) }}</span>
                    <span class="block text-[10px] text-gray-400">{{ __('pos.ph_expiry_tile_value', ['amount' => number_format($pxNearCost)]) }}</span>
                </span>
            </a>
            <a href="{{ route('fbrpos.pharmacy.reports', ['report' => 'expired']) }}"
               class="flex items-center gap-2.5 px-3.5 py-2 rounded-lg border bg-white dark:bg-gray-800 {{ $pxExpired > 0 ? 'border-red-200 dark:border-red-700 hover:bg-red-100 dark:hover:bg-red-900/40' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' }} transition" data-testid="pharmacy-expiry-expired-link">
                <span class="text-xl font-extrabold {{ $pxExpired > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ $pxExpired }}</span>
                <span class="text-left">
                    <span class="block text-[11px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.ph_expiry_tile_expired_label') }}</span>
                    <span class="block text-[10px] text-gray-400">{{ __('pos.ph_expiry_tile_value', ['amount' => number_format($pxExpiredCost)]) }}</span>
                </span>
            </a>
        </div>
    </div>
</div>
@endif
