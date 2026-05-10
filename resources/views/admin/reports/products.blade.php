<x-admin-layout>
    <x-slot name="header"><h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">📦 {{ $title }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto px-4">
        @include('admin.reports._filters', ['action' => '/admin/reports/products'])

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-lg">Product performance ({{ count($rows) }} unique items)</h3>
                @include('admin.reports._export-buttons', ['route' => '/admin/reports/products'])
            </div>
            <table class="w-full text-sm table-cards">
                <thead class="text-xs uppercase font-bold text-slate-700 dark:text-slate-300 bg-gray-100 dark:bg-gray-700">
                    <tr>@foreach($headers as $h)<th class="px-3 py-2 {{ $loop->first ? 'text-left' : 'text-right' }}">{{ $h }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-2 font-semibold">{{ $r->item_name }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r->sold_in_invoices }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float)$r->units_sold, 4, '.', ''), '0'), '.') ?: '0' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r->revenue, 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r->tax, 2) }}</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums">{{ number_format($r->gross, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">No items sold in selected range.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="border-t-2 border-gray-300 dark:border-gray-600 bg-emerald-50 dark:bg-emerald-900/20 font-extrabold">
                    <tr>
                        <td class="px-3 py-2">TOTALS</td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float)$totals['units_sold'], 4, '.', ''), '0'), '.') ?: '0' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($totals['revenue'], 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($totals['tax'], 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">PKR {{ number_format($totals['gross'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</x-admin-layout>
