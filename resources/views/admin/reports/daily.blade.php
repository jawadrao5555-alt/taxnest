<x-admin-layout>
    <x-slot name="header"><h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">📅 {{ $title }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto px-4">
        @include('admin.reports._filters', ['action' => '/admin/reports/daily'])

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-lg">Day-by-day breakdown</h3>
                @include('admin.reports._export-buttons', ['route' => '/admin/reports/daily'])
            </div>
            <table class="w-full text-sm">
                <thead class="text-xs uppercase font-bold text-slate-700 dark:text-slate-300 bg-gray-100 dark:bg-gray-700">
                    <tr>@foreach($headers as $h)<th class="px-3 py-2 {{ $loop->first ? 'text-left' : 'text-right' }}">{{ $h }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-2 font-bold">{{ $r->sale_date }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r->invoice_count }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r->subtotal, 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r->tax, 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r->discount, 2) }}</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums">{{ number_format($r->total, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">No data in selected range.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="border-t-2 border-gray-300 dark:border-gray-600 bg-emerald-50 dark:bg-emerald-900/20 font-extrabold">
                    <tr>
                        <td class="px-3 py-2">TOTALS</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $totals['invoice_count'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($totals['subtotal'], 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($totals['tax'], 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($totals['discount'], 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">PKR {{ number_format($totals['total'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</x-admin-layout>
