<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">FBR POS Dashboard</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">FBR Point of Sale Overview</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $company->fbr_pos_environment === 'production' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' }}">
                {{ strtoupper($company->fbr_pos_environment ?? 'sandbox') }}
            </span>
            <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Sale
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Today's Sales</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($todayStats->revenue ?? 0) }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center">
                    <span class="text-sm font-bold text-emerald-600">PKR</span>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Today's Transactions</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $todayStats->count ?? 0 }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">FBR Submitted</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ $fbrSubmitted }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-green-50 dark:bg-green-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Pending FBR</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ $fbrPending }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Monthly Revenue</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">PKR {{ number_format($monthStats->revenue ?? 0) }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ $monthStats->count ?? 0 }} transactions</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Monthly Tax Collected</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">PKR {{ number_format($monthStats->tax ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Today's Tax</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">PKR {{ number_format($todayStats->tax ?? 0) }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 dark:text-white">Recent Transactions</h3>
            <a href="{{ route('fbrpos.transactions') }}" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoice #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Customer</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">FBR Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($recentTransactions as $txn)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                        <td class="px-4 py-3 text-sm font-medium text-emerald-600">
                            <a href="{{ route('fbrpos.show', $txn->id) }}">{{ $txn->invoice_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                        <td class="px-4 py-3 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($txn->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($txn->fbr_status === 'submitted')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Submitted</span>
                            @elseif($txn->fbr_status === 'failed')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">Failed</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $txn->created_at->format('d M Y h:i A') }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">No transactions yet. Create your first sale!</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</x-fbr-pos-layout>
