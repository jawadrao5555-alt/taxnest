<x-pos-layout>
{{-- Deliveries board (Jul 2026) — open to admins, managers AND cashiers
     (the cashier is who receives the rider's cash). Rider CRUD lives on
     /pos/riders (admin-only). --}}
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{ settleRider: null, settleTotal: 0, recalcSettle(form) {
         let t = 0;
         form.querySelectorAll('input[name=\'bill_ids[]\']:checked').forEach(cb => t += parseFloat(cb.dataset.amount || 0));
         this.settleTotal = t;
     } }">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
        <div class="flex items-center gap-3">
            {{-- Back (owner request Jul 2026): return to whatever screen the user came from;
                 direct-open fallback = POS dashboard. --}}
            <button type="button"
                    onclick="if (history.length > 1) { history.back(); } else { window.location = '{{ route('pos.dashboard') }}'; }"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition"
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
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach($riders as $rider)
        @php
            $open = $khataBills[$rider->id] ?? collect();
            $owed = (float) $open->sum('total_amount');
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
                @if($openDel > 0)
                <span class="flex-shrink-0 px-2 py-0.5 rounded-full text-[11px] font-extrabold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">{{ __('pos.rider_out_pill', ['count' => $openDel]) }}</span>
                @endif
            </div>
            @if($rider->phone)<div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">{{ $rider->phone }}</div>@endif
            @if($owed > 0)
                <div class="text-lg font-bold text-amber-600 dark:text-amber-400">Rs. {{ number_format($owed) }}</div>
                <div class="text-[11px] text-gray-400 mb-3">{{ __('pos.unsettled_cash_bills', ['count' => $open->count()]) }}</div>
                <button type="button" class="w-full px-3 py-1.5 rounded-lg bg-purple-600 text-white text-xs font-semibold shadow-sm hover:bg-purple-700 transition"
                        @click="settleRider = {{ $rider->id }}; settleTotal = {{ $owed }}">{{ __('pos.settle_cash') }}</button>
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
                    <form method="POST" action="{{ route('pos.deliveries.bulk', $rider->id) }}" class="flex-1"
                          onsubmit="return confirm({{ Js::from(__('pos.confirm_all_returned', ['count' => $openDel])) }});">
                        @csrf
                        <input type="hidden" name="delivery_status" value="returned">
                        <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-red-600 text-white text-[11px] font-semibold hover:bg-red-700 transition">{{ __('pos.all_returned') }}</button>
                    </form>
                </div>
            </div>
            @endif
        </div>

        {{-- Settle modal (one per rider with open bills) --}}
        @if($owed > 0)
        <template x-teleport="body">
            <div x-show="settleRider === {{ $rider->id }}" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="settleRider = null"></div>
                <div class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-lg p-5 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.settle_cash') }} — {{ $rider->name }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.settle_cash_hint') }}</p>
                    <form method="POST" action="{{ route('pos.riders.settle', $rider->id) }}" x-init="recalcSettle($el)" @change="recalcSettle($event.target.closest('form'))">
                        @csrf
                        <div class="space-y-1.5 mb-4">
                            @foreach($open as $b)
                            <label class="flex items-center gap-3 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/60 cursor-pointer">
                                <input type="checkbox" name="bill_ids[]" value="{{ $b->id }}" data-amount="{{ (float) $b->total_amount }}" checked class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="flex-1 min-w-0">
                                    <span class="block text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                                    <span class="block text-[11px] text-gray-400">{{ $b->created_at->format('d/m h:i A') }}@if($b->customer_name) · {{ $b->customer_name }}@endif</span>
                                </span>
                                <span class="text-xs font-bold text-gray-900 dark:text-white">Rs. {{ number_format((float) $b->total_amount) }}</span>
                            </label>
                            @endforeach
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.notes_optional') }}</label>
                            <input type="text" name="notes" maxlength="500" placeholder="{{ __('pos.ph_eg_evening_handover') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div class="flex items-center justify-between mt-5">
                            <div class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.receiving_colon') }} Rs. <span x-text="settleTotal.toLocaleString()"></span></div>
                            <div class="flex gap-2">
                                <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="settleRider = null">{{ __('pos.cancel') }}</button>
                                <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold shadow-sm hover:bg-purple-700 transition">{{ __('pos.confirm_settlement') }}</button>
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
                    <tr data-delrow data-search="{{ Str::lower(($b->invoice_number ?: ('#' . $b->id)) . ' ' . ($b->customer_name ?? '') . ' ' . ($b->customer_phone ?? '') . ' ' . ($b->delivery_address ?? '') . ' ' . ($b->rider->name ?? '') . ' ' . ($b->delivery_status ?? '')) }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $b->invoice_number ?: ('#' . $b->id) }}</div>
                            <div class="text-[11px] text-gray-400">{{ $b->created_at->format('h:i A') }}</div>
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
                                <span class="text-xs text-gray-600 dark:text-gray-300">{{ $b->rider->name ?? '—' }}</span>
                            @else
                            <form method="POST" action="{{ route('pos.deliveries.assign', $b->id) }}">
                                @csrf
                                <select name="rider_id" onchange="this.form.submit()" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                    {{-- Int-cast compare: PDO can return rider_id as a STRING on the
                                         cPanel host — strict === then never matches and the dropdown
                                         falls back to "— no rider —" even though the rider is saved. --}}
                                    @foreach($riders as $r)
                                    @if($r->is_active || (int) $b->rider_id === (int) $r->id)
                                    {{-- Owner (3 Aug 2026): dropdown mein bhi dikhe kis rider ke
                                         kitne order pehle se bahar hain — barabar batwara aasan. --}}
                                    <option value="{{ $r->id }}" {{ (int) $b->rider_id === (int) $r->id ? 'selected' : '' }}>{{ $r->name }}{{ ($openDeliveryCounts[$r->id] ?? 0) > 0 ? ' — ' . __('pos.rider_out_pill', ['count' => $openDeliveryCounts[$r->id]]) : '' }}{{ $r->is_active ? '' : __('pos.sfx_inactive_paren') }}</option>
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
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $stClass }}">{{ $st ? (Lang::has('pos.delivery_status_' . $st) ? __('pos.delivery_status_' . $st) : ucfirst($st)) : '—' }}</span>
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
                                <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline" onsubmit="return confirm({{ Js::from(__('pos.confirm_mark_returned')) }});">
                                    @csrf<input type="hidden" name="delivery_status" value="returned">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition">{{ __('pos.returned_word') }}</button>
                                </form>
                                @endif
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
</x-pos-layout>
