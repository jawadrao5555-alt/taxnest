<x-admin-layout>
    <x-slot name="header"><h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">💡 Smart Pricing Suggestions (Phase 3)</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto px-4">
        @if(session('success'))<div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-800 p-4 mb-4 rounded">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="bg-rose-100 border-l-4 border-rose-500 text-rose-800 p-4 mb-4 rounded">{{ $errors->first() }}</div>@endif

        <form method="GET" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide mb-1">Company</label>
                <input type="number" name="company_id" value="{{ $companyId }}" placeholder="all" class="rounded-lg border-gray-300 text-sm w-28">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide mb-1">Verdict</label>
                <select name="verdict" class="rounded-lg border-gray-300 text-sm">
                    <option value="">All</option>
                    <option value="fast_selling" @selected($verdictFilter === 'fast_selling')>🚀 Fast-selling</option>
                    <option value="slow_selling" @selected($verdictFilter === 'slow_selling')>🐢 Slow-selling</option>
                    <option value="typical" @selected($verdictFilter === 'typical')>Typical</option>
                    <option value="insufficient_data" @selected($verdictFilter === 'insufficient_data')>No data</option>
                </select>
            </div>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-lg text-sm">Filter</button>
        </form>

        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
            <div class="bg-slate-100 dark:bg-slate-800 rounded-lg p-3 text-center"><p class="text-xs uppercase font-bold">Total</p><p class="text-2xl font-extrabold">{{ $summary['total'] }}</p></div>
            <div class="bg-emerald-100 dark:bg-emerald-900/30 rounded-lg p-3 text-center"><p class="text-xs uppercase font-bold text-emerald-700">🚀 Fast</p><p class="text-2xl font-extrabold text-emerald-700">{{ $summary['fast_selling'] }}</p></div>
            <div class="bg-rose-100 dark:bg-rose-900/30 rounded-lg p-3 text-center"><p class="text-xs uppercase font-bold text-rose-700">🐢 Slow</p><p class="text-2xl font-extrabold text-rose-700">{{ $summary['slow_selling'] }}</p></div>
            <div class="bg-blue-100 dark:bg-blue-900/30 rounded-lg p-3 text-center"><p class="text-xs uppercase font-bold text-blue-700">Typical</p><p class="text-2xl font-extrabold text-blue-700">{{ $summary['typical'] }}</p></div>
            <div class="bg-gray-100 dark:bg-gray-800 rounded-lg p-3 text-center"><p class="text-xs uppercase font-bold">No Data</p><p class="text-2xl font-extrabold">{{ $summary['insufficient_data'] }}</p></div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase font-bold text-slate-700 dark:text-slate-300 bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left">Product</th>
                        <th class="px-3 py-2 text-right">Current</th>
                        <th class="px-3 py-2 text-right">Units 7d</th>
                        <th class="px-3 py-2 text-right">Company Avg</th>
                        <th class="px-3 py-2 text-center">Verdict</th>
                        <th class="px-3 py-2 text-right">Suggested</th>
                        <th class="px-3 py-2 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suggestions as $s)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-2"><span class="font-semibold">{{ $s['name'] }}</span><span class="block text-[10px] text-slate-500">co #{{ $s['company_id'] }}</span></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($s['current_price'], 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format($s['units_last_7d'], 4, '.', ''), '0'), '.') ?: '0' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format($s['company_avg_units_7d'], 4, '.', ''), '0'), '.') ?: '0' }}</td>
                        <td class="px-3 py-2 text-center">
                            @php $colors = ['fast_selling'=>'emerald', 'slow_selling'=>'rose', 'typical'=>'blue', 'insufficient_data'=>'gray', 'product_not_found'=>'gray']; $c = $colors[$s['verdict']] ?? 'gray'; @endphp
                            <span class="px-2 py-1 text-xs font-bold rounded bg-{{ $c }}-100 text-{{ $c }}-800">{{ str_replace('_', ' ', $s['verdict']) }}</span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            @if($s['suggested_price'] !== null && $s['verdict'] !== 'insufficient_data')
                                <span class="font-bold tabular-nums {{ $s['suggested_price'] > $s['current_price'] ? 'text-emerald-700' : ($s['suggested_price'] < $s['current_price'] ? 'text-rose-700' : 'text-slate-700') }}">PKR {{ number_format($s['suggested_price'], 2) }}</span>
                            @else
                                <span class="text-slate-400 text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if($s['verdict'] !== 'insufficient_data' && $s['suggested_price'] !== $s['current_price'])
                                <form method="POST" action="/admin/pricing/apply/{{ $s['product_id'] }}" class="inline" onsubmit="return confirm('Apply suggested price PKR {{ number_format($s['suggested_price'], 2) }} to {{ $s['name'] }}?')">
                                    @csrf
                                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-1 rounded">Apply</button>
                                </form>
                            @else
                                <span class="text-slate-400 text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">No products to suggest for.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>
