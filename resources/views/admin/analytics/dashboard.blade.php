<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">📊 Analytics Dashboard (Phase 1)</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Filters --}}
            <form method="GET" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1 uppercase tracking-wide">Date</label>
                    <input type="date" name="date" value="{{ $date }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1 uppercase tracking-wide">Company ID (optional)</label>
                    <input type="number" name="company_id" value="{{ $companyId }}" placeholder="all" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm w-32">
                </div>
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-lg text-sm">Apply</button>
                <a href="{{ url('/admin/analytics/dashboard?format=json&date='.$date.($companyId?'&company_id='.$companyId:'')) }}" class="text-blue-600 hover:underline text-xs font-semibold ml-auto">JSON API</a>
                <a href="{{ url('/admin/analytics/advanced?date='.$date.($companyId?'&company_id='.$companyId:'')) }}" class="text-purple-600 hover:underline text-xs font-semibold">→ Advanced (Phase 4)</a>
            </form>

            {{-- KPI Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-gradient-to-br from-emerald-500 to-emerald-700 rounded-xl shadow p-5 text-white">
                    <p class="text-xs font-bold uppercase tracking-wider opacity-80">Today's Sales</p>
                    <p class="text-3xl font-extrabold mt-2 tabular-nums">PKR {{ number_format($kpis['today_sales'], 2) }}</p>
                    <p class="text-xs opacity-80 mt-1">{{ $date }}</p>
                </div>
                <div class="bg-gradient-to-br from-blue-500 to-blue-700 rounded-xl shadow p-5 text-white">
                    <p class="text-xs font-bold uppercase tracking-wider opacity-80">Today's Profit</p>
                    <p class="text-3xl font-extrabold mt-2 tabular-nums">PKR {{ number_format($kpis['today_profit'], 2) }}</p>
                    <p class="text-[10px] opacity-80 mt-1">⚠️ Subtotal proxy (cost_price added in Phase 3)</p>
                </div>
                <div class="bg-gradient-to-br from-purple-500 to-purple-700 rounded-xl shadow p-5 text-white">
                    <p class="text-xs font-bold uppercase tracking-wider opacity-80">Invoice Count</p>
                    <p class="text-3xl font-extrabold mt-2 tabular-nums">{{ $kpis['invoice_count'] }}</p>
                    <p class="text-xs opacity-80 mt-1">completed bills</p>
                </div>
                <div class="bg-gradient-to-br from-amber-500 to-amber-700 rounded-xl shadow p-5 text-white">
                    <p class="text-xs font-bold uppercase tracking-wider opacity-80">Avg Invoice Value</p>
                    <p class="text-3xl font-extrabold mt-2 tabular-nums">PKR {{ number_format($kpis['avg_invoice_value'], 2) }}</p>
                    <p class="text-xs opacity-80 mt-1">per bill</p>
                </div>
            </div>

            {{-- Top Product --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
                <h3 class="font-bold text-lg text-slate-900 dark:text-white mb-4">🏆 Top Product Today</h3>
                @if($kpis['top_product_today'])
                    <div class="bg-gradient-to-r from-yellow-50 to-yellow-100 dark:from-yellow-900/20 dark:to-yellow-900/40 rounded-lg p-4">
                        <p class="text-xl font-extrabold text-yellow-800 dark:text-yellow-200">{{ $kpis['top_product_today']->item_name }}</p>
                        <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                            <span class="font-bold">{{ $kpis['top_product_today']->units_sold }}</span> units · Revenue
                            <span class="font-bold">PKR {{ number_format($kpis['top_product_today']->revenue, 2) }}</span>
                        </p>
                    </div>
                @else
                    <p class="text-slate-500 dark:text-slate-400 text-sm">No product sales recorded for {{ $date }}.</p>
                @endif
            </div>

            {{-- Top 5 Products Table --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h3 class="font-bold text-lg text-slate-900 dark:text-white mb-4">📦 Top 5 Products</h3>
                @if(count($kpis['top_5_products']))
                    <table class="w-full text-sm table-cards">
                        <thead class="text-xs uppercase font-bold text-slate-600 dark:text-slate-400 border-b border-gray-200 dark:border-gray-700">
                            <tr><th class="text-left py-2">#</th><th class="text-left py-2">Product</th><th class="text-right py-2">Units</th><th class="text-right py-2">Revenue</th></tr>
                        </thead>
                        <tbody>
                            @foreach($kpis['top_5_products'] as $i => $p)
                            <tr class="border-b border-gray-100 dark:border-gray-700">
                                <td class="py-2 font-bold text-slate-700 dark:text-slate-300">{{ $i + 1 }}</td>
                                <td class="py-2 text-slate-900 dark:text-white">{{ $p->item_name }}</td>
                                <td class="py-2 text-right font-bold tabular-nums">{{ $p->units_sold }}</td>
                                <td class="py-2 text-right font-bold tabular-nums">PKR {{ number_format($p->revenue, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-slate-500 dark:text-slate-400 text-sm">No product sales for {{ $date }}.</p>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
