<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">NestPOS Dashboard</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Point of Sale Overview</p>
        </div>
        <div class="flex items-center gap-3">
            <div x-data="{ praEnabled: {{ $praStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2">
                <span class="text-sm text-gray-600 dark:text-gray-400">PRA Reporting</span>
                <button @click="loading=true; fetch('{{ route('pos.api.toggle-pra') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{praEnabled=d.enabled; loading=false;})" :class="praEnabled ? 'bg-emerald-600' : 'bg-gray-300 dark:bg-gray-600'" class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out" :disabled="loading">
                    <span :class="praEnabled ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5 ml-0.5"></span>
                </button>
                <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold'" class="text-xs"></span>
            </div>
            <a href="{{ route('pos.invoice.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Invoice
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
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Avg Ticket</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($todayStats->avg_ticket ?? 0) }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Monthly Revenue</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($monthStats->revenue ?? 0) }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-1 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Payment Breakdown (Today)</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                        {{ $pb->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                        {{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}
                    </span>
                    <span class="text-xs text-gray-500">{{ $pb->count }} txns</span>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white">PKR {{ number_format($pb->total) }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-400">No transactions today</p>
            @endforelse
        </div>

        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Recent Transactions</h3>
                <a href="{{ route('pos.transactions') }}" class="text-xs text-emerald-600 hover:underline">View All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2 pr-4">Invoice #</th>
                            <th class="pb-2 pr-4">Customer</th>
                            <th class="pb-2 pr-4">Payment</th>
                            <th class="pb-2 pr-4 text-right">Amount</th>
                            <th class="pb-2">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentTransactions as $txn)
                        <tr class="border-b border-gray-50 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="py-2.5 pr-4">
                                <a href="{{ route('pos.transaction.show', $txn->id) }}" class="text-emerald-600 hover:underline font-medium">{{ $txn->invoice_number }}</a>
                            </td>
                            <td class="py-2.5 pr-4 text-gray-700 dark:text-gray-300">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                            <td class="py-2.5 pr-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                    {{ ucwords(str_replace('_', ' ', $txn->payment_method)) }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-4 text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($txn->total_amount) }}</td>
                            <td class="py-2.5 text-gray-500 text-xs">{{ $txn->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">No transactions yet. Create your first POS invoice!</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <a href="{{ route('pos.invoice.create') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-emerald-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center mb-2 group-hover:bg-emerald-100">
                <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">New Invoice</span>
        </a>
        <a href="{{ route('pos.transactions') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-blue-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center mb-2 group-hover:bg-blue-100">
                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Transactions</span>
        </a>
        <a href="{{ route('pos.reports') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-purple-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center mb-2 group-hover:bg-purple-100">
                <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Reports</span>
        </a>
        <a href="{{ route('pos.services') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-amber-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center mb-2 group-hover:bg-amber-100">
                <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Services</span>
        </a>
        <a href="{{ route('pos.products') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-teal-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-teal-50 dark:bg-teal-900/30 flex items-center justify-center mb-2 group-hover:bg-teal-100">
                <svg class="w-5 h-5 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Products</span>
        </a>
        <a href="{{ route('pos.pra-settings') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-red-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-red-50 dark:bg-red-900/30 flex items-center justify-center mb-2 group-hover:bg-red-100">
                <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">PRA Settings</span>
        </a>
    </div>
</div>
</x-pos-layout>