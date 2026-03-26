<x-fbr-pos-layout>
<div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transaction->invoice_number }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $transaction->created_at->format('d M Y h:i A') }}</p>
        </div>
        <div class="flex items-center gap-3">
            @if($transaction->fbr_status === 'submitted')
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">FBR Submitted</span>
            @elseif($transaction->fbr_status === 'failed')
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">FBR Failed</span>
            @else
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">FBR Pending</span>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Items</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Item</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden sm:table-cell">HS Code</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Qty</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Price</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden sm:table-cell">Tax</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($transaction->items as $item)
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                    {{ $item->item_name }}
                                    @if($item->is_tax_exempt)
                                    <span class="ml-1 text-xs text-amber-600">(Exempt)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 hidden sm:table-cell">{{ $item->hs_code ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-300">{{ $item->quantity }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($item->unit_price, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-500 dark:text-gray-400 hidden sm:table-cell">{{ $item->tax_rate }}%</td>
                                <td class="px-4 py-3 text-sm text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($item->total, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if($transaction->fbrLogs->count() > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-white">FBR Submission Logs</h3>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($transaction->fbrLogs as $log)
                    <div class="px-5 py-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium {{ $log->status === 'success' ? 'text-green-600' : 'text-red-600' }}">{{ ucfirst($log->status) }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $log->created_at->format('d M Y h:i A') }}</span>
                        </div>
                        @if($log->error_message)
                        <p class="text-xs text-red-600 mt-1">{{ $log->error_message }}</p>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Summary</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->subtotal, 2) }}</span>
                    </div>
                    @if($transaction->discount_amount > 0)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Discount</span>
                        <span class="text-red-600">-PKR {{ number_format($transaction->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Tax</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->tax_amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-200 dark:border-gray-700">
                        <span class="text-gray-900 dark:text-white">Total</span>
                        <span class="text-emerald-600">PKR {{ number_format($transaction->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Details</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Customer</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_name ?? 'Walk-in' }}</span>
                    </div>
                    @if($transaction->customer_phone)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Phone</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_phone }}</span>
                    </div>
                    @endif
                    @if($transaction->customer_ntn)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">NTN</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_ntn }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Payment</span>
                        <span class="text-gray-900 dark:text-white capitalize">{{ str_replace('_', ' ', $transaction->payment_method) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Created By</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->creator->name ?? '—' }}</span>
                    </div>
                    @if($transaction->fbr_invoice_number)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">FBR Invoice</span>
                        <span class="text-emerald-600 font-medium">{{ $transaction->fbr_invoice_number }}</span>
                    </div>
                    @endif
                </div>
            </div>

            <a href="{{ route('fbrpos.transactions') }}" class="block w-full text-center py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-200 dark:hover:bg-gray-700 transition text-sm">
                Back to Transactions
            </a>
        </div>
    </div>
</div>
</x-fbr-pos-layout>
