<x-admin-layout>
    <x-slot name="header"><h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">🛡️ {{ $title }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto px-4">
        @include('admin.reports._filters', ['action' => '/admin/reports/fbr'])

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-blue-100 dark:bg-blue-900/30 border-l-4 border-blue-600 rounded-lg p-4">
                <p class="text-xs font-bold uppercase text-blue-700 dark:text-blue-300">Total Bills</p>
                <p class="text-2xl font-extrabold text-blue-900 dark:text-blue-100">{{ $totals['total_count'] }}</p>
            </div>
            <div class="bg-emerald-100 dark:bg-emerald-900/30 border-l-4 border-emerald-600 rounded-lg p-4">
                <p class="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300">Submitted</p>
                <p class="text-2xl font-extrabold text-emerald-900 dark:text-emerald-100">{{ $totals['submitted_count'] }}</p>
            </div>
            <div class="bg-rose-100 dark:bg-rose-900/30 border-l-4 border-rose-600 rounded-lg p-4">
                <p class="text-xs font-bold uppercase text-rose-700 dark:text-rose-300">Failed / Pending</p>
                <p class="text-2xl font-extrabold text-rose-900 dark:text-rose-100">{{ $totals['failed_count'] }}</p>
            </div>
            <div class="bg-amber-100 dark:bg-amber-900/30 border-l-4 border-amber-600 rounded-lg p-4">
                <p class="text-xs font-bold uppercase text-amber-700 dark:text-amber-300">Compliance %</p>
                <p class="text-2xl font-extrabold text-amber-900 dark:text-amber-100">{{ $totals['compliance_pct'] }}%</p>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-lg">Status breakdown</h3>
                @include('admin.reports._export-buttons', ['route' => '/admin/reports/fbr'])
            </div>
            <table class="w-full text-sm table-cards">
                <thead class="text-xs uppercase font-bold text-slate-700 dark:text-slate-300 bg-gray-100 dark:bg-gray-700">
                    <tr><th class="px-3 py-2 text-left">FBR Status</th><th class="px-3 py-2 text-right">Invoices</th><th class="px-3 py-2 text-right">Total (PKR)</th></tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-2"><span class="px-2 py-1 text-xs font-bold rounded {{ $r->fbr_status === 'submitted' ? 'bg-emerald-100 text-emerald-800' : ($r->fbr_status === 'failed' ? 'bg-rose-100 text-rose-800' : 'bg-gray-100 text-gray-800') }}">{{ $r->fbr_status }}</span></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r->cnt }}</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums">{{ number_format($r->total, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="px-3 py-6 text-center text-slate-500">No data in selected range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>
