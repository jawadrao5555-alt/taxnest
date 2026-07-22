{{-- ━━━ SAAF — Simple & Clean dashboard (owner-approved, Jul 2026) ━━━
     Design rules: solid white cards, gray borders, teal #0A4D5C accents only,
     NO blue, NO gradients, NO colored glow shadows. Roman Urdu copy (customer-facing).
     Works in BOTH contexts:
       • Retail (PosController::dashboard): $todayStats, $yesterdayRevenue, $praSyncedToday,
         $profitStats, $topSold, $dayOpening, $todayClosed
       • Restaurant (RestaurantPosController::dashboard): $todaySales, $yesterdaySales,
         $todayOrders, $completedCount, $totalTables, $occupiedTables, $topProducts, $todayProfit --}}
@php
    if (!empty($isRestaurant)) {
        $saafToday     = (float) ($todaySales ?? 0);
        $saafYesterday = (float) ($yesterdaySales ?? 0);
        $saafBills     = (int) ($completedCount ?? $todayOrders ?? 0);
        $saafProfit    = (float) ($todayProfit ?? 0);
        $saafTopItems  = collect($topProducts ?? [])->take(5)->map(fn ($r) => [
            'name' => $r->item_name,
            'qty'  => (float) $r->total_qty,
        ]);
        $saafTopLabel  = 'Top Items (7 din)';
    } else {
        $saafToday     = (float) ($todayStats->revenue ?? 0);
        $saafYesterday = isset($yesterdayRevenue) ? (float) $yesterdayRevenue : null;
        $saafBills     = (int) ($todayStats->count ?? 0);
        $saafProfit    = (float) ($profitStats['profit'] ?? 0);
        $saafTopItems  = collect($topSold ?? [])->take(5)->map(fn ($r) => [
            'name' => $r->name,
            'qty'  => (float) $r->qty,
        ]);
        $saafTopLabel  = 'Aaj ke Top Items';
    }
    $saafAvg = $saafBills > 0 ? $saafToday / $saafBills : 0;
    $saafDeltaPct = ($saafYesterday !== null && $saafYesterday > 0)
        ? round((($saafToday - $saafYesterday) / $saafYesterday) * 100)
        : null;
@endphp

<div class="space-y-5 w-full">

    {{-- Greeting + style picker --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">Assalam-o-Alaikum 👋</h1>
            <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ now()->format('l, d M Y') }} · {{ $company->name ?? 'Business' }}</p>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    {{-- 4 clean KPI cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">Aaj ki Sales</p>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">Rs. {{ number_format(round($saafToday)) }}</p>
            @if($saafDeltaPct !== null)
                @if($saafDeltaPct >= 0)
                <p class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold mt-1">↑ {{ $saafDeltaPct }}% kal se zyada</p>
                @else
                <p class="text-[11px] text-red-500 font-semibold mt-1">↓ {{ abs($saafDeltaPct) }}% kal se kam</p>
                @endif
            @else
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Aaj ka total</p>
            @endif
        </div>

        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">Aaj ke Bills</p>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">{{ number_format($saafBills) }}</p>
            @if(isset($praSyncedToday) && $praSyncedToday !== null)
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ number_format($praSyncedToday) }} PRA synced ✓</p>
            @else
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Complete ho chuke</p>
            @endif
        </div>

        @if(empty($isCashier))
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">Aaj ka Profit</p>
            <p class="text-xl sm:text-2xl font-black mt-2" style="color:#0A4D5C;">Rs. {{ number_format(round($saafProfit)) }}</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Sirf aapko nazar aata hai</p>
        </div>
        @else
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">Average Bill</p>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">Rs. {{ number_format(round($saafAvg)) }}</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Fi bill ausat</p>
        </div>
        @endif

        @if(!empty($isRestaurant))
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">Tables</p>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">{{ $occupiedTables ?? 0 }}<span class="text-sm text-gray-400">/{{ $totalTables ?? 0 }}</span></p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Abhi occupied hain</p>
        </div>
        @elseif(isset($dayOpening) && $dayOpening)
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">Opening Cash</p>
            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white mt-2">Rs. {{ number_format(round((float) $dayOpening->opening_cash)) }}</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Day close par hisaab milega</p>
        </div>
        @else
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wide">Opening Cash</p>
            <p class="text-xl sm:text-2xl font-black text-gray-300 dark:text-gray-600 mt-2">—</p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ !empty($todayClosed) ? 'Aaj ka din close ho chuka hai' : 'Upar form se enter karein' }}</p>
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
                    <span class="w-6 h-6 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-[10px] font-bold text-gray-500 dark:text-gray-400 flex-shrink-0">{{ $i + 1 }}</span>
                    <span class="truncate text-gray-800 dark:text-gray-200">{{ $item['name'] }}</span>
                </span>
                <span class="text-gray-400 dark:text-gray-500 font-semibold whitespace-nowrap ml-2">{{ rtrim(rtrim(number_format($item['qty'], 2), '0'), '.') }} sold</span>
            </div>
            @empty
            <p class="text-xs text-gray-400 dark:text-gray-500 py-4 text-center">Abhi koi sale nahi hui — pehla bill banayein!</p>
            @endforelse
        </div>

        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <p class="text-sm font-extrabold text-gray-900 dark:text-white mb-3">Roz ke Kaam</p>
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('pos.day-close') }}" class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <div class="text-xl mb-1.5">🧾</div>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">Day Close</div>
                </a>
                <a href="{{ route('pos.reports') }}" class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <div class="text-xl mb-1.5">📊</div>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">Reports</div>
                </a>
                <a href="{{ route('pos.products') }}" class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <div class="text-xl mb-1.5">🛍️</div>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">Products</div>
                </a>
                <a href="{{ route('pos.customers') }}" class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-teal-600 dark:hover:border-teal-500 transition">
                    <div class="text-xl mb-1.5">👤</div>
                    <div class="text-[12px] font-bold text-gray-700 dark:text-gray-300">Customers</div>
                </a>
            </div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-4 text-center">Baaki sab kuch (Inventory, Riders, Tax Reports…) upar <b>Reports</b> aur profile menu mein mehfooz hai</p>
        </div>
    </div>

    {{-- One soft status banner --}}
    @if(!empty($praStatus))
    <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm flex-shrink-0" style="background:#0A4D5C;">✓</div>
        <p class="text-[13px] text-gray-600 dark:text-gray-300 flex-1">
            PRA reporting theek chal rahi hai{{ isset($praSyncedToday) && $praSyncedToday !== null ? ' — aaj ke ' . number_format($praSyncedToday) . ' bills sync ho chuke hain.' : '.' }}
        </p>
        <a href="{{ route('pos.day-close') }}" class="text-[12px] font-bold whitespace-nowrap" style="color:#0A4D5C;">Day Close karein →</a>
    </div>
    @else
    <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-gray-400 dark:bg-gray-600 flex items-center justify-center text-white text-sm flex-shrink-0">!</div>
        <p class="text-[13px] text-gray-600 dark:text-gray-300 flex-1">PRA reporting is waqt OFF hai — bills local mehfooz ho rahe hain.</p>
        <a href="{{ route('pos.day-close') }}" class="text-[12px] font-bold text-gray-500 dark:text-gray-400 whitespace-nowrap">Day Close karein →</a>
    </div>
    @endif

</div>
