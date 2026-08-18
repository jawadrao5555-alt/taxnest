<x-pos-layout>
{{-- Deliveries board (Jul 2026) — open to admins, managers AND cashiers
     (the cashier is who receives the rider's cash). Rider CRUD lives on
     /pos/riders (admin-only). --}}
{{-- Embedded mode (Task 431, 10 Aug 2026): the sale screen opens this board in
     a modal IFRAME. Frame detection (window.self !== window.top) — not a query
     param — so tab links / date filter / POST-redirects inside the iframe keep
     the embedded look with zero param plumbing. Hides the app top-nav and the
     history-back button (back inside a frame would strand the modal). --}}
<script>if (window.self !== window.top) { document.documentElement.classList.add('tn-embedded'); }</script>
<style>
    .tn-embedded .topnav-bar { display: none !important; }
    .tn-embedded .tn-embed-hide { display: none !important; }
</style>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{ settleRider: null, settleTotal: 0, settleAmount: '', retBill: null, retBulk: null, retCount: 0, recalcSettle(form) {
         let t = 0;
         form.querySelectorAll('input[name=\'bill_ids[]\']:checked').forEach(cb => t += parseFloat(cb.dataset.amount || 0));
         this.settleTotal = t;
         this.settleAmount = t > 0 ? t : '';
     } }">

    {{-- Return / credit-note prompt (Task 570): a PRA-reported bill just came
         back with the rider — nudge the admin/manager straight into the
         return-bill form so tax/stock/cash reconcile (khata drop alone is not
         a credit note). Flash-driven, shows once after the status change. --}}
    @if(session('return_prompt_url'))
    <div class="mb-4 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 rounded-xl p-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-sm text-rose-800 dark:text-rose-300">
                <span class="font-bold">{{ __('pos.return_prompt_title', ['invoice' => session('return_prompt_invoice')]) }}</span>
                <span class="block text-xs mt-0.5">{{ __('pos.return_prompt_body') }}</span>
                @if((float) session('return_prompt_partial', 0) > 0)
                <span class="block text-xs mt-0.5 font-semibold text-amber-700 dark:text-amber-400">{{ __('pos.return_rider_partial_notice', ['amount' => number_format((float) session('return_prompt_partial'))]) }}</span>
                @endif
            </div>
            <a href="{{ session('return_prompt_url') }}" class="shrink-0 inline-flex items-center gap-2 px-4 py-2 bg-rose-600 text-white text-sm font-semibold rounded-lg hover:bg-rose-700 transition">
                {{ __('pos.return_create_bill_btn') }}
            </a>
        </div>
    </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
        <div class="flex items-center gap-3">
            {{-- Back (owner request Jul 2026): return to whatever screen the user came from;
                 direct-open fallback = POS dashboard. --}}
            <button type="button"
                    onclick="if (history.length > 1) { history.back(); } else { window.location = '{{ route('pos.dashboard') }}'; }"
                    class="tn-embed-hide inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition"
                    title="{{ __('pos.ti_go_back') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_word') }}
            </button>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.deliveries') }}</h1>
        </div>
        <form method="GET" action="{{ route('pos.deliveries') }}" class="flex items-center gap-2">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <input type="date" name="date" value="{{ $day->format('Y-m-d') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
            <button type="submit" class="px-3 py-2 rounded-lg bg-purple-600 text-white text-xs font-semibold shadow-sm hover:bg-purple-700 transition">{{ __('pos.go_btn') }}</button>
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
    {{-- Task 1104: shop pin missing → distance hints can't render; one-line
         nudge to the tracking page (admin/manager only — that page is
         PosAdminOnly). No nag beyond this. --}}
    @if($trackingHints && !$hasShopLocation && $isAdminOrManager)
    <div class="mb-2 text-[11px] text-gray-400 dark:text-gray-500">
        {{ __('pos.rider_shop_loc_hint') }}
        <a href="{{ route('pos.riders.tracking') }}" class="font-semibold text-purple-600 dark:text-purple-400 underline">{{ __('pos.rider_shop_loc_hint_link') }}</a>
    </div>
    @endif
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
                {{-- Owner (3 Aug 2026): "pehle se kitne order bahar" numaya ho —
                     assign karte waqt cashier ko ek nazar mein dikhe. --}}
                @php $oldestDays = (int) ($openDeliveryOldest[$rider->id] ?? 0); @endphp
                @if($openDel > 0)
                {{-- Purana atka bill = LAAL pill (owner, 7 Aug 2026 — Touseef case) --}}
                <span class="flex-shrink-0 px-2 py-0.5 rounded-full text-[11px] font-extrabold {{ $oldestDays >= 1 ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' }}">{{ __('pos.rider_out_pill', ['count' => $openDel]) }}</span>
                @endif
            </div>
            @if($openDel > 0 && $oldestDays >= 1)
            <div class="text-[11px] font-bold text-red-600 dark:text-red-400 mb-1">{{ __('pos.del_oldest_days', ['days' => $oldestDays]) }}</div>
            @endif
            {{-- Task 1104: duty / free / distance hints — Unlimited tracking
                 plans only ($trackingHints); other plans see the plain card. --}}
            @if($trackingHints)
            @php $hint = $riderHints[$rider->id] ?? null; @endphp
            <div class="flex items-center gap-1 flex-wrap mb-1.5">
                @if($suggestedRiderId !== null && (int) $suggestedRiderId === (int) $rider->id)
                <span class="px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300">★ {{ __('pos.rider_suggested_badge') }}</span>
                @endif
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold {{ ($hint['on_duty'] ?? false) ? 'bg-emerald-100 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400' }}">{{ ($hint['on_duty'] ?? false) ? __('pos.rider_duty_on_chip') : __('pos.rider_duty_off_chip') }}</span>
                @if($openDel === 0)
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-sky-100 dark:bg-sky-900/20 text-sky-700 dark:text-sky-400">{{ __('pos.rider_free_chip') }}</span>
                @endif
                @if(($hint['distance_km'] ?? null) !== null)
                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">{{ __('pos.rider_km_away', ['km' => number_format($hint['distance_km'], 1)]) }}</span>
                @endif
            </div>
            @endif
            @if($rider->phone)<div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">{{ $rider->phone }}</div>@endif
            @if($owed > 0)
                <div class="text-lg font-bold text-amber-600 dark:text-amber-400">Rs. {{ number_format($owed) }}</div>
                <div class="text-[11px] text-gray-400 mb-3">{{ __('pos.unsettled_cash_bills', ['count' => $open->count()]) }}</div>
                <button type="button" class="w-full px-3 py-1.5 rounded-lg bg-purple-600 text-white text-xs font-semibold shadow-sm hover:bg-purple-700 transition"
                        @click="settleRider = {{ $rider->id }}; settleTotal = {{ $owed }}; settleAmount = {{ $owed }}">{{ __('pos.settle_cash') }}</button>
            @else
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ __('pos.clear') }}</div>
                <div class="text-[11px] text-gray-400">{{ __('pos.no_cash_pending') }}</div>
            @endif
            {{-- Bulk update: mark ALL of this rider's open (assigned/dispatched)
                 deliveries in one go. Delivered/returned bills stay untouched.
                 ($openDel is computed at the card header above.) --}}
            @if($openDel > 0)
            <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                <div class="text-[11px] text-gray-400 mb-1.5">{{ __('pos.orders_out_for_delivery', ['count' => $openDel]) }}</div>
                <div class="flex gap-1.5">
                    <form method="POST" action="{{ route('pos.deliveries.bulk', $rider->id) }}" class="flex-1"
                          onsubmit="return confirm({{ Js::from(__('pos.confirm_all_delivered', ['count' => $openDel])) }});">
                        @csrf
                        <input type="hidden" name="delivery_status" value="delivered">
                        <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition">{{ __('pos.all_delivered') }}</button>
                    </form>
                    {{-- Task 586: bulk returned goes through the wastage-choice
                         modal — the choice applies to ALL of this rider's open
                         deliveries, and each gets its auto return bill. --}}
                    <button type="button" class="flex-1 px-2 py-1.5 rounded-lg bg-red-600 text-white text-[11px] font-semibold hover:bg-red-700 transition"
                            @click="retBulk = {{ $rider->id }}; retBill = null; retCount = {{ $openDel }}">{{ __('pos.all_returned') }}</button>
                </div>
            </div>
            @endif
        </div>

        {{-- Settle modal (one per rider with open bills) --}}
        @if($owed > 0)
        <template x-teleport="body">
            <div x-show="settleRider === {{ $rider->id }}" x-cloak @keydown.escape.window="if (settleRider === {{ $rider->id }}) settleRider = null" @keydown.escape.prevent.stop="settleRider = null" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="settleRider = null"></div>
                <div class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-lg p-5 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.settle_cash') }} — {{ $rider->name }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.settle_cash_hint') }}</p>
                    <form method="POST" action="{{ route('pos.riders.settle', $rider->id) }}" x-init="recalcSettle($el)" @change="if ($event.target.name === 'bill_ids[]') recalcSettle($event.target.closest('form'))">
                        @csrf
                        <div class="space-y-1.5 mb-4">
                            @foreach($open as $b)
                            @php $rem = (float) $b->total_amount - (float) ($b->rider_partial_paid ?? 0); @endphp
                            <label class="flex items-center gap-3 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/60 cursor-pointer">
                                <input type="checkbox" name="bill_ids[]" value="{{ $b->id }}" data-amount="{{ $rem }}" checked class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="flex-1 min-w-0">
                                    <span class="flex items-center gap-1.5 min-w-0">
                                        <span class="block text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                                        <span class="inline-flex flex-shrink-0 px-1.5 py-0.5 rounded text-[10px] font-bold {{ $b->isLocalBill() ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">{{ $b->isLocalBill() ? __('pos.local_word') : __('pos.pra_word') }}</span>
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
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm font-bold focus:ring-purple-500 focus:border-purple-500">
                            <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.settle_partial_hint') }}</p>
                            <p class="text-[11px] font-semibold text-red-600 dark:text-red-400 mt-1" x-show="parseFloat(settleAmount || 0) > settleTotal + 0.009" x-cloak>{{ __('pos.settle_amount_over_live') }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.notes_optional') }}</label>
                            <input type="text" name="notes" maxlength="500" placeholder="{{ __('pos.ph_eg_evening_handover') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div class="flex items-center justify-between mt-5">
                            <div class="text-xs text-gray-600 dark:text-gray-300">
                                <div>{{ __('pos.selected_total_colon') }} <b class="text-gray-900 dark:text-white">Rs. <span x-text="settleTotal.toLocaleString()"></span></b></div>
                                <div class="font-bold" :class="(settleTotal - (parseFloat(settleAmount) || 0)) > 0.009 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">{{ __('pos.baqaya_colon') }} Rs. <span x-text="Math.max(0, settleTotal - (parseFloat(settleAmount) || 0)).toLocaleString()"></span></div>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="settleRider = null">{{ __('pos.cancel') }}</button>
                                <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold shadow-sm hover:bg-purple-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
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
        {{ __('pos.no_active_riders_yet') }} <a href="{{ route('pos.riders') }}" class="font-semibold text-purple-600 dark:text-purple-400 underline">{{ __('pos.add_riders_link') }}</a> {{ __('pos.to_start_assigning') }}
    </div>
    @endif

    {{-- Per-rider day summary (compact read-only strip — zero extra DB queries,
         derived from the same $allBills collection as the tab counts above). --}}
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
                            @if($rs['pending'] > 0)
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-bold bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300">{{ $rs['pending'] }}</span>
                            @else
                                <span class="text-gray-300 dark:text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if($rs['delivered'] > 0)
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400">{{ $rs['delivered'] }}</span>
                            @else
                                <span class="text-gray-300 dark:text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if($rs['returned'] > 0)
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-bold bg-red-100 dark:bg-red-900/20 text-red-700 dark:text-red-400">{{ $rs['returned'] }}</span>
                            @else
                                <span class="text-gray-300 dark:text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">{{ $rs['pending'] + $rs['delivered'] + $rs['returned'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Day's delivery bills with status tabs (owner, 4 Aug 2026) --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">

        {{-- Tab bar --}}
        <div class="border-b border-gray-100 dark:border-gray-800 flex items-center gap-0 px-4 pt-3 overflow-x-auto">
            @php
                $tabs = [
                    'pending'   => ['label' => __('pos.pending_word'),   'sub' => __('pos.del_tab_pending_sub'), 'count' => $tabCounts['pending']],
                    'delivered' => ['label' => __('pos.delivered_word'), 'sub' => null,                          'count' => $tabCounts['delivered']],
                    'returned'  => ['label' => __('pos.returned_word'),  'sub' => null,                          'count' => $tabCounts['returned']],
                ];
            @endphp
            @foreach($tabs as $tabKey => $tabMeta)
            <a href="{{ route('pos.deliveries', array_filter(['date' => request('date'), 'tab' => $tabKey])) }}"
               class="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold border-b-2 whitespace-nowrap transition
                      {{ $activeTab === $tabKey
                          ? 'border-purple-600 text-purple-700 dark:text-purple-300'
                          : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600' }}">
                {{ $tabMeta['label'] }}
                @if($tabMeta['sub'])
                    <span class="hidden sm:inline text-[10px] font-normal text-gray-400 dark:text-gray-500">({{ $tabMeta['sub'] }})</span>
                @endif
                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-extrabold
                             {{ $activeTab === $tabKey
                                 ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300'
                                 : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400' }}">{{ $tabMeta['count'] }}</span>
            </a>
            @endforeach
        </div>

        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row sm:items-center gap-2 sm:justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex-shrink-0">{{ __('pos.delivery_bills') }} — {{ $day->format('d M Y') }}</h3>
            <div class="flex items-center gap-2">
                {{-- Owner (3 Aug 2026): bill/customer/rider/address search — list lambi
                     ho to dhoondna aasan. Client-side filter, koi request nahi. --}}
                <input type="text" id="del-search" name="delsearch_nofill" autocomplete="off"
                       data-lpignore="true" data-form-type="other" data-1p-ignore
                       placeholder="{{ __('pos.del_search_ph') }}"
                       class="w-full sm:w-64 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5 focus:ring-purple-500 focus:border-purple-500">
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
                    <tr data-delrow data-search="{{ Str::lower(($b->invoice_number ?: ('#' . $b->id)) . ' ' . ($b->customer_name ?? '') . ' ' . ($b->customer_phone ?? '') . ' ' . ($b->delivery_address ?? '') . ' ' . ($b->rider->name ?? '') . ' ' . ($b->delivery_status ?? '') . ' ' . ($b->isLocalBill() ? 'local' : 'pra') . ' ' . (!$b->rider_id && !empty($b->delivered_by) && !empty($deliveredByUsers[$b->delivered_by]) ? $deliveredByUsers[$b->delivered_by] : '')) }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900 dark:text-white flex items-center gap-1.5 flex-wrap">
                                <span>{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                                {{-- Task 353 (ZFC): Local vs PRA stream chip — same colors as
                                     customer-history (amber = local/provisional, blue = PRA). --}}
                                <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-bold {{ $b->isLocalBill() ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">{{ $b->isLocalBill() ? __('pos.local_word') : __('pos.pra_word') }}</span>
                            </div>
                            {{-- Pending tab ab HAR tareekh ke khule bills dikhata hai (7 Aug 2026) —
                                 purane bill par tareekh + "X din se pending" ka laal chip. --}}
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
                            @else
                                {{-- Prepaid / non-cash chip (Task 285): blue for digital payment, gray for others --}}
                                @if($b->payment_method === 'qr_payment')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        {{ __('pos.prepaid_chip') }}
                                    </span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">{{ ucwords(str_replace('_',' ', $b->payment_method)) }}</span>
                                @endif
                            @endif
                            @if($b->rider_settlement_id)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400">{{ __('pos.settled_word') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{-- Rider LOCKS once settled OR delivered/returned (terminal states) —
                                 reassign stays open only while assigned/dispatched so a rider who
                                 suddenly leaves can be swapped (khata follows rider_id). --}}
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
                            <form method="POST" action="{{ route('pos.deliveries.assign', $b->id) }}">
                                @csrf
                                <select name="rider_id" onchange="this.form.submit()" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                    {{-- Int-cast compare: PDO can return rider_id as a STRING on the
                                         cPanel host — strict === then never matches and the dropdown
                                         falls back to "— no rider —" even though the rider is saved. --}}
                                    {{-- Task 1104: $ridersPicker = suggestion-sorted (on-duty →
                                         free → nearest) on tracking plans; identical name order
                                         otherwise. Suffix ($riderOptionSuffix) carries the old
                                         ":count out" label plus duty/free/distance hints. --}}
                                    @foreach($ridersPicker as $r)
                                    @if($r->is_active || (int) $b->rider_id === (int) $r->id)
                                    {{-- Owner (3 Aug 2026): dropdown mein bhi dikhe kis rider ke
                                         kitne order pehle se bahar hain — barabar batwara aasan. --}}
                                    <option value="{{ $r->id }}" {{ (int) $b->rider_id === (int) $r->id ? 'selected' : '' }}>{{ $r->name }}{{ $riderOptionSuffix[$r->id] ?? '' }}{{ $r->is_active ? '' : __('pos.sfx_inactive_paren') }}</option>
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
                                    'assigned' => 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300',
                                    'dispatched' => 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300',
                                    'delivered' => 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400',
                                    'returned' => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400',
                                ][$st] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400';
                            @endphp
                            @if(!$st && $activeTab === 'pending')
                                {{-- Task 512: unassigned delivery bill now surfaces on Pending —
                                     amber chip nudges the admin/manager to pick a rider in the
                                     dropdown on this same row (no delivery-manager login needed). --}}
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">{{ __('pos.del_status_unassigned') }}</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $stClass }}">{{ $st ? (Lang::has('pos.delivery_status_' . $st) ? __('pos.delivery_status_' . $st) : ucfirst($st)) : '—' }}</span>
                            @endif
                            {{-- Return / credit-note CTA (Task 570): returned PRA-stream bill →
                                 admin/manager can jump straight to the return-bill form. Shows
                                 existing-return state instead once a credit note exists. Cheap
                                 per-row exists() runs ONLY on returned rows. --}}
                            @if($st === 'returned' && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type'))
                                @php
                                    // Task 586: status/link now visible to EVERY board role
                                    // (cashier bhi auto return banata hai); create-CTA link
                                    // stays admin/manager-only (manual form is gated).
                                    $returnRow = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                                        ->where('company_id', $b->company_id)
                                        ->where('parent_transaction_id', $b->id)
                                        ->orderByDesc('id')
                                        ->first();
                                    $canMakeReturn = !$returnRow && $isAdminOrManager && \App\Http\Controllers\PosReturnController::returnableReason($b) === null;
                                @endphp
                                @if($returnRow)
                                    <div class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 mt-0.5">
                                        @if($isAdminOrManager)
                                            <a href="{{ route('pos.transaction.show', $returnRow->id) }}" class="underline hover:text-emerald-700 dark:hover:text-emerald-300">{{ __('pos.return_already_made') }} — {{ $returnRow->invoice_number }}</a>
                                        @else
                                            {{ __('pos.return_already_made') }} — {{ $returnRow->invoice_number }}
                                        @endif
                                        @if(!empty($returnRow->is_wastage))
                                            <span class="inline-flex ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">{{ __('pos.return_wastage_chip') }}</span>
                                        @endif
                                    </div>
                                @elseif($canMakeReturn)
                                    <a href="{{ route('pos.transaction.return-form', $b->id) }}" class="inline-flex mt-1 px-2 py-0.5 rounded-lg text-[11px] font-semibold bg-rose-600 text-white hover:bg-rose-700 transition">{{ __('pos.return_create_bill_btn') }}</a>
                                    @if((float) ($b->rider_partial_paid ?? 0) > 0)
                                    <div class="text-[10px] font-semibold text-amber-600 dark:text-amber-400 mt-0.5">{{ __('pos.return_rider_partial_notice', ['amount' => number_format((float) $b->rider_partial_paid)]) }}</div>
                                    @endif
                                @endif
                            @endif
                            {{-- Delivery duration (owner, 3 Aug 2026): rider assign se
                                 delivered tak kitne minute lage. --}}
                            @if($st === 'delivered' && $b->delivered_at && $b->rider_assigned_at)
                                @php $delMins = (int) \Carbon\Carbon::parse($b->rider_assigned_at)->diffInMinutes(\Carbon\Carbon::parse($b->delivered_at)); @endphp
                                <div class="text-[10px] text-gray-400 mt-0.5">{{ __('pos.delivery_took_mins', ['mins' => $delMins]) }}</div>
                            @endif
                        </td>
                        @if($activeTab === 'pending' || ($activeTab === 'delivered' && $isAdminOrManager))
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($activeTab === 'pending' && $b->rider_id && !$b->rider_settlement_id)
                                @if(in_array($st, ['assigned']))
                                <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline">
                                    @csrf<input type="hidden" name="delivery_status" value="dispatched">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-900/20 hover:bg-purple-100 dark:hover:bg-purple-900/40 transition">{{ __('pos.dispatch_btn') }}</button>
                                </form>
                                @endif
                                @if(in_array($st, ['assigned', 'dispatched']))
                                <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline">
                                    @csrf<input type="hidden" name="delivery_status" value="delivered">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">{{ __('pos.delivered_word') }}</button>
                                </form>
                                @endif
                                @if($st !== 'returned')
                                {{-- Task 586: returned goes through the wastage-choice modal —
                                     the auto return bill (credit note) is created right there. --}}
                                <button type="button" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition"
                                        @click="retBill = {{ $b->id }}; retBulk = null">{{ __('pos.returned_word') }}</button>
                                @endif
                            @elseif($activeTab === 'pending' && !$b->rider_id && !$st)
                                {{-- Task 774: unassigned pending delivery — Delivered button only
                                     (no dispatch, no returned; rider cash/khata not involved). --}}
                                <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline"
                                      onsubmit="return confirm({{ Js::from(__('pos.del_mark_delivered_confirm')) }});">
                                    @csrf<input type="hidden" name="delivery_status" value="delivered">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">{{ __('pos.delivered_word') }}</button>
                                </form>
                            @endif
                            {{-- Task 773: settled bill still stuck at assigned/dispatched —
                                 forward move to Delivered only (Dispatch/Returned stay locked). --}}
                            @if($activeTab === 'pending' && $b->rider_id && $b->rider_settlement_id && in_array($st, ['assigned', 'dispatched']))
                            <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline">
                                @csrf<input type="hidden" name="delivery_status" value="delivered">
                                <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">{{ __('pos.delivered_word') }}</button>
                            </form>
                            @endif
                            {{-- Prepaid conversion button (Task 285, Aug 2026): admin/manager only,
                                 cash + unsettled + not returned — any tab (pending OR delivered).
                                 PRA-submitted bills get a different confirm dialog noting the PRA record is unchanged. --}}
                            @if($isAdminOrManager && $b->payment_method === 'cash' && $b->rider_id && !$b->rider_settlement_id && $b->delivery_status !== 'returned')
                            @php
                                $praNote = !empty($b->pra_invoice_number);
                                $confirmMsg = $praNote
                                    ? __('pos.confirm_mark_prepaid_pra', ['invoice' => $b->pra_invoice_number])
                                    : __('pos.confirm_mark_prepaid');
                            @endphp
                            <form method="POST" action="{{ route('pos.deliveries.mark-prepaid', $b->id) }}" class="inline"
                                  onsubmit="return confirm({{ Js::from($confirmMsg) }});">
                                @csrf
                                <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition">{{ __('pos.mark_prepaid_online') }}</button>
                            </form>
                            @endif
                            {{-- Prepaid undo / revert (Task 288, Aug 2026): admin/manager only.
                                 Visible when bill was converted via markPrepaid (prepaid_converted_at set)
                                 AND still unsettled AND not returned — same window as the forward action. --}}
                            @if($isAdminOrManager && !empty($b->prepaid_converted_at) && !$b->rider_settlement_id && $b->rider_id && $b->delivery_status !== 'returned')
                            <form method="POST" action="{{ route('pos.deliveries.unmark-prepaid', $b->id) }}" class="inline"
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

    {{-- Task 524 (customer voice note, 12 Aug 2026): purane (pichhle business
         days ke) UNASSIGNED delivery bills — collapsed section, pending tab ke
         badge/count mein shamil NAHIN. Assign yahin se ho sakta hai (same
         pos.deliveries.assign form as the main table; koi naya path nahi). --}}
    @if($activeTab === 'pending' && ($oldUnassigned ?? collect())->count())
    {{-- Task 536: search se auto-expand — script neeche CustomEvent bhejta hai. --}}
    <div class="mt-4 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden" x-data="{ showOldDel: false }" @tn-open-olddel.window="showOldDel = true">
        <button type="button" @click="showOldDel = !showOldDel"
                class="w-full flex items-center justify-between gap-2 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800/60 transition">
            <span class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0" :class="showOldDel ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                {{ __('pos.old_del_section') }}
                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-extrabold bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">{{ $oldUnassigned->count() }}</span>
                {{-- Task 536: search hone par yahan match ginti dikhti hai (main #del-count
                     ko inflate kiye baghair). --}}
                <span id="del-old-hits" style="display:none" class="px-1.5 py-0.5 rounded-full text-[11px] font-extrabold bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300"></span>
            </span>
            <span class="hidden sm:inline text-[11px] text-gray-400 truncate">{{ __('pos.old_del_hint') }}</span>
        </button>
        <div x-show="showOldDel" x-cloak class="border-t border-gray-100 dark:border-gray-800 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3">{{ __('pos.bill_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.customer_word') }}</th>
                        <th class="px-4 py-3">{{ __('pos.amount_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.payment_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.rider_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.status_label') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.update_label') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($oldUnassigned as $b)
                    {{-- Task 536: data-oldrow (data-delrow NAHIN — main count alag rahe) + same
                         data-search recipe as the main table so the one search box filters both. --}}
                    <tr data-oldrow data-search="{{ Str::lower(($b->invoice_number ?: ('#' . $b->id)) . ' ' . ($b->customer_name ?? '') . ' ' . ($b->customer_phone ?? '') . ' ' . ($b->delivery_address ?? '') . ' ' . ($b->isLocalBill() ? 'local' : 'pra')) }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-700 dark:text-gray-300 flex items-center gap-1.5 flex-wrap">
                                <span>{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                                <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-bold {{ $b->isLocalBill() ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">{{ $b->isLocalBill() ? __('pos.local_word') : __('pos.pra_word') }}</span>
                            </div>
                            <div class="text-[11px] text-gray-400">{{ $b->created_at->format('d M · h:i A') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-gray-700 dark:text-gray-300">{{ $b->customer_name ?: __('pos.walk_in') }}</div>
                            @if($b->delivery_address)<div class="text-[11px] text-gray-400 max-w-[200px] truncate">{{ $b->delivery_address }}</div>@endif
                        </td>
                        <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $b->total_amount) }}</td>
                        <td class="px-4 py-3">
                            @if($b->payment_method === 'cash')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400">{{ __('pos.cash_word') }}</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">{{ ucwords(str_replace('_',' ', $b->payment_method)) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('pos.deliveries.assign', $b->id) }}">
                                @csrf
                                <select name="rider_id" onchange="this.form.submit()" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                    {{-- Task 1104: same suggestion order + hint suffix as the main table. --}}
                                    @foreach($ridersPicker as $r)
                                    @if($r->is_active)
                                    <option value="{{ $r->id }}">{{ $r->name }}{{ $riderOptionSuffix[$r->id] ?? '' }}</option>
                                    @endif
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            {{-- Halka (gray) chip — purana bill koi demand nahi kar raha (Task 524). --}}
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">{{ __('pos.del_status_unassigned') }}</span>
                        </td>
                        {{-- Task 774: Delivered button for old unassigned bills too --}}
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline"
                                  onsubmit="return confirm({{ Js::from(__('pos.del_mark_delivered_confirm')) }});">
                                @csrf<input type="hidden" name="delivery_status" value="delivered">
                                <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">{{ __('pos.delivered_word') }}</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                    <tr id="del-old-search-empty" style="display:none"><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-400">{{ __('pos.del_no_match') }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ── Returned + wastage choice modal (Task 586) ─────────────────────────
         One shared modal for single-bill Returned AND bulk All Returned.
         Marking returned auto-creates the FULL return bill (credit note); the
         wastage choice decides whether stock goes back into inventory. --}}
    <template x-teleport="body">
        <div x-show="retBill !== null || retBulk !== null" x-cloak
             @keydown.escape.window="retBill = null; retBulk = null"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="retBill = null; retBulk = null"></div>
            <div class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.return_mark_title') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                    <span x-show="retBill !== null">{{ __('pos.confirm_mark_returned') }}</span>
                    <span x-show="retBulk !== null" x-text="{{ Js::from(__('pos.confirm_all_returned', ['count' => ':count'])) }}.replace(':count', retCount)"></span>
                </p>
                <form method="POST"
                      :action="retBulk !== null
                          ? '{{ url('/pos/deliveries/rider') }}/' + retBulk + '/bulk-status'
                          : '{{ url('/pos/deliveries') }}/' + retBill + '/status'">
                    @csrf
                    <input type="hidden" name="delivery_status" value="returned">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('pos.return_wastage_q') }}</p>
                    <div class="space-y-1.5 mb-3">
                        <label class="flex items-start gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/60 cursor-pointer">
                            <input type="radio" name="wastage" value="0" checked class="mt-0.5 border-gray-300 text-purple-600 focus:ring-purple-500">
                            <span class="text-xs text-gray-700 dark:text-gray-300">{{ __('pos.return_opt_restock') }}</span>
                        </label>
                        <label class="flex items-start gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/60 cursor-pointer">
                            <input type="radio" name="wastage" value="1" class="mt-0.5 border-gray-300 text-amber-600 focus:ring-amber-500">
                            <span class="text-xs text-gray-700 dark:text-gray-300">{{ __('pos.return_opt_wastage') }}</span>
                        </label>
                    </div>
                    <p class="text-[11px] text-gray-400 mb-4">{{ __('pos.return_auto_note') }}</p>
                    <div class="flex gap-2 justify-end">
                        <button type="button" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition"
                                @click="retBill = null; retBulk = null">{{ __('pos.cancel') }}</button>
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-600 text-white hover:bg-red-700 transition">{{ __('pos.return_mark_btn') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>
<script>
(function () {
    var inp = document.getElementById('del-search');
    if (!inp) return;
    var emptyRow = document.getElementById('del-search-empty');
    var cnt = document.getElementById('del-count');
    var oldHits = document.getElementById('del-old-hits');
    var oldEmpty = document.getElementById('del-old-search-empty');
    inp.addEventListener('input', function () {
        var q = inp.value.trim().toLowerCase(), shown = 0;
        document.querySelectorAll('tr[data-delrow]').forEach(function (tr) {
            var hit = !q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1;
            tr.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        if (emptyRow) emptyRow.style.display = shown === 0 ? '' : 'none';
        if (cnt) cnt.textContent = shown;
        // Task 536: purani-deliveries section ke rows bhi filter hon. Alag counter —
        // main #del-count purane matches se inflate NAHIN hota. Match milne par
        // section Alpine event se auto-khul jata hai aur header par match-ginti chip.
        var oldRows = document.querySelectorAll('tr[data-oldrow]'), oldShown = 0;
        oldRows.forEach(function (tr) {
            var hit = !q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1;
            tr.style.display = hit ? '' : 'none';
            if (hit) oldShown++;
        });
        if (oldRows.length) {
            if (oldHits) {
                oldHits.style.display = q ? '' : 'none';
                oldHits.textContent = q ? ('\u2192 ' + oldShown) : '';
            }
            if (oldEmpty) oldEmpty.style.display = (q && oldShown === 0) ? '' : 'none';
            if (q && oldShown > 0) window.dispatchEvent(new CustomEvent('tn-open-olddel'));
        }
    });
})();
</script>
</x-pos-layout>
