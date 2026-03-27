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
        <div class="flex flex-wrap gap-2">
            @if(!$transaction->pra_invoice_number)
            <a href="{{ route('pos.transaction.edit', $transaction->id) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500 text-white text-sm font-semibold rounded-lg hover:bg-amber-600 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Edit
            </a>
            <form method="POST" action="{{ route('pos.transaction.delete', $transaction->id) }}" onsubmit="return confirm('Are you sure you want to delete invoice {{ $transaction->invoice_number }}? This action cannot be undone.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete
                </button>
            </form>
            @endif
            <a href="{{ route('pos.receipt', $transaction->id) }}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print
            </a>
            <a href="{{ route('pos.invoice.pdf', $transaction->id) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                PDF
            </a>
            <div x-data="shareInvoice({{ $transaction->id }})" class="relative">
                <button @click="toggleMenu()" type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    Share
                </button>
                <div x-show="open" x-cloak @click.away="open = false" x-transition class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 z-50 overflow-hidden">
                    <div class="py-1">
                        <button @click="shareWhatsApp()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-green-500" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            WhatsApp
                        </button>
                        <button @click="shareEmail()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            Email
                        </button>
                        <button @click="copySmsText()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                            SMS / Text
                        </button>
                        <div class="border-t border-gray-100 dark:border-gray-700"></div>
                        <button @click="copyLink()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                            <span x-text="copied ? 'Link Copied!' : 'Copy Link'"></span>
                        </button>
                    </div>
                </div>
                <template x-if="toast">
                    <div x-transition class="fixed bottom-6 right-6 z-[200] bg-gray-900 text-white text-sm font-medium px-5 py-3 rounded-xl shadow-2xl flex items-center gap-2">
                        <svg class="w-4 h-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span x-text="toastMsg"></span>
                    </div>
                </template>
            </div>
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

<script>
function shareInvoice(transactionId) {
    return {
        open: false,
        shareUrl: null,
        loading: false,
        copied: false,
        toast: false,
        toastMsg: '',
        invoiceNumber: '{{ $transaction->invoice_number }}',
        totalAmount: '{{ number_format($transaction->total_amount, 2) }}',

        toggleMenu() {
            this.open = !this.open;
            if (this.open && !this.shareUrl) {
                this.getShareLink();
            }
        },

        async getShareLink() {
            if (this.shareUrl || this.loading) return;
            this.loading = true;
            try {
                const resp = await fetch(`/pos/transaction/${transactionId}/share-link`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                });
                const data = await resp.json();
                this.shareUrl = data.url;
            } catch (e) {
                this.showToast('Failed to generate share link');
            }
            this.loading = false;
        },

        shareWhatsApp() {
            if (!this.shareUrl) return;
            const text = `Invoice ${this.invoiceNumber}\nTotal: PKR ${this.totalAmount}\nView/Download PDF: ${this.shareUrl}`;
            window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
            this.open = false;
        },

        shareEmail() {
            if (!this.shareUrl) return;
            const subject = `Invoice ${this.invoiceNumber} - PKR ${this.totalAmount}`;
            const body = `Please find your invoice details below:\n\nInvoice Number: ${this.invoiceNumber}\nTotal Amount: PKR ${this.totalAmount}\n\nView/Download PDF: ${this.shareUrl}\n\nThank you for your business!`;
            window.open(`mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`);
            this.open = false;
        },

        copySmsText() {
            if (!this.shareUrl) return;
            const text = `Invoice ${this.invoiceNumber} | PKR ${this.totalAmount} | PDF: ${this.shareUrl}`;
            navigator.clipboard.writeText(text).then(() => {
                this.showToast('SMS text copied to clipboard');
            });
            this.open = false;
        },

        copyLink() {
            if (!this.shareUrl) return;
            navigator.clipboard.writeText(this.shareUrl).then(() => {
                this.copied = true;
                this.showToast('Share link copied!');
                setTimeout(() => { this.copied = false; }, 2000);
            });
            this.open = false;
        },

        showToast(msg) {
            this.toastMsg = msg;
            this.toast = true;
            setTimeout(() => { this.toast = false; }, 3000);
        }
    };
}
</script>

@if(session('success') && str_contains(session('success'), 'Invoice Created Successfully'))
<div id="printPopup" class="fixed inset-0 z-[60] flex items-center justify-center transition-opacity duration-300">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closePrintPopup()"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-5xl mx-4 h-[85vh] flex flex-col overflow-hidden">
        <button onclick="closePrintPopup()" class="absolute top-4 right-4 z-10 p-2 rounded-full bg-gray-100 hover:bg-gray-200 transition text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div class="px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Invoice Created Successfully!</h3>
                    <p class="text-sm text-gray-500">{{ $transaction->invoice_number }} — PKR {{ number_format($transaction->total_amount, 2) }}</p>
                </div>
            </div>
        </div>
        <div class="flex-1 overflow-hidden p-4 min-h-0">
            <iframe src="{{ route('pos.receipt', $transaction->id) }}" class="w-full h-full border border-gray-200 rounded-lg bg-white"></iframe>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex-shrink-0 bg-gray-50">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                <a href="{{ route('pos.receipt', $transaction->id) }}" target="_blank" class="inline-flex items-center justify-center px-5 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print Receipt
                </a>
                <a href="{{ route('pos.invoice.pdf', $transaction->id) }}" class="inline-flex items-center justify-center px-5 py-2.5 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download PDF
                </a>
                <button onclick="closePrintPopup()" class="inline-flex items-center justify-center px-5 py-2.5 bg-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-300 transition sm:ml-auto">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
<script>
function closePrintPopup() {
    document.getElementById('printPopup').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePrintPopup();
});
</script>
@endif
</x-pos-layout>
