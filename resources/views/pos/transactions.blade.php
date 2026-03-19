<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">POS Transactions</h1>
        <div class="flex items-center gap-3">
            @php
                $failedCount = \App\Models\PosTransaction::where('company_id', app('currentCompanyId'))
                    ->whereIn('pra_status', ['failed', 'offline', 'pending'])
                    ->whereNull('pra_invoice_number')
                    ->count();
            @endphp
            @if($failedCount > 0)
            <form method="POST" action="{{ route('pos.transactions.bulk-retry-pra') }}" onsubmit="return confirm('Retry PRA submission for {{ $failedCount }} invoice(s)?')">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-orange-600 text-white text-sm font-medium rounded-lg hover:bg-orange-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Sync All ({{ $failedCount }})
                </button>
            </form>
            @endif
            <a href="{{ route('pos.invoice.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Invoice
            </a>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search invoice # or customer..." class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <select name="payment_method" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                <option value="">All Payment Methods</option>
                <option value="cash" {{ request('payment_method') === 'cash' ? 'selected' : '' }}>Cash</option>
                <option value="debit_card" {{ request('payment_method') === 'debit_card' ? 'selected' : '' }}>Debit Card</option>
                <option value="credit_card" {{ request('payment_method') === 'credit_card' ? 'selected' : '' }}>Credit Card</option>
                <option value="qr_payment" {{ request('payment_method') === 'qr_payment' ? 'selected' : '' }}>QR / Raast</option>
            </select>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition">Filter</button>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                        <th class="px-4 py-3">Invoice #</th>
                        <th class="px-4 py-3 hidden md:table-cell">Customer</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Payment</th>
                        <th class="px-4 py-3 text-right hidden lg:table-cell">Subtotal</th>
                        <th class="px-4 py-3 text-right hidden lg:table-cell">Tax</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 hidden lg:table-cell">PRA Fiscal #</th>
                        <th class="px-4 py-3 hidden sm:table-cell">PRA Status</th>
                        <th class="px-4 py-3 hidden md:table-cell">Date</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $txn)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 font-medium text-emerald-600">
                            <a href="{{ route('pos.transaction.show', $txn->id) }}" class="hover:underline">{{ $txn->invoice_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300 hidden md:table-cell">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $txn->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ ucwords(str_replace('_', ' ', $txn->payment_method)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 hidden lg:table-cell">{{ number_format($txn->subtotal) }}</td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 hidden lg:table-cell">{{ number_format($txn->tax_amount) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($txn->total_amount) }}</td>
                        <td class="px-4 py-3 hidden lg:table-cell">
                            @if($txn->pra_invoice_number)
                                <span class="text-xs font-mono text-purple-700 dark:text-purple-400">{{ $txn->pra_invoice_number }}</span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            @if($txn->pra_status === 'submitted')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">Submitted</span>
                            @elseif($txn->pra_status === 'failed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">Failed</span>
                            @elseif($txn->pra_status === 'pending')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">Pending</span>
                            @elseif($txn->pra_status === 'offline')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400">Offline</span>
                            @elseif($txn->pra_status === 'local')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Local</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Local</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs hidden md:table-cell">{{ $txn->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('pos.receipt', $txn->id) }}" class="text-emerald-600 hover:underline text-xs font-medium">Receipt</a>
                                @if(in_array($txn->pra_status, ['failed', 'offline', 'pending']) && !$txn->pra_invoice_number)
                                <form method="POST" action="{{ route('pos.transaction.retry-pra', $txn->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-orange-600 hover:text-orange-700 text-xs font-medium" title="Retry PRA">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="px-4 py-12 text-center text-gray-400">No transactions found</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($transactions->hasPages())
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $transactions->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
</x-pos-layout>