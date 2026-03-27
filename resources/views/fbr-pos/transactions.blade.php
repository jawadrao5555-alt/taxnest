<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Transactions</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $tab === 'local' ? 'Local (Offline) Sales' : 'FBR POS Sales History' }}</p>
        </div>
        <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Sale
        </a>
    </div>

    @include('fbr-pos.partials.mode-tabs', ['tab' => $tab, 'hasPinSet' => $hasPinSet, 'localCount' => $localCount, 'baseUrl' => route('fbrpos.transactions')])

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">Total</p>
            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $stats->total ?? 0 }}</p>
        </div>
        @if($tab === 'fbr')
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">Submitted</p>
            <p class="text-lg font-bold text-green-600">{{ $stats->submitted ?? 0 }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">Pending</p>
            <p class="text-lg font-bold text-amber-600">{{ $stats->pending ?? 0 }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">Failed</p>
            <p class="text-lg font-bold text-red-600">{{ $stats->failed ?? 0 }}</p>
        </div>
        @else
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center sm:col-span-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">Local Revenue</p>
            <p class="text-lg font-bold text-amber-600">PKR {{ number_format($localRevenue ?? 0) }}</p>
        </div>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md mb-4 p-4">
        <form method="GET" action="{{ route('fbrpos.transactions') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search invoice, customer..."
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
            @if($tab === 'fbr')
            <select name="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Status</option>
                <option value="submitted" {{ request('status') === 'submitted' ? 'selected' : '' }}>Submitted</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
            @else
            <div></div>
            @endif
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
            <div class="flex gap-2">
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                    class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Filter</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoice #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden sm:table-cell">Customer</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden md:table-cell">Payment</th>
                        @if($tab === 'fbr')
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">FBR</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden lg:table-cell">FBR Invoice</th>
                        @else
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Mode</th>
                        @endif
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden md:table-cell">Date</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($transactions as $txn)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                        <td class="px-4 py-3 text-sm font-medium {{ $tab === 'local' ? 'text-amber-600' : 'text-blue-600' }}">{{ $txn->invoice_number }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 hidden sm:table-cell">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                        <td class="px-4 py-3 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($txn->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-center hidden md:table-cell">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ str_replace('_', ' ', $txn->payment_method) }}</span>
                        </td>
                        @if($tab === 'fbr')
                        <td class="px-4 py-3 text-center">
                            @if($txn->fbr_status === 'submitted')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Submitted</span>
                            @elseif($txn->fbr_status === 'failed')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">Failed</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 hidden lg:table-cell">{{ $txn->fbr_invoice_number ?? '—' }}</td>
                        @else
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Local</span>
                        </td>
                        @endif
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ $txn->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('fbrpos.show', $txn->id) }}" class="text-blue-600 hover:text-blue-700 text-sm font-medium">View</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $tab === 'fbr' ? 8 : 7 }}" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">
                            {{ $tab === 'local' ? 'No local transactions found.' : 'No transactions found.' }}
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($transactions->hasPages())
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $transactions->links() }}
        </div>
        @endif
    </div>
</div>

@include('fbr-pos.partials.pin-modal')
</x-fbr-pos-layout>
