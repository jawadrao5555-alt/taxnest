<x-pos-layout>

<div class="w-full overflow-x-hidden">
    <div class="px-4 sm:px-6 py-4 max-w-7xl mx-auto">
        <x-pwa-banner color="purple" appName="Nest Pra Pos" />
        <x-pwa-push scope="pos" />

        {{-- ━━━ In-app notifications (mark-read dismissal, 30-day window — mirrors DI dashboard) ━━━ --}}
        @if(isset($notifications) && $notifications->count() > 0)
        <div class="mb-4 space-y-2">
            @foreach($notifications as $notif)
            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-3 flex items-center space-x-3">
                <div class="p-1.5 bg-amber-500 rounded-lg shadow-sm">
                    <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                </div>
                <p class="flex-1 text-sm text-amber-900 dark:text-amber-100"><span class="font-bold">{{ $notif->title }}</span> &middot; {{ $notif->message }}</p>
                <form method="POST" action="{{ route('pos.notifications.dismiss', $notif->id) }}" class="flex-shrink-0">
                    @csrf
                    <button type="submit" title="{{ __('pos.dismiss') }}" aria-label="{{ __('pos.dismiss_notification') }}" class="p-1.5 rounded-lg text-amber-500 hover:text-amber-700 hover:bg-amber-100 dark:hover:bg-amber-800/40 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </form>
            </div>
            @endforeach
            @if($notifications->count() > 1)
            <div class="flex justify-end">
                <form method="POST" action="{{ route('pos.notifications.dismiss-all') }}">
                    @csrf
                    <button type="submit" class="text-xs font-semibold text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:text-amber-100 transition">{{ __('pos.dismiss_all') }}</button>
                </form>
            </div>
            @endif
        </div>
        @endif

        {{-- ━━━ PRA POS Universal v2 — Customize CTA (dismissible; hidden on the Saaf clean dashboard) ━━━ --}}
        @if(!$isCashier && ($dashboardStyle ?? 'default') !== 'saaf')
        <div x-data="{ show: localStorage.getItem('hide_universal_cta_v1') !== '1' }" x-show="show" x-cloak class="mb-4 rounded-2xl bg-purple-600 p-4 sm:p-5 text-white shadow-xl relative overflow-hidden">
            <button @click="show=false; localStorage.setItem('hide_universal_cta_v1','1')" class="absolute top-2 right-2 w-7 h-7 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/80 hover:text-white transition" aria-label="{{ __('pos.dismiss') }}">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="relative flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pr-6">
                <div class="flex items-start gap-3">
                    <div class="text-2xl sm:text-3xl hidden sm:block">🎯</div>
                    <div>
                        <div class="flex items-center gap-1.5 mb-0.5">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full bg-white/20 backdrop-blur text-[9px] font-bold uppercase tracking-wider">{{ __('pos.new_word') }}</span>
                            <span class="text-sm font-extrabold">{{ __('pos.universal_v2_title') }}</span>
                        </div>
                        <p class="hidden sm:block text-xs text-white/85">{{ __('pos.universal_v2_blurb') }}</p>
                    </div>
                </div>
                <div class="flex gap-2 flex-shrink-0 w-full sm:w-auto">
                    <a href="{{ route('pos.features') }}" class="flex-1 sm:flex-initial inline-flex justify-center items-center gap-1.5 px-4 py-2 rounded-lg bg-white text-purple-700 text-xs font-bold hover:bg-purple-50 transition shadow-lg whitespace-nowrap">
                        {{ __('pos.customize_pos') }}
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                    <a href="{{ route('pos.v2.invoice.create') }}" class="flex-1 sm:flex-initial inline-flex justify-center items-center px-4 py-2 rounded-lg bg-white/10 backdrop-blur text-white text-xs font-bold hover:bg-white/20 transition border border-white/30 whitespace-nowrap">{{ __('pos.open_pos') }}</a>
                </div>
            </div>
        </div>
        @endif

        {{-- ━━━ Opening Cash Balance (Jul 2026) — day-start drawer entry; auto-fills day-close reconciliation.
             Saaf style: KPI card shows the saved value, so this block appears only while entry is still needed. ━━━ --}}
        @if((isset($dayOpening) || isset($todayClosed)) && (($dashboardStyle ?? 'default') !== 'saaf' || (empty($dayOpening) && empty($todayClosed))))
        <div class="mb-4" x-data="{ editing: false }">
            @if(!empty($todayClosed))
                @if($dayOpening)
                <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 flex items-center gap-3 shadow-sm">
                    <div class="w-9 h-9 rounded-xl bg-teal-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.opening_cash') }}: Rs {{ number_format((float) $dayOpening->opening_cash, 2) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.day_closed_opening_locked') }}</p>
                    </div>
                </div>
                @else
                <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 flex items-center gap-3 shadow-sm">
                    <div class="w-9 h-9 rounded-xl bg-gray-500 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.opening_cash') }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.day_closed_no_opening_entry') }}</p>
                    </div>
                </div>
                @endif
            @elseif($dayOpening)
            <div class="rounded-2xl bg-white dark:bg-gray-900 border border-teal-200 dark:border-teal-800 p-4 shadow-sm">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-teal-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.todays_opening_cash') }}: <span class="text-teal-700 dark:text-teal-400">Rs {{ number_format((float) $dayOpening->opening_cash, 2) }}</span></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $dayOpening->enteredBy?->name ? __('pos.entered_by_name', ['name' => $dayOpening->enteredBy->name]) : __('pos.entered_at_day_start') }} · {{ __('pos.used_at_day_close') }}</p>
                    </div>
                    <button type="button" @click="editing = !editing" class="px-3 py-1.5 rounded-lg text-xs font-bold text-teal-700 dark:text-teal-300 bg-teal-50 dark:bg-teal-900/30 hover:bg-teal-100 dark:hover:bg-teal-900/50 transition">{{ __('pos.change_word') }}</button>
                </div>
                <form method="POST" action="{{ route('pos.day-opening.save') }}" x-show="editing" x-cloak class="mt-3 flex flex-wrap items-end gap-2">
                    @csrf
                    <div class="flex-1 min-w-[160px]">
                        <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.new_opening_cash_rs') }}</label>
                        <input type="number" name="opening_cash" step="0.01" min="0" max="99999999" required value="{{ (float) $dayOpening->opening_cash }}"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-xs font-bold hover:bg-teal-700 transition">{{ __('pos.update_word') }}</button>
                </form>
            </div>
            @else
            <div class="rounded-2xl bg-white dark:bg-gray-900 border border-amber-300 dark:border-amber-700 p-4 shadow-sm">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-amber-500 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.day_start_opening_cash') }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.opening_cash_hint') }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('pos.day-opening.save') }}" class="mt-3 flex flex-wrap items-end gap-2">
                    @csrf
                    <div class="flex-1 min-w-[160px]">
                        <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.opening_cash_rs') }}</label>
                        <input type="number" name="opening_cash" step="0.01" min="0" max="99999999" required placeholder="{{ __('pos.ph_eg_5000') }}"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-xs font-bold hover:bg-teal-700 transition">{{ __('pos.save_opening') }}</button>
                </form>
            </div>
            @endif
        </div>
        @endif

        {{-- ─── PROFIT + BI WIDGETS (v18) — admin only, sits above the chosen dashboard style (hidden on Saaf: its own profit KPI covers this) ─── --}}
        @if(!$isCashier && isset($profitStats) && ($dashboardStyle ?? 'default') !== 'saaf')
        @php
            $period = $profitStats['period'] ?? 'today';
            $periodLabel = ['today' => __('pos.period_today'), 'week' => __('pos.period_this_week'), 'month' => __('pos.period_this_month')][$period] ?? __('pos.period_today');
            $coveragePct = ($costCoverage['total'] ?? 0) > 0
                ? round(($costCoverage['with_cost'] / $costCoverage['total']) * 100)
                : 0;
        @endphp
        <div class="mb-4 rounded-2xl border border-emerald-200/60 dark:border-emerald-700/30 bg-white dark:bg-gray-900 p-4 sm:p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center shadow-md">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 14l4-4 4 4 5-5"/></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.profit_and_bi') }}</h3>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ $periodLabel }} · {{ __('pos.cost_coverage_label') }} <span class="font-semibold {{ $coveragePct >= 80 ? 'text-emerald-600' : ($coveragePct >= 40 ? 'text-amber-600' : 'text-red-500') }}">{{ $coveragePct }}%</span> {{ __('pos.of_products_have_cost') }}</p>
                    </div>
                </div>
                <div class="inline-flex rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-1 text-[11px] font-semibold shadow-sm">
                    @foreach (['today' => __('pos.period_today'), 'week' => __('pos.period_week'), 'month' => __('pos.period_month')] as $key => $label)
                        <a href="{{ route('pos.dashboard', ['period' => $key]) }}"
                           class="px-3 py-1.5 rounded-lg transition {{ $period === $key ? 'bg-emerald-500 text-white shadow' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- KPI grid: Sales / Cost / Profit / Margin / Orders --}}
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-3">
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">{{ __('pos.sales_word') }}</p>
                    <p class="text-lg sm:text-xl font-black text-gray-900 dark:text-white mt-1">Rs. {{ number_format($profitStats['revenue'], 0) }}</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">{{ __('pos.cost_word') }}</p>
                    <p class="text-lg sm:text-xl font-black text-amber-600 mt-1">Rs. {{ number_format($profitStats['cost'], 0) }}</p>
                </div>
                <div class="rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white p-3 shadow-md">
                    <p class="text-[10px] uppercase tracking-wider opacity-80 font-bold">{{ __('pos.profit_word') }}</p>
                    <p class="text-lg sm:text-xl font-black mt-1">Rs. {{ number_format($profitStats['profit'], 0) }}</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">{{ __('pos.margin_word') }}</p>
                    <p class="text-lg sm:text-xl font-black {{ $profitStats['margin'] >= 30 ? 'text-emerald-600' : ($profitStats['margin'] >= 15 ? 'text-amber-600' : 'text-red-500') }} mt-1">{{ $profitStats['margin'] }}%</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">{{ __('pos.orders_word') }}</p>
                    <p class="text-lg sm:text-xl font-black text-purple-600 mt-1">{{ number_format($profitStats['orders']) }}</p>
                </div>
            </div>

            @if($coveragePct < 80)
            <div class="mt-3 flex items-center gap-2 text-[11px] text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/40 rounded-lg px-3 py-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                <span>{{ __('pos.add_cost_prices_hint') }} <a href="{{ route('pos.products') }}" class="font-bold underline hover:text-amber-900">{{ __('pos.open_products_arrow') }}</a></span>
            </div>
            @endif

            {{-- Top products + low margin alerts --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mt-4">
                {{-- Top sold --}}
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[11px] font-bold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span> {{ __('pos.top_sold') }}
                    </p>
                    @forelse($topSold as $row)
                        <div class="flex items-center justify-between py-1.5 text-xs border-b border-gray-50 dark:border-gray-700/50 last:border-b-0">
                            <span class="truncate text-gray-800 dark:text-gray-200">{{ $row->name }}</span>
                            <span class="text-purple-600 font-bold ml-2 whitespace-nowrap">{{ rtrim(rtrim(number_format($row->qty, 2), '0'), '.') }} {{ __('pos.sold_suffix') }}</span>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-400 py-2">{{ __('pos.no_sales_yet_for_period', ['period' => strtolower($periodLabel)]) }}</p>
                    @endforelse
                </div>

                {{-- Top profit --}}
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[11px] font-bold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> {{ __('pos.most_profitable') }}
                    </p>
                    @forelse($topProfit as $row)
                        <div class="flex items-center justify-between py-1.5 text-xs border-b border-gray-50 dark:border-gray-700/50 last:border-b-0">
                            <span class="truncate text-gray-800 dark:text-gray-200">{{ $row->name }}</span>
                            <span class="text-emerald-600 font-bold ml-2 whitespace-nowrap">Rs. {{ number_format($row->profit, 0) }}</span>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-400 py-2">{{ __('pos.add_cost_to_see_profit') }}</p>
                    @endforelse
                </div>

                {{-- Low margin alerts --}}
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[11px] font-bold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> {{ __('pos.low_margin') }} <span class="text-gray-400 font-normal">(&lt; 15%)</span>
                    </p>
                    @forelse($lowMargin as $row)
                        @php $m = $row->price > 0 ? round((($row->price - $row->cost_price) / $row->price) * 100, 1) : 0; @endphp
                        <div class="flex items-center justify-between py-1.5 text-xs border-b border-gray-50 dark:border-gray-700/50 last:border-b-0">
                            <span class="truncate text-gray-800 dark:text-gray-200 flex items-center gap-1">
                                <svg class="w-3 h-3 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5z" clip-rule="evenodd"/></svg>
                                {{ $row->name }}
                            </span>
                            <span class="text-red-500 font-bold ml-2 whitespace-nowrap">{{ $m }}%</span>
                        </div>
                    @empty
                        <p class="text-[11px] text-emerald-600 py-2">{{ __('pos.all_products_healthy') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
        @endif
        {{-- ─── /PROFIT + BI WIDGETS ─── --}}

        @include('pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))
        @include('pos.dashboard-styles._drafts-section')
    </div>
</div>

<script>
function draftsManager() {
    return {
        drafts: @json($drafts),
        init() {},
        timeAgo(dateStr) {
            if (!dateStr) return '';
            const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
            if (diff < 60) return @js(__('pos.just_now'));
            if (diff < 3600) return Math.floor(diff / 60) + @js(__('pos.minutes_ago_suffix'));
            if (diff < 86400) return Math.floor(diff / 3600) + @js(__('pos.hours_ago_suffix'));
            return Math.floor(diff / 86400) + @js(__('pos.days_ago_suffix'));
        },
        formatMethod(m) {
            return m ? m.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : @js(__('pos.cash_title'));
        },
        async deleteDraft(id) {
            if (!confirm(@js(__('pos.confirm_delete_draft')))) return;
            try {
                const res = await fetch('/pos/api/draft/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
                });
                if (res.ok) {
                    this.drafts = this.drafts.filter(d => d.id !== id);
                    window.dispatchEvent(new CustomEvent('draft-deleted', { detail: { count: this.drafts.length } }));
                } else {
                    alert(@js(__('pos.failed_to_delete_draft')));
                }
            } catch (e) {
                alert(@js(__('pos.network_error_dot')));
            }
        },
        async deleteAllDrafts() {
            if (!confirm(@js(__('pos.confirm_delete_all_drafts')))) return;
            let failed = 0;
            for (const draft of [...this.drafts]) {
                try {
                    const res = await fetch('/pos/api/draft/' + draft.id, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
                    });
                    if (res.ok) {
                        this.drafts = this.drafts.filter(d => d.id !== draft.id);
                    } else { failed++; }
                } catch (e) { failed++; }
            }
            window.dispatchEvent(new CustomEvent('draft-deleted', { detail: { count: this.drafts.length } }));
            if (failed > 0) alert(@js(__('pos.drafts_could_not_be_deleted')).replace(':count', failed));
        }
    };
}
</script>
</x-pos-layout>
