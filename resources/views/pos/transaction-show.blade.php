<x-app-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transaction->invoice_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $transaction->created_at->format('d M Y H:i:s') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pos.receipt', $transaction->id) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print Receipt
            </a>
            <a href="{{ route('pos.transactions') }}" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition">Back</a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
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
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">PRA Logs</h3>
                @foreach($transaction->praLogs as $log)
                <div class="border border-gray-100 dark:border-gray-800 rounded-lg p-3 mb-2 last:mb-0">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium {{ $log->status === 'success' ? 'text-emerald-600' : 'text-red-600' }}">{{ strtoupper($log->status) }}</span>
                        <span class="text-xs text-gray-500">{{ $log->created_at->format('d M Y H:i:s') }}</span>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400">Response Code: {{ $log->response_code }}</p>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
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

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
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
                    <div class="flex justify-between">
                        <span class="text-gray-500">PRA Status</span>
                        @if($transaction->pra_status === 'reported')
                            <span class="text-emerald-600 font-medium">Reported</span>
                        @elseif($transaction->pra_status === 'failed')
                            <span class="text-red-600 font-medium">Failed</span>
                        @else
                            <span class="text-gray-400">Local Only</span>
                        @endif
                    </div>
                    @if($transaction->pra_invoice_number)
                    <div class="flex justify-between">
                        <span class="text-gray-500">PRA Invoice #</span>
                        <span class="text-gray-900 dark:text-white font-mono text-xs">{{ $transaction->pra_invoice_number }}</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
