<x-admin-layout>
    <x-slot name="header"><h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">📊 Advanced Analytics (Phase 4)</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto px-4">
        <form method="GET" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
            <div><label class="block text-xs font-bold uppercase mb-1">Date</label><input type="date" name="date" value="{{ $date }}" class="rounded-lg border-gray-300 text-sm"></div>
            <div><label class="block text-xs font-bold uppercase mb-1">Company</label><input type="number" name="company_id" value="{{ $companyId }}" placeholder="all" class="rounded-lg border-gray-300 text-sm w-28"></div>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-lg text-sm">Apply</button>
            <a href="{{ url('/admin/analytics/advanced?format=json&date='.$date.($companyId?'&company_id='.$companyId:'')) }}" class="text-blue-600 hover:underline text-xs font-semibold ml-auto">JSON API</a>
        </form>

        {{-- Insights --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
            <h3 class="font-bold text-lg mb-3">💡 Insights</h3>
            <ul class="space-y-2">
                @foreach($payload['insights'] as $insight)
                    <li class="bg-gradient-to-r from-amber-50 to-yellow-50 dark:from-amber-900/20 dark:to-yellow-900/20 border-l-4 border-amber-500 rounded p-3 text-sm font-semibold">{{ $insight }}</li>
                @endforeach
            </ul>
        </div>

        {{-- Heatmap --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
            <h3 class="font-bold text-lg mb-3">🌡️ Hourly Sales Heatmap ({{ $date }})</h3>
            <p class="text-xs text-slate-600 dark:text-slate-300 mb-4">
                Peak hour: <span class="font-bold">{{ $payload['heatmap_peak_hour'] }}:00</span>
                · Total bars = 24 (00:00 to 23:00)
            </p>
            <div class="grid grid-cols-12 sm:grid-cols-24 gap-1">
                @php $maxV = max(array_values($payload['heatmap'])) ?: 1; @endphp
                @foreach($payload['heatmap'] as $hr => $sales)
                    @php
                        $intensity = $sales > 0 ? max(0.1, $sales / $maxV) : 0;
                        $bg = $intensity > 0 ? 'rgba(5, 150, 105, ' . round($intensity, 2) . ')' : '#f1f5f9';
                    @endphp
                    <div class="rounded p-1 text-center" style="background:{{ $bg }};" title="Hour {{ str_pad($hr, 2, '0', STR_PAD_LEFT) }}:00 — PKR {{ number_format($sales, 2) }}">
                        <div class="text-[9px] font-bold {{ $intensity > 0.4 ? 'text-white' : 'text-slate-700' }}">{{ str_pad($hr, 2, '0', STR_PAD_LEFT) }}</div>
                        <div class="text-[9px] {{ $intensity > 0.4 ? 'text-white' : 'text-slate-600' }}">{{ $sales > 0 ? number_format($sales, 0) : '·' }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Top + Worst --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h3 class="font-bold text-lg mb-3">🏆 Top 5 Products (last 30d)</h3>
                <table class="w-full text-sm table-cards">
                    <thead class="text-xs uppercase font-bold bg-emerald-100 dark:bg-emerald-900/30">
                        <tr><th class="px-2 py-2 text-left">#</th><th class="px-2 py-2 text-left">Product</th><th class="px-2 py-2 text-right">Units</th><th class="px-2 py-2 text-right">Revenue</th></tr>
                    </thead>
                    <tbody>
                        @forelse($payload['top_products_30d'] as $i => $p)
                        <tr class="border-b border-gray-100"><td class="px-2 py-2 font-bold">{{ $i+1 }}</td><td class="px-2 py-2">{{ $p->item_name }}</td><td class="px-2 py-2 text-right font-bold">{{ rtrim(rtrim(number_format((float)$p->units, 4, '.', ''), '0'), '.') ?: '0' }}</td><td class="px-2 py-2 text-right">{{ number_format($p->revenue, 2) }}</td></tr>
                        @empty
                        <tr><td colspan="4" class="px-2 py-4 text-center text-slate-500">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h3 class="font-bold text-lg mb-3">🐢 Worst 5 Products (last 30d)</h3>
                <table class="w-full text-sm table-cards">
                    <thead class="text-xs uppercase font-bold bg-rose-100 dark:bg-rose-900/30">
                        <tr><th class="px-2 py-2 text-left">#</th><th class="px-2 py-2 text-left">Product</th><th class="px-2 py-2 text-right">Units</th><th class="px-2 py-2 text-right">Revenue</th></tr>
                    </thead>
                    <tbody>
                        @forelse($payload['worst_products_30d'] as $i => $p)
                        <tr class="border-b border-gray-100"><td class="px-2 py-2 font-bold">{{ $i+1 }}</td><td class="px-2 py-2">{{ $p->item_name }}</td><td class="px-2 py-2 text-right font-bold">{{ rtrim(rtrim(number_format((float)$p->units, 4, '.', ''), '0'), '.') ?: '0' }}</td><td class="px-2 py-2 text-right">{{ number_format($p->revenue, 2) }}</td></tr>
                        @empty
                        <tr><td colspan="4" class="px-2 py-4 text-center text-slate-500">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
