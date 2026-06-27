<x-pos-layout>

<div class="w-full overflow-x-hidden">
    <div class="px-4 sm:px-6 py-4 max-w-7xl mx-auto">
        <x-pwa-banner color="purple" appName="Nest Pra Pos" />
        <x-pwa-push scope="pos" />

        {{-- ━━━ PRA POS Universal v2 — Customize CTA (dismissible) ━━━ --}}
        @if(!$isCashier)
        <div x-data="{ show: localStorage.getItem('hide_universal_cta_v1') !== '1' }" x-show="show" x-cloak class="mb-4 rounded-2xl bg-gradient-to-br from-purple-600 via-fuchsia-600 to-pink-600 p-4 sm:p-5 text-white shadow-xl relative overflow-hidden">
            <button @click="show=false; localStorage.setItem('hide_universal_cta_v1','1')" class="absolute top-2 right-2 w-7 h-7 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/80 hover:text-white transition" aria-label="Dismiss">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="relative flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pr-6">
                <div class="flex items-start gap-3">
                    <div class="text-3xl">🎯</div>
                    <div>
                        <div class="flex items-center gap-1.5 mb-0.5">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full bg-white/20 backdrop-blur text-[9px] font-bold uppercase tracking-wider">New</span>
                            <span class="text-sm font-extrabold">PRA POS Universal v2 — One Screen, All Features</span>
                        </div>
                        <p class="text-xs text-white/85">Customize from 9 industry presets (Restaurant, Cafe, Quick Service, Retail, Pharmacy, Salon, Grocery, Wholesale, Hybrid). Toggle KOT, KDS, recipes, inventory, loyalty &amp; more.</p>
                    </div>
                </div>
                <div class="flex gap-2 flex-shrink-0 w-full sm:w-auto">
                    <a href="{{ route('pos.features') }}" class="flex-1 sm:flex-initial inline-flex justify-center items-center gap-1.5 px-4 py-2 rounded-lg bg-white text-purple-700 text-xs font-bold hover:bg-purple-50 transition shadow-lg whitespace-nowrap">
                        Customize POS
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                    <a href="{{ route('pos.v2.invoice.create') }}" class="flex-1 sm:flex-initial inline-flex justify-center items-center px-4 py-2 rounded-lg bg-white/10 backdrop-blur text-white text-xs font-bold hover:bg-white/20 transition border border-white/30 whitespace-nowrap">Open POS</a>
                </div>
            </div>
        </div>
        @endif

        {{-- ─── PROFIT + BI WIDGETS (v18) — admin only, sits above the chosen dashboard style ─── --}}
        @if(!$isCashier && isset($profitStats))
        @php
            $period = $profitStats['period'] ?? 'today';
            $periodLabel = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'][$period] ?? 'Today';
            $coveragePct = ($costCoverage['total'] ?? 0) > 0
                ? round(($costCoverage['with_cost'] / $costCoverage['total']) * 100)
                : 0;
        @endphp
        <div class="mb-4 rounded-2xl border border-emerald-200/60 dark:border-emerald-700/30 bg-gradient-to-br from-emerald-50 via-white to-purple-50 dark:from-emerald-900/20 dark:via-gray-900 dark:to-purple-900/20 p-4 sm:p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center shadow-md">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 14l4-4 4 4 5-5"/></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">Profit & BI</h3>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ $periodLabel }} · cost coverage: <span class="font-semibold {{ $coveragePct >= 80 ? 'text-emerald-600' : ($coveragePct >= 40 ? 'text-amber-600' : 'text-red-500') }}">{{ $coveragePct }}%</span> of products have cost set</p>
                    </div>
                </div>
                <div class="inline-flex rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-1 text-[11px] font-semibold shadow-sm">
                    @foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month'] as $key => $label)
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
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">Sales</p>
                    <p class="text-lg sm:text-xl font-black text-gray-900 dark:text-white mt-1">Rs. {{ number_format($profitStats['revenue'], 0) }}</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">Cost</p>
                    <p class="text-lg sm:text-xl font-black text-amber-600 mt-1">Rs. {{ number_format($profitStats['cost'], 0) }}</p>
                </div>
                <div class="rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white p-3 shadow-md">
                    <p class="text-[10px] uppercase tracking-wider opacity-80 font-bold">Profit</p>
                    <p class="text-lg sm:text-xl font-black mt-1">Rs. {{ number_format($profitStats['profit'], 0) }}</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">Margin</p>
                    <p class="text-lg sm:text-xl font-black {{ $profitStats['margin'] >= 30 ? 'text-emerald-600' : ($profitStats['margin'] >= 15 ? 'text-amber-600' : 'text-red-500') }} mt-1">{{ $profitStats['margin'] }}%</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 font-bold">Orders</p>
                    <p class="text-lg sm:text-xl font-black text-purple-600 mt-1">{{ number_format($profitStats['orders']) }}</p>
                </div>
            </div>

            @if($coveragePct < 80)
            <div class="mt-3 flex items-center gap-2 text-[11px] text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/40 rounded-lg px-3 py-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                <span>Add cost prices to your products to see accurate profit. <a href="{{ route('pos.products') }}" class="font-bold underline hover:text-amber-900">Open Products →</a></span>
            </div>
            @endif

            {{-- Top products + low margin alerts --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mt-4">
                {{-- Top sold --}}
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[11px] font-bold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span> TOP SOLD
                    </p>
                    @forelse($topSold as $row)
                        <div class="flex items-center justify-between py-1.5 text-xs border-b border-gray-50 dark:border-gray-700/50 last:border-b-0">
                            <span class="truncate text-gray-800 dark:text-gray-200">{{ $row->name }}</span>
                            <span class="text-purple-600 font-bold ml-2 whitespace-nowrap">{{ rtrim(rtrim(number_format($row->qty, 2), '0'), '.') }} sold</span>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-400 py-2">No sales yet for {{ strtolower($periodLabel) }}.</p>
                    @endforelse
                </div>

                {{-- Top profit --}}
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[11px] font-bold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> MOST PROFITABLE
                    </p>
                    @forelse($topProfit as $row)
                        <div class="flex items-center justify-between py-1.5 text-xs border-b border-gray-50 dark:border-gray-700/50 last:border-b-0">
                            <span class="truncate text-gray-800 dark:text-gray-200">{{ $row->name }}</span>
                            <span class="text-emerald-600 font-bold ml-2 whitespace-nowrap">Rs. {{ number_format($row->profit, 0) }}</span>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-400 py-2">Add cost prices to see profit by product.</p>
                    @endforelse
                </div>

                {{-- Low margin alerts --}}
                <div class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-3">
                    <p class="text-[11px] font-bold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> LOW MARGIN <span class="text-gray-400 font-normal">(&lt; 15%)</span>
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
                        <p class="text-[11px] text-emerald-600 py-2">All products healthy — no low-margin items.</p>
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
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        },
        formatMethod(m) {
            return m ? m.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Cash';
        },
        async deleteDraft(id) {
            if (!confirm('Delete this draft? This cannot be undone.')) return;
            try {
                const res = await fetch('/pos/api/draft/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
                });
                if (res.ok) {
                    this.drafts = this.drafts.filter(d => d.id !== id);
                    window.dispatchEvent(new CustomEvent('draft-deleted', { detail: { count: this.drafts.length } }));
                } else {
                    alert('Failed to delete draft.');
                }
            } catch (e) {
                alert('Network error.');
            }
        },
        async deleteAllDrafts() {
            if (!confirm('Delete ALL drafts? This cannot be undone.')) return;
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
            if (failed > 0) alert(failed + ' draft(s) could not be deleted.');
        }
    };
}
</script>
</x-pos-layout>
