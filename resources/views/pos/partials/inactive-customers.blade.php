{{--
    Task 1161: "Purane Customer Khamosh Hain" — repeat-customer inactivity alert.
    Repeat customers (PosRepeatCustomerAlert::MIN_ORDERS+ completed orders)
    whose LAST order is older than the inactivity window: naam, phone
    (tap-to-call), total orders, "aakhri order X din pehle" + history link.

    Shared by BOTH dashboard variants (retail pos/dashboard + restaurant
    dashboard) — included before the style include like the pending-bills tile,
    so no per-style edits are needed. Only existing Tailwind classes (no Vite
    rebuild required).

    Visibility: informational, dismiss-free. Controller passes rows only for
    admin/manager ($isAdmin). Empty list hides the card entirely. Pending
    companies + view-only impersonation are skipped per the standard POS
    notification-surface convention (What's New rules) — and confined roles
    never reach the dashboards at all.
--}}
@php
    $icList = collect($inactiveRegulars ?? []);
    $icImp = session('impersonation');
    $icReadonlyImp = is_array($icImp) && !empty($icImp['readonly']);
    $icPending = (($company->status ?? null) === 'pending') || (($company->company_status ?? null) === 'pending');
    $icShow = ($isAdmin ?? false) && !$icPending && !$icReadonlyImp && $icList->isNotEmpty();
    $icRows = $icList->take(\App\Services\PosRepeatCustomerAlert::CARD_LIMIT);
    $icMore = max(0, $icList->count() - $icRows->count());
@endphp
@if($icShow)
<div id="inactive-regulars" class="mb-4 rounded-2xl bg-white dark:bg-gray-900 border border-sky-200 dark:border-sky-800 p-4 shadow-sm">
    <div class="flex items-start gap-3 mb-3">
        <div class="w-9 h-9 rounded-xl bg-sky-600 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">
                {{ __('pos.inactive_regulars_title') }}
                <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-sky-500 text-white text-[11px] font-extrabold">{{ $icList->count() }}</span>
            </h3>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                {{ __('pos.inactive_regulars_hint', ['min' => \App\Services\PosRepeatCustomerAlert::MIN_ORDERS, 'days' => \App\Services\PosRepeatCustomerAlert::INACTIVE_DAYS]) }}
            </p>
        </div>
    </div>
    <div class="divide-y divide-gray-100 dark:divide-gray-800">
        @foreach($icRows as $ic)
        <div class="py-2 flex items-center gap-3">
            <div class="flex-1 min-w-0">
                {{-- hover styles limited to classes already in the built CSS (no Vite rebuild) --}}
                <a href="{{ route('pos.customers.history', $ic['id']) }}" class="text-sm font-bold text-gray-900 dark:text-white hover:underline transition truncate block">{{ $ic['name'] }}</a>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                    <span class="font-semibold text-sky-700 dark:text-sky-400">{{ __('pos.inactive_orders_count', ['count' => $ic['orders']]) }}</span>
                    · {{ __('pos.inactive_last_order_days', ['days' => $ic['days']]) }}
                </p>
            </div>
            @if(!empty($ic['phone']))
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $ic['phone']) }}"
               class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 text-xs font-bold hover:bg-sky-100 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                <span dir="ltr">{{ $ic['phone'] }}</span>
            </a>
            @endif
        </div>
        @endforeach
    </div>
    @if($icMore > 0)
    <p class="mt-2 text-[11px] text-gray-400">
        <a href="{{ route('pos.customers') }}" class="font-semibold text-sky-700 dark:text-sky-400 hover:underline">{{ __('pos.inactive_more_note', ['count' => $icMore]) }}</a>
    </p>
    @endif
</div>
@endif
