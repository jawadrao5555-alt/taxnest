<x-fbr-pos-layout>
{{-- FBR POS Deliveries board (Aug 2026) — port of pos/deliveries.blade.php.
     Open to admin + cashier. Rider CRUD at /fbr-pos/riders (admin-only). --}}
{{-- Embedded mode: sale screen opens this board in a modal IFRAME.
     Frame detection hides the app top-nav and back button. --}}
<script>if (window.self !== window.top) { document.documentElement.classList.add('tn-embedded'); }</script>
<style>
    .tn-embedded .topnav-bar { display: none !important; }
    .tn-embedded .tn-embed-hide { display: none !important; }
</style>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{ settleRider: null, settleTotal: 0, settleAmount: '', recalcSettle(form) {
         let t = 0;
         form.querySelectorAll('input[name=\'bill_ids[]\']:checked').forEach(cb => t += parseFloat(cb.dataset.amount || 0));
         this.settleTotal = t;
         this.settleAmount = t > 0 ? t : '';
     } }">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
        <div class="flex items-center gap-3">
            <button type="button"
                    onclick="if (history.length > 1) { history.back(); } else { window.location = '{{ route('fbrpos.dashboard') }}'; }"
                    class="tn-embed-hide inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition"
                    title="{{ __('pos.ti_go_back') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_word') }}
            </button>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.deliveries') }}</h1>
        </div>
        <form method="GET" action="{{ route('fbrpos.deliveries') }}" class="flex items-center gap-2">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <input type="date" name="date" value="{{ $day->format('Y-m-d') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
            <button type="submit" class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold shadow-sm hover:bg-blue-700 transition">{{ __('pos.go_btn') }}</button>
        </form>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.deliveries_page_intro') }}</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">
        <ul class="list-disc pl-4">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    {{-- Rider khata cards --}}
    @if($riders->count())
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach($riders as $rider)
        @php
            $open = $khataBills[$rider->id] ?? collect();
            // Khata remaining — partial receipts (Task 525) already handed over are deducted.
            $owed = (float) $open->sum(fn ($b) => (float) $b->total_amount - (float) ($b->rider_partial_paid ?? 0));
        @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-4">
            @php $openDel = (int) ($openDeliveryCounts[$rider->id] ?? 0); @endphp
            <div class="flex items-center justify-between gap-2 mb-1">
                <div class="font-bold text-gray-900 dark:text-white text-sm truncate">
                    {{ $rider->name }}
                    @unless($rider->is_active)<span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 align-middle">{{ __('pos.inactive_word') }}</span>@endunless
                </div>
                @php $oldestDays = (int) ($openDeliveryOldest[$rider->id] ?? 0); @endphp
                @if($openDel > 0)
                <span class="flex-shrink-0 px-2 py-0.5 rounded-full text-[11px] font-extrabold {{ $oldestDays >= 1 ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' }}">{{ __('pos.rider_out_pill', ['count' => $openDel]) }}</span>
                @endif
            </div>
            @if($openDel > 0 && $oldestDays >= 1)
            <div class="text-[11px] font-bold text-red-600 dark:text-red-400 mb-1">{{ __('pos.del_oldest_days', ['days' => $oldestDays]) }}</div>
            @endif
            @if($rider->phone)<div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">{{ $rider->phone }}</div>@endif
            @if($owed > 0)
                <div class="text-lg font-bold text-amber-600 dark:text-amber-400">Rs. {{ number_format($owed) }}</div>
                <div class="text-[11px] text-gray-400 mb-3">{{ __('pos.unsettled_cash_bills', ['count' => $open->count()]) }}</div>
                <button type="button" class="w-full px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold shadow-sm hover:bg-blue-700 transition"
                        @click="settleRider = {{ $rider->id }}; settleTotal = {{ $owed }}; settleAmount = {{ $owed }}">{{ __('pos.settle_cash') }}</button>
            @else
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ __('pos.clear') }}</div>
                <div class="text-[11px] text-gray-400">{{ __('pos.no_cash_pending') }}</div>
            @endif
            @if($openDel > 0)
            <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                <div class="text-[11px] text-gray-400 mb-1.5">{{ __('pos.orders_out_for_delivery', ['count' => $openDel]) }}</div>
                <div class="flex gap-1.5">
                    <form method="POST" action="{{ route('fbrpos.deliveries.bulk', $rider->id) }}" class="flex-1"
                          onsubmit="return confirm({{ Js::from(__('pos.confirm_all_delivered', ['count' => $openDel])) }});">
                        @csrf
                        <input type="hidden" name="delivery_status" value="delivered">
                        <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition">{{ __('pos.all_delivered') }}</button>
                    </form>
                    <form method="POST" action="{{ route('fbrpos.deliveries.bulk', $rider->id) }}" class="flex-1"
                          onsubmit="return confirm({{ Js::from(__('pos.confirm_all_returned', ['count' => $openDel])) }});">
                        @csrf
                        <input type="hidden" name="delivery_status" value="returned">
                        <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-red-600 text-white text-[11px] font-semibold hover:bg-red-700 transition">{{ __('pos.all_returned') }}</button>
                    </form>
                </div>
            </div>
            @endif
        </div>

        {{-- Settle modal --}}
        @if($owed > 0)
        <template x-teleport="body">
            <div x-show="settleRider === {{ $rider->id }}" x-cloak @keydown.escape.window="if (settleRider === {{ $rider->id }}) settleRider = null" @keydown.escape.prevent.stop="settleRider = null" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="settleRider = null"></div>
                <div class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-lg p-5 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.settle_cash') }} — {{ $rider->name }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.settle_cash_hint') }}</p>
                    <form method="POST" action="{{ route('fbrpos.riders.settle', $rider->id) }}" x-init="recalcSettle($el)" @change="if ($event.target.name === 'bill_ids[]') recalcSettle($event.target.closest('form'))">
                        @csrf
                        <div class="space-y-1.5 mb-4">
                            @foreach($open as $b)
                            @php $rem = (float) $b->total_amount - (float) ($b->rider_partial_paid ?? 0); @endphp
                            <label class="flex items-center gap-3 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/60 cursor-pointer">
                                <input type="checkbox" name="bill_ids[]" value="{{ $b->id }}" data-amount="{{ $rem }}" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="flex-1 min-w-0">
                                    <span class="flex items-center gap-1.5 min-w-0">
                                        <span class="block text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                                        <span class="inline-flex flex-shrink-0 px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">FBR</span>
                                    </span>
                                    <span class="block text-[11px] text-gray-400">{{ $b->created_at->format('d/m h:i A') }}@if($b->customer_name) · {{ $b->customer_name }}@endif</span>
                                    @if((float) ($b->rider_partial_paid ?? 0) > 0)
                                    <span class="block text-[11px] font-semibold text-amber-600 dark:text-amber-400">{{ __('pos.partial_paid_note', ['paid' => number_format((float) $b->rider_partial_paid)]) }}</span>
                                    @endif
                                </span>
                                <span class="text-xs font-bold text-gray-900 dark:text-white">Rs. {{ number_format($rem) }}</span>
                            </label>
                            @endforeach
                        </div>
                        {{-- Partial receive (Task 525): "aadha cash abhi, baqi baad" — cashier
                             enters what actually came; the rest stays on the rider's khata. --}}
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.cash_received_now') }}</label>
                            <input type="number" name="received_amount" x-model="settleAmount" min="1" step="0.01" inputmode="decimal"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm font-bold focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.settle_partial_hint') }}</p>
                            <p class="text-[11px] font-semibold text-red-600 dark:text-red-400 mt-1" x-show="parseFloat(settleAmount || 0) > settleTotal + 0.009" x-cloak>{{ __('pos.settle_amount_over_live') }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.notes_optional') }}</label>
                            <input type="text" name="notes" maxlength="500" placeholder="{{ __('pos.ph_eg_evening_handover') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="flex items-center justify-between mt-5">
                            <div class="text-xs text-gray-600 dark:text-gray-300">
                                <div>{{ __('pos.selected_total_colon') }} <b class="text-gray-900 dark:text-white">Rs. <span x-text="settleTotal.toLocaleString()"></span></b></div>
                                <div class="font-bold" :class="(settleTotal - (parseFloat(settleAmount) || 0)) > 0.009 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">{{ __('pos.baqaya_colon') }} Rs. <span x-text="Math.max(0, settleTotal - (parseFloat(settleAmount) || 0)).toLocaleString()"></span></div>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="settleRider = null">{{ __('pos.cancel') }}</button>
                                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                                        :disabled="!(parseFloat(settleAmount) > 0) || parseFloat(settleAmount) > settleTotal + 0.009">{{ __('pos.confirm_settlement') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </template>
        @endif
        @endforeach
    </div>
    @else
    <div class="mb-6 p-4 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400">
        {{ __('pos.no_active_riders_yet') }} <a href="{{ route('fbrpos.riders') }}" class="font-semibold text-blue-600 dark:text-blue-400 underline">{{ __('pos.add_riders_link') }}</a> {{ __('pos.to_start_assigning') }}
    </div>
    @endif

    {{-- Per-rider day summary --}}
    @if($riderDaySummary->count())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden mb-4">
        <div class="px-4 py-2.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.rider_day_summary_title') }} — {{ $day->format('d M Y') }}</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-2">{{ __('pos.rider_label') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('pos.pending_word') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('pos.delivered_word') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('pos.returned_word') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('pos.rider_day_summary_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($riderDaySummary as $rs)
                    <tr>
                        <td class="px-4 py-2 font-semibold text-gray-900 dark:text-white">{{ $rs['name'] }}</td>
                        <td class="px-4 py-2 text-center">
                            @if($rs['pending'] > 0)<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-bold bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">{{ $rs['pending'] }}</span>
                            @else<span class="text-gray-300 dark:text-gray-600">—</span>@endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if($rs['delivered'] > 0)<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400">{{ $rs['delivered'] }}</span>
                            @else<span class="text-gray-300 dark:text-gray-600">—</span>@endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if($rs['returned'] > 0)<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-bold bg-red-100 dark:bg-red-900/20 text-red-700 dark:text-red-400">{{ $rs['returned'] }}</span>
                            @else<span class="text-gray-300 dark:text-gray-600">—</span>@endif
                        </td>
                        <td class="px-4 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">{{ $rs['pending'] + $rs['delivered'] + $rs['returned'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Bills table with status tabs --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">

        <div class="border-b border-gray-100 dark:border-gray-800 flex items-center gap-0 px-4 pt-3 overflow-x-auto">
            @php
                $tabs = [
                    'pending'   => ['label' => __('pos.pending_word'),   'sub' => __('pos.del_tab_pending_sub'), 'count' => $tabCounts['pending']],
                    'delivered' => ['label' => __('pos.delivered_word'), 'sub' => null,                          'count' => $tabCounts['delivered']],
                    'returned'  => ['label' => __('pos.returned_word'),  'sub' => null,                          'count' => $tabCounts['returned']],
                ];
            @endphp
            @foreach($tabs as $tabKey => $tabMeta)
            <a href="{{ route('fbrpos.deliveries', array_filter(['date' => request('date'), 'tab' => $tabKey])) }}"
               class="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold border-b-2 whitespace-nowrap transition
                      {{ $activeTab === $tabKey
                          ? 'border-blue-600 text-blue-700 dark:text-blue-300'
                          : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600' }}">
                {{ $tabMeta['label'] }}
                @if($tabMeta['sub'])
                    <span class="hidden sm:inline text-[10px] font-normal text-gray-400 dark:text-gray-500">({{ $tabMeta['sub'] }})</span>
                @endif
                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-extrabold
                             {{ $activeTab === $tabKey
                                 ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300'
                                 : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400' }}">{{ $tabMeta['count'] }}</span>
            </a>
            @endforeach
        </div>

        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row sm:items-center gap-2 sm:justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex-shrink-0">{{ __('pos.delivery_bills') }} — {{ $day->format('d M Y') }}</h3>
            <div class="flex items-center gap-2">
                <input type="text" id="del-search" name="delsearch_nofill" autocomplete="off"
                       data-lpignore="true" data-form-type="other" data-1p-ignore
                       placeholder="{{ __('pos.del_search_ph') }}"
                       class="w-full sm:w-64 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5 focus:ring-blue-500 focus:border-blue-500">
                <span class="text-[11px] text-gray-400 flex-shrink-0"><span id="del-count">{{ $bills->count() }}</span>{{ __('pos.sfx_bills') }}</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3">{{ __('pos.bill_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.customer_word') }}</th>
                        <th class="px-4 py-3">{{ __('pos.amount_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.payment_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.rider_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.status_label') }}</th>
                        @if($activeTab === 'pending' || ($activeTab === 'delivered' && $isAdminOrManager))
                        <th class="px-4 py-3 text-right">{{ __('pos.update_label') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($bills as $b)
                    <tr data-delrow data-search="{{ Str::lower(($b->invoice_number ?: ('#' . $b->id)) . ' ' . ($b->customer_name ?? '') . ' ' . ($b->customer_phone ?? '') . ' ' . ($b->delivery_address ?? '') . ' ' . ($b->rider->name ?? '') . ' ' . ($b->delivery_status ?? '') . ' fbr' . ' ' . (!$b->rider_id && !empty($b->delivered_by) && !empty($deliveredByUsers[$b->delivered_by]) ? $deliveredByUsers[$b->delivered_by] : '')) }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900 dark:text-white flex items-center gap-1.5 flex-wrap">
                                <span>{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                                <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">FBR</span>
                            </div>
                            @php $billAgeDays = (int) floor(abs(now()->diffInHours(\Carbon\Carbon::parse($b->rider_assigned_at ?: $b->created_at))) / 24); @endphp
                            <div class="text-[11px] text-gray-400">{{ $billAgeDays >= 1 ? $b->created_at->format('d M · h:i A') : $b->created_at->format('h:i A') }}</div>
                            @if($activeTab === 'pending' && $billAgeDays >= 1)
                                <span class="inline-flex mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold {{ $billAgeDays >= 2 ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400' }}">{{ __('pos.del_age_days', ['days' => $billAgeDays]) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-gray-700 dark:text-gray-300">{{ $b->customer_name ?: __('pos.walk_in') }}</div>
                            @if($b->delivery_address)<div class="text-[11px] text-gray-400 max-w-[200px] truncate">{{ $b->delivery_address }}</div>@endif
                        </td>
                        <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $b->total_amount) }}</td>
                        <td class="px-4 py-3">
                            @if($b->payment_method === 'cash')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400">{{ __('pos.cash_word') }}</span>
                            @elseif($b->payment_method === 'qr_payment')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    {{ __('pos.prepaid_chip') }}
                                </span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $b->payment_method)) }}</span>
                            @endif
                            @if($b->rider_settlement_id)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400">{{ __('pos.settled_word') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($b->rider_settlement_id || in_array($b->delivery_status, ['delivered', 'returned']))
                                @if($b->rider_id)
                                    <span class="text-xs text-gray-600 dark:text-gray-300">{{ $b->rider->name ?? '—' }}</span>
                                @else
                                    {{-- Task 774/786: unassigned bill was marked delivered directly —
                                         show who closed it and when for audit trail. --}}
                                    <span class="text-xs text-gray-400 dark:text-gray-500 italic">{{ __('pos.del_no_rider_direct') }}</span>
                                    @if(!empty($b->delivered_at))
                                        <div class="text-[10px] text-gray-400 mt-0.5">{{ \Carbon\Carbon::parse($b->delivered_at)->format('h:i A') }}</div>
                                    @endif
                                    @if(!empty($b->delivered_by) && !empty($deliveredByUsers[$b->delivered_by]))
                                        <div class="text-[10px] text-gray-500 dark:text-gray-400">{{ __('pos.del_closed_by', ['name' => $deliveredByUsers[$b->delivered_by]]) }}</div>
                                    @endif
                                @endif
                            @else
                            <form method="POST" action="{{ route('fbrpos.deliveries.assign', $b->id) }}">
                                @csrf
                                <select name="rider_id" onchange="this.form.submit()" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                    @foreach($riders as $r)
                                    @if($r->is_active || (int) $b->rider_id === (int) $r->id)
                                    {{-- Task 1132: 🪫 low-battery marker (≤20%, on-duty only) — rider ka phone raste mein band na ho jaye. --}}
                                    <option value="{{ $r->id }}" {{ (int) $b->rider_id === (int) $r->id ? 'selected' : '' }}>{{ $r->name }}{{ ($openDeliveryCounts[$r->id] ?? 0) > 0 ? ' — ' . __('pos.rider_out_pill', ['count' => $openDeliveryCounts[$r->id]]) : '' }}{{ (!empty($hasBatteryPct) && $r->last_battery_pct !== null && (int) $r->last_battery_pct <= 20 && $r->on_duty) ? ' 🪫 ' . (int) $r->last_battery_pct . '%' : '' }}{{ $r->is_active ? '' : __('pos.sfx_inactive_paren') }}</option>
                                    @endif
                                    @endforeach
                                </select>
                            </form>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $st = $b->delivery_status;
                                $stClass = [
                                    'assigned'   => 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300',
                                    'dispatched' => 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300',
                                    'delivered'  => 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400',
                                    'returned'   => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400',
                                ][$st] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400';
                            @endphp
                            @if(!$st && $activeTab === 'pending')
                                {{-- Task 774: unassigned delivery bill on FBR board — amber chip
                                     matches PRA board treatment; nudges user to assign or deliver. --}}
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">{{ __('pos.del_status_unassigned') }}</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $stClass }}">{{ $st ? (Lang::has('pos.delivery_status_' . $st) ? __('pos.delivery_status_' . $st) : ucfirst($st)) : '—' }}</span>
                            @endif
                            @if($st === 'delivered' && $b->delivered_at && $b->rider_assigned_at)
                                @php $delMins = (int) \Carbon\Carbon::parse($b->rider_assigned_at)->diffInMinutes(\Carbon\Carbon::parse($b->delivered_at)); @endphp
                                <div class="text-[10px] text-gray-400 mt-0.5">{{ __('pos.delivery_took_mins', ['mins' => $delMins]) }}</div>
                            @endif
                        </td>
                        @if($activeTab === 'pending' || ($activeTab === 'delivered' && $isAdminOrManager))
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($activeTab === 'pending' && $b->rider_id && !$b->rider_settlement_id)
                                @if(in_array($st, ['assigned']))
                                <form method="POST" action="{{ route('fbrpos.deliveries.status', $b->id) }}" class="inline">
                                    @csrf<input type="hidden" name="delivery_status" value="dispatched">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition">{{ __('pos.dispatch_btn') }}</button>
                                </form>
                                @endif
                                @if(in_array($st, ['assigned', 'dispatched']))
                                <form method="POST" action="{{ route('fbrpos.deliveries.status', $b->id) }}" class="inline">
                                    @csrf<input type="hidden" name="delivery_status" value="delivered">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">{{ __('pos.delivered_word') }}</button>
                                </form>
                                @endif
                            @elseif($activeTab === 'pending' && !$b->rider_id && !$st)
                                {{-- Task 774: unassigned pending delivery on FBR board — Delivered only --}}
                                <form method="POST" action="{{ route('fbrpos.deliveries.status', $b->id) }}" class="inline"
                                      onsubmit="return confirm({{ Js::from(__('pos.del_mark_delivered_confirm')) }});">
                                    @csrf<input type="hidden" name="delivery_status" value="delivered">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">{{ __('pos.delivered_word') }}</button>
                                </form>
                            @endif
                            {{-- Task 773: settled bill still stuck at assigned/dispatched —
                                 forward move to Delivered only (Dispatch/Returned stay locked). --}}
                            @if($activeTab === 'pending' && $b->rider_id && $b->rider_settlement_id && in_array($st, ['assigned', 'dispatched']))
                            <form method="POST" action="{{ route('fbrpos.deliveries.status', $b->id) }}" class="inline">
                                @csrf<input type="hidden" name="delivery_status" value="delivered">
                                <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">{{ __('pos.delivered_word') }}</button>
                            </form>
                            @endif
                            @if(($activeTab === 'pending' && $b->rider_id && !$b->rider_settlement_id) || ($activeTab === 'delivered' && $isAdminOrManager && $b->rider_id && !$b->rider_settlement_id))
                                @if($st !== 'returned')
                                <form method="POST" action="{{ route('fbrpos.deliveries.status', $b->id) }}" class="inline" onsubmit="return confirm({{ Js::from(__('pos.confirm_mark_returned')) }});">
                                    @csrf<input type="hidden" name="delivery_status" value="returned">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition">{{ __('pos.returned_word') }}</button>
                                </form>
                                @endif
                            @endif
                            {{-- Prepaid conversion (admin only in FBR) --}}
                            @if($isAdminOrManager && $b->payment_method === 'cash' && $b->rider_id && !$b->rider_settlement_id && $b->delivery_status !== 'returned')
                            <form method="POST" action="{{ route('fbrpos.deliveries.mark-prepaid', $b->id) }}" class="inline"
                                  onsubmit="return confirm({{ Js::from(__('pos.confirm_mark_prepaid')) }});">
                                @csrf
                                <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition">{{ __('pos.mark_prepaid_online') }}</button>
                            </form>
                            @endif
                            @if($isAdminOrManager && !empty($b->prepaid_converted_at) && !$b->rider_settlement_id && $b->rider_id && $b->delivery_status !== 'returned')
                            <form method="POST" action="{{ route('fbrpos.deliveries.unmark-prepaid', $b->id) }}" class="inline"
                                  onsubmit="return confirm({{ Js::from(__('pos.confirm_unmark_prepaid')) }});">
                                @csrf
                                <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition">{{ __('pos.unmark_prepaid_btn') }}</button>
                            </form>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @empty
                    @php $colSpan = ($activeTab === 'pending' || ($activeTab === 'delivered' && $isAdminOrManager)) ? 7 : 6; @endphp
                    <tr><td colspan="{{ $colSpan }}" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('pos.no_delivery_bills_day') }}</td></tr>
                    @endforelse
                    @php $colSpan = $colSpan ?? (($activeTab === 'pending' || ($activeTab === 'delivered' && $isAdminOrManager)) ? 7 : 6); @endphp
                    <tr id="del-search-empty" style="display:none"><td colspan="{{ $colSpan }}" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('pos.del_no_match') }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    var inp = document.getElementById('del-search');
    if (!inp) return;
    var emptyRow = document.getElementById('del-search-empty');
    var cnt = document.getElementById('del-count');
    inp.addEventListener('input', function () {
        var q = inp.value.trim().toLowerCase(), shown = 0;
        document.querySelectorAll('tr[data-delrow]').forEach(function (tr) {
            var hit = !q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1;
            tr.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        if (emptyRow) emptyRow.style.display = shown === 0 ? '' : 'none';
        if (cnt) cnt.textContent = shown;
    });
})();
</script>
</x-fbr-pos-layout>
