{{-- ━━━ SAAF — Simple & Clean dashboard (owner-approved, Jul 2026) ━━━
     Design rules: solid white cards, gray borders, teal #0A4D5C accents only,
     NO blue, NO gradients, NO colored glow shadows. Roman Urdu copy (customer-facing).
     Works in BOTH contexts:
       • Retail (PosController::dashboard): $todayStats, $yesterdayRevenue, $praSyncedToday,
         $profitStats, $topSold, $dayOpening, $todayClosed
       • Restaurant (RestaurantPosController::dashboard): $todaySales, $yesterdaySales,
         $todayOrders, $completedCount, $totalTables, $occupiedTables, $topProducts, $todayProfit --}}
@php
    // Owner rule (5 Aug 2026): Day Close is admin/manager work by DEFAULT.
    // Cashiers see these links only when the company switch (Customize) or a
    // Custom Access tick re-opens it — same verdict as nav + route guards.
    $saafCanDayClose = \App\Services\PosAccessService::dayCloseAllowed(auth('pos')->user());
    if (!empty($isRestaurant)) {
        // Task 988: combined PRA+Local (+exempt) figures — match the Aaj ka
        // Khaata sum this user sees (fallback = old single-stream sums).
        $saafToday     = (float) ($todayTotalSale ?? $todaySales ?? 0);
        $saafYesterday = (float) ($yesterdayTotalSale ?? $yesterdaySales ?? 0);
        $saafBills     = (int) ($completedCount ?? $todayOrders ?? 0);
        $saafProfit    = (float) ($todayProfit ?? 0);
        $saafTopItems  = collect($topProducts ?? [])->take(5)->map(fn ($r) => [
            'name' => $r->item_name,
            'qty'  => (float) $r->total_qty,
        ]);
        $saafTopLabel  = __("pos.top_items_7_days");
    } else {
        $saafToday     = (float) ($todayTotalSale ?? $todayStats->revenue ?? 0);
        $saafYesterday = isset($yesterdayRevenue) ? (float) $yesterdayRevenue : null;
        $saafBills     = (int) ($todayStats->count ?? 0);
        $saafProfit    = (float) ($profitStats['profit'] ?? 0);
        $saafTopItems  = collect($topSold ?? [])->take(5)->map(fn ($r) => [
            'name' => $r->name,
            'qty'  => (float) $r->qty,
        ]);
        $saafTopLabel  = __("pos.todays_top_items");
    }
    $saafDeltaPct = ($saafYesterday !== null && $saafYesterday > 0)
        ? round((($saafToday - $saafYesterday) / $saafYesterday) * 100)
        : null;
@endphp

<div class="space-y-5 w-full">

    {{-- Greeting + New Sale CTA + style picker --}}
    @php $saafFirstName = trim(explode(' ', trim(auth('pos')->user()?->name ?? auth()->user()?->name ?? ''))[0] ?? ''); @endphp
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">{{ __("pos.assalam_o_alaikum") }}{{ $saafFirstName !== '' ? ', ' . $saafFirstName : '' }} 👋</h1>
            <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ now()->format('l, d M Y') }} · {{ $company->name ?? __("pos.business_fallback") }}</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="{{ route('pos.invoice.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-bold hover:opacity-90 transition"
               style="background:#0A4D5C;">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                {{ __("pos.naya_bill") }}
            </a>
            @include('pos.dashboard-styles._style-picker')
        </div>
    </div>

    {{-- 4 clean KPI cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5 hover:border-teal-600 dark:hover:border-teal-500 transition">
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#ccfbf1;">
                    <svg class="w-4 h-4" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                </span>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">{{ __("pos.aaj_ki_sales") }}</p>
            </div>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">Rs. {{ number_format(round($saafToday)) }}</p>
            @if($saafDeltaPct !== null)
                @if($saafDeltaPct >= 0)
                <p class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold mt-1">↑ {{ $saafDeltaPct }}% {{ __("pos.kal_se_zyada") }}</p>
                @else
                <p class="text-[11px] text-red-500 font-semibold mt-1">↓ {{ abs($saafDeltaPct) }}% {{ __("pos.kal_se_kam") }}</p>
                @endif
            @else
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ __("pos.aaj_ka_total") }}</p>
            @endif
        </div>

        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5 hover:border-teal-600 dark:hover:border-teal-500 transition">
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#ccfbf1;">
                    <svg class="w-4 h-4" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">{{ __("pos.aaj_ke_bills") }}</p>
            </div>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">{{ number_format($saafBills) }}</p>
            @if(isset($praSyncedToday) && $praSyncedToday !== null)
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ number_format($praSyncedToday) }} {{ __("pos.pra_synced_tick") }}</p>
            @else
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ __("pos.complete_ho_chuke") }}</p>
            @endif
        </div>

        @if(empty($isCashier))
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5 hover:border-teal-600 dark:hover:border-teal-500 transition">
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#ccfbf1;">
                    <svg class="w-4 h-4" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </span>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">{{ __("pos.aaj_ka_profit") }}</p>
            </div>
            <p class="text-xl sm:text-2xl font-black mt-2" style="color:#0A4D5C;">Rs. {{ number_format(round($saafProfit)) }}</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ __("pos.sirf_aapko_nazar") }}</p>
        </div>
        @else
        {{-- Task 988: Average Bill tile replaced by New Customers (owner voice note, 16 Aug 2026) --}}
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5 hover:border-teal-600 dark:hover:border-teal-500 transition">
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#ccfbf1;">
                    <svg class="w-4 h-4" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </span>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">{{ __("pos.new_customers") }}</p>
            </div>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">{{ number_format($newCustomersToday ?? 0) }}</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ number_format($newCustomersMonth ?? 0) }} {{ __("pos.period_this_month") }}</p>
        </div>
        @endif

        @if(!empty($isRestaurant))
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5 hover:border-teal-600 dark:hover:border-teal-500 transition">
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#ccfbf1;">
                    <svg class="w-4 h-4" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M5 10v9m14-9v9M9 10v4m6-4v4M4 5h16a1 1 0 011 1v3H3V6a1 1 0 011-1z"/></svg>
                </span>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">{{ __("pos.tables_word") }}</p>
            </div>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">{{ $occupiedTables ?? 0 }}<span class="text-sm text-gray-400">/{{ $totalTables ?? 0 }}</span></p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ __("pos.abhi_occupied_hain") }}</p>
        </div>
        @elseif(isset($dayOpening) && $dayOpening)
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5 hover:border-teal-600 dark:hover:border-teal-500 transition">
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#ccfbf1;">
                    <svg class="w-4 h-4" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </span>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">{{ __("pos.opening_cash") }}</p>
            </div>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">Rs. {{ number_format(round((float) $dayOpening->opening_cash)) }}</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ __("pos.day_close_par_hisaab") }}</p>
        </div>
        @else
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5 hover:border-teal-600 dark:hover:border-teal-500 transition">
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#ccfbf1;">
                    <svg class="w-4 h-4" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </span>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">{{ __("pos.opening_cash") }}</p>
            </div>
            <p class="text-xl sm:text-2xl font-black text-gray-300 dark:text-gray-600 mt-2">—</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ !empty($todayClosed) ? __("pos.aaj_ka_din_close") : __("pos.upar_form_se_enter") }}</p>
        </div>
        @endif
    </div>

    {{-- Two panels: Top items + Roz ke Kaam --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 sm:gap-4">
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-sm font-extrabold text-gray-900 dark:text-white mb-3">{{ $saafTopLabel }}</p>
            @forelse($saafTopItems as $i => $item)
            <div class="flex items-center justify-between py-2.5 text-[13px] border-b border-gray-50 dark:border-gray-800 last:border-0">
                <span class="flex items-center gap-3 min-w-0">
                    @if($i === 0)
                    <span class="w-6 h-6 rounded-lg flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0" style="background:#0A4D5C;">1</span>
                    @else
                    <span class="w-6 h-6 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-[10px] font-bold text-gray-500 dark:text-gray-400 flex-shrink-0">{{ $i + 1 }}</span>
                    @endif
                    <span class="truncate text-gray-800 dark:text-gray-200 {{ $i === 0 ? 'font-semibold' : '' }}">{{ $item['name'] }}</span>
                </span>
                <span class="text-[11px] font-semibold whitespace-nowrap ml-2 px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($item['qty'], 2), '0'), '.') }} {{ __('pos.sold_suffix') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-400 dark:text-gray-500 py-4 text-center">{{ __("pos.abhi_koi_sale_nahi") }}</p>
            @endforelse
        </div>

        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-sm font-extrabold text-gray-900 dark:text-white mb-3">{{ __("pos.roz_ke_kaam") }}</p>
            <div class="grid grid-cols-2 gap-3">
                @if($saafCanDayClose)
                <a href="{{ route('pos.day-close') }}" class="group rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <span class="mx-auto mb-2 w-9 h-9 rounded-lg flex items-center justify-center" style="background:#ccfbf1;">
                        <svg class="w-5 h-5" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">{{ __("pos.day_close") }}</div>
                </a>
                @endif
                <a href="{{ route('pos.reports') }}" class="group rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <span class="mx-auto mb-2 w-9 h-9 rounded-lg flex items-center justify-center" style="background:#ccfbf1;">
                        <svg class="w-5 h-5" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </span>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">{{ __("pos.reports") }}</div>
                </a>
                <a href="{{ route('pos.products') }}" class="group rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <span class="mx-auto mb-2 w-9 h-9 rounded-lg flex items-center justify-center" style="background:#ccfbf1;">
                        <svg class="w-5 h-5" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </span>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">{{ __("pos.products_word") }}</div>
                </a>
                <a href="{{ route('pos.customers') }}" class="group rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <span class="mx-auto mb-2 w-9 h-9 rounded-lg flex items-center justify-center" style="background:#ccfbf1;">
                        <svg class="w-5 h-5" style="color:#0A4D5C;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </span>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">{{ __("pos.customers_word") }}</div>
                </a>
            </div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-4 text-center">{!! __("pos.baaki_sab_kuch", ["reports" => "<b>" . e(__("pos.reports")) . "</b>"]) !!}</p>
        </div>
    </div>

    {{-- One soft status banner --}}
    @if(!empty($praStatus))
    <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm flex-shrink-0" style="background:#0A4D5C;">✓</div>
        <p class="text-[13px] text-gray-600 dark:text-gray-300 flex-1">
            {{ __("pos.pra_reporting_theek") }}{{ isset($praSyncedToday) && $praSyncedToday !== null ? __("pos.aaj_ke_bills_sync", ["count" => number_format($praSyncedToday)]) : "." }}
        </p>
        @if($saafCanDayClose)
        <a href="{{ route('pos.day-close') }}" class="text-[12px] font-bold whitespace-nowrap" style="color:#0A4D5C;">{{ __("pos.day_close_karein_arrow") }}</a>
        @endif
    </div>
    @else
    <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-gray-400 dark:bg-gray-600 flex items-center justify-center text-white text-sm flex-shrink-0">!</div>
        <p class="text-[13px] text-gray-600 dark:text-gray-300 flex-1">{{ __("pos.pra_reporting_off_local") }}</p>
        @if($saafCanDayClose)
        <a href="{{ route('pos.day-close') }}" class="text-[12px] font-bold text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ __("pos.day_close_karein_arrow") }}</a>
        @endif
    </div>
    @endif

</div>
