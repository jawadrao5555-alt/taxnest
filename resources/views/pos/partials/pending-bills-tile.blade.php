{{--
    Task 109 (ZFC, 2 Aug 2026): Pending Bills tile — "dashboard se pata lag
    jayega bhai dekh lo, bhool nahi hoga." Shows bills that are not FINAL yet:
    (a) provisional delivery bills (triple-filter, current business day) and
    (b) open dine-in orders (held/preparing/ready) for restaurant companies.

    Shared across ALL 7 dashboard styles: included by the two dashboard wrapper
    blades BEFORE the style include, so no per-style edits are needed.
    Provisional link goes to the Local Bills portal — PosAuth confines that to
    isPosAdmin(), so the whole tile is admin/manager-only ($isAdmin guard here).
    Only existing Tailwind classes are used (no Vite rebuild required).
--}}
@php
    $pbProv = (int) ($pendingProvisional ?? 0);
    $pbOpen = (int) ($openOrdersCount ?? 0);
    $pbIsRestaurant = (bool) ($isRestaurant ?? false);
    $pbTotal = $pbProv + ($pbIsRestaurant ? $pbOpen : 0);
    // Non-restaurant PRA dashboard: show only when something is actually pending
    // (most retail shops never use provisional bills — avoid permanent clutter).
    $pbShow = ($isAdmin ?? false) && ($pbIsRestaurant || $pbProv > 0);
@endphp
@if($pbShow)
<div class="mb-4 rounded-xl border p-4 {{ $pbTotal > 0 ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-700' : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-700' }}">
    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="flex-1 min-w-0">
            <h3 class="text-sm font-bold {{ $pbTotal > 0 ? 'text-amber-900 dark:text-amber-200' : 'text-emerald-800 dark:text-emerald-300' }}">
                {{ __('pos.pending_bills_title') }}
                @if($pbTotal > 0)
                    <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-amber-500 text-white text-[11px] font-extrabold">{{ $pbTotal }}</span>
                @endif
            </h3>
            <p class="text-[11px] mt-0.5 {{ $pbTotal > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-400' }}">
                {{ $pbTotal > 0 ? __('pos.pending_bills_hint') : __('pos.pending_all_clear') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('pos.local.index') }}"
               class="flex items-center gap-2.5 px-3.5 py-2 rounded-lg border bg-white dark:bg-gray-800 {{ $pbProv > 0 ? 'border-amber-200 dark:border-amber-700 hover:bg-amber-100 dark:hover:bg-amber-900/40' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' }} transition">
                <span class="text-xl font-extrabold {{ $pbProv > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ $pbProv }}</span>
                <span class="text-left">
                    <span class="block text-[11px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.pending_provisional_bills') }}</span>
                    <span class="block text-[10px] text-gray-400">{{ __('pos.pending_provisional_sub') }}</span>
                </span>
            </a>
            @if($pbIsRestaurant)
            <a href="{{ route('pos.restaurant.tables') }}"
               class="flex items-center gap-2.5 px-3.5 py-2 rounded-lg border bg-white dark:bg-gray-800 {{ $pbOpen > 0 ? 'border-amber-200 dark:border-amber-700 hover:bg-amber-100 dark:hover:bg-amber-900/40' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' }} transition">
                <span class="text-xl font-extrabold {{ $pbOpen > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ $pbOpen }}</span>
                <span class="text-left">
                    <span class="block text-[11px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.pending_open_tables') }}</span>
                    <span class="block text-[10px] text-gray-400">{{ __('pos.pending_open_tables_sub') }}</span>
                </span>
            </a>
            {{-- Task 113: Cancelled Orders count (current business day) → report page.
                 Informational only — NOT part of the pending total (cancelled ≠ pending). --}}
            @php $pbCancelled = (int) ($cancelledTodayCount ?? 0); @endphp
            <a href="{{ route('pos.restaurant.cancelled-orders') }}"
               class="flex items-center gap-2.5 px-3.5 py-2 rounded-lg border bg-white dark:bg-gray-800 {{ $pbCancelled > 0 ? 'border-red-200 dark:border-red-700 hover:bg-red-50 dark:hover:bg-red-900/30' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' }} transition">
                <span class="text-xl font-extrabold {{ $pbCancelled > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ $pbCancelled }}</span>
                <span class="text-left">
                    <span class="block text-[11px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.cancelled_orders_tile') }}</span>
                    <span class="block text-[10px] text-gray-400">{{ __('pos.cancelled_orders_tile_sub') }}</span>
                </span>
            </a>
            @endif
        </div>
    </div>
</div>
@endif
