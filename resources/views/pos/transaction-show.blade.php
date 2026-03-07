<x-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    @if(session('success'))
    <div class="mb-6 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="text-sm text-emerald-800 dark:text-emerald-300 font-medium">{{ session('success') }}</div>
        </div>
    </div>
    @endif

    @if(session('error'))
    <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-red-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="text-sm text-red-800 dark:text-red-300 font-medium">{{ session('error') }}</div>
        </div>
    </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transaction->invoice_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $transaction->created_at->format('d M Y H:i:s') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pos.receipt', $transaction->id) }}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print Receipt
            </a>
            <a href="{{ route('pos.transactions') }}" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition">Back</a>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Invoice Numbers</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">POS Invoice Number (USIN)</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white font-mono">{{ $transaction->invoice_number }}</p>
            </div>
            <div class="rounded-lg p-4 border {{ $transaction->pra_invoice_number ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-700' : 'bg-gray-50 dark:bg-gray-800 border-gray-100 dark:border-gray-700' }}">
                <p class="text-xs font-medium {{ $transaction->pra_invoice_number ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' }} uppercase mb-1">PRA Fiscal Invoice Number</p>
                @if($transaction->pra_invoice_number)
                    <p class="text-lg font-bold text-emerald-700 dark:text-emerald-300 font-mono">{{ $transaction->pra_invoice_number }}</p>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">Not submitted to PRA</p>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Items</h3>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2">Item</th>
                            <th class="pb-2">Type</th>
                            <th class="pb-2 text-right">Qty</th>
                            <th class="pb-2 text-right">Price</th>
                            <th class="pb-2 text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transaction->items as $item)
                        <tr class="border-b border-gray-50 dark:border-gray-800">
                            <td class="py-2.5 text-gray-900 dark:text-white font-medium">{{ $item->item_name }}</td>
                            <td class="py-2.5">
                                <span class="text-xs px-2 py-0.5 rounded {{ $item->item_type === 'service' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' }}">{{ ucfirst($item->item_type) }}</span>
                            </td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $item->quantity }}</td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ number_format($item->unit_price, 2) }}</td>
                            <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ number_format($item->subtotal, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($transaction->praLogs->isNotEmpty())
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">PRA Submission Logs</h3>
                @foreach($transaction->praLogs as $log)
                <div class="border border-gray-100 dark:border-gray-800 rounded-lg p-3 mb-2 last:mb-0">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium px-2 py-0.5 rounded {{ $log->status === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : ($log->status === 'pending' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400') }}">{{ strtoupper($log->status) }}</span>
                        <span class="text-xs text-gray-500">{{ $log->created_at->format('d M Y H:i:s') }}</span>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400">Response Code: {{ $log->response_code ?? 'N/A' }}</p>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Summary</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Subtotal</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->subtotal, 2) }}</span>
                    </div>
                    @if($transaction->discount_amount > 0)
                    <div class="flex justify-between">
                        <span class="text-gray-500">Discount ({{ $transaction->discount_type === 'percentage' ? $transaction->discount_value . '%' : 'Fixed' }})</span>
                        <span class="text-red-600">-PKR {{ number_format($transaction->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500">Tax ({{ $transaction->tax_rate }}%)</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->tax_amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between pt-3 border-t border-gray-200 dark:border-gray-700">
                        <span class="font-semibold text-gray-900 dark:text-white">Total</span>
                        <span class="font-bold text-lg text-emerald-600">PKR {{ number_format($transaction->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Details</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Payment</span>
                        <span class="text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Customer</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_name ?? 'Walk-in' }}</span>
                    </div>
                    @if($transaction->customer_phone)
                    <div class="flex justify-between">
                        <span class="text-gray-500">Phone</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_phone }}</span>
                    </div>
                    @endif
                    @if($transaction->terminal)
                    <div class="flex justify-between">
                        <span class="text-gray-500">Terminal</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->terminal->terminal_name }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500">Created By</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->creator->name ?? 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">PRA Status</span>
                        @if($transaction->pra_status === 'submitted')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">Submitted</span>
                        @elseif($transaction->pra_status === 'failed')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">Failed</span>
                        @elseif($transaction->pra_status === 'pending')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">Pending</span>
                        @elseif($transaction->pra_status === 'offline')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">Offline</span>
                        @elseif($transaction->pra_status === 'local')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Local</span>
                        @else
                            <span class="text-gray-400">Local Only</span>
                        @endif
                    </div>
                </div>
            </div>

            @if($transaction->pra_status === 'local')
            <div class="bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Local Invoice</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">This invoice was created while PRA reporting was off. It will not be synced to PRA.</p>
            </div>
            @elseif($transaction->pra_status === 'offline')
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-xl border border-orange-200 dark:border-orange-700 p-5">
                <h3 class="text-sm font-semibold text-orange-800 dark:text-orange-300 mb-2">Offline — Pending Sync</h3>
                <p class="text-xs text-orange-700 dark:text-orange-400 mb-3">This invoice was saved offline and will sync to PRA automatically when connection is restored.</p>
                <form method="POST" action="{{ route('pos.transaction.retry-pra', $transaction->id) }}">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Sync to PRA Now
                    </button>
                </form>
            </div>
            @elseif(!$transaction->pra_invoice_number && in_array($transaction->pra_status, ['pending', 'failed']))
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-700 p-5">
                <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">PRA Retry Available</h3>
                <p class="text-xs text-amber-700 dark:text-amber-400 mb-3">This invoice has not been reported to PRA. You can retry the submission.</p>
                <form method="POST" action="{{ route('pos.transaction.retry-pra', $transaction->id) }}">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Retry PRA Submission
                    </button>
                </form>
            </div>
            @endif

            @if($transaction->pra_invoice_number)
            <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-200 dark:border-emerald-700 p-5">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <h3 class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">PRA Verified</h3>
                </div>
                <p class="text-xs text-emerald-700 dark:text-emerald-400 mb-3">This invoice has been successfully reported to PRA and cannot be resubmitted.</p>
                @if($transaction->pra_qr_code)
                <div class="flex flex-col items-center pt-3 border-t border-emerald-200 dark:border-emerald-700">
                    <img src="{{ $transaction->pra_qr_code }}" alt="PRA Verification QR" class="w-32 h-32 mb-2">
                    <a href="https://reg.pra.punjab.gov.pk/IMSFiscalReport/SearchPOSInvoice_Report.aspx?PRAInvNo={{ urlencode($transaction->pra_invoice_number) }}" target="_blank" class="text-xs text-emerald-600 hover:underline">Verify on PRA Portal</a>
                </div>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>
</x-pos-layout>
