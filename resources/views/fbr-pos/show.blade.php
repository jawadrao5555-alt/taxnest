<x-fbr-pos-layout>
<div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transaction->invoice_number }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $transaction->created_at->format('d M Y h:i A') }}</p>
        </div>
        <div class="flex items-center gap-3">
            @if($transaction->fbr_status === 'submitted')
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ __('pos.fbr_submitted') }}</span>
            @elseif($transaction->invoice_mode === 'local')
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('pos.local_invoice') }}</span>
            @elseif($transaction->fbr_status === 'failed')
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ __('pos.fbr_failed') }}</span>
                {{-- ✏️ Edit & Retry only available for terminal-failed bills (not pending/in-flight) --}}
                <a href="{{ route('fbrpos.editFailed', $transaction->id) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-600 text-white text-xs font-bold rounded-lg hover:bg-amber-700 transition shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    ✏️ {{ __('pos.edit_and_retry') }}
                </a>
                <form method="POST" action="{{ route('fbrpos.retryFbr', $transaction->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('pos.retry_as_is') }}
                    </button>
                </form>
            @else
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">FBR {{ ucfirst(str_replace('_', ' ', $transaction->fbr_status ?? 'Pending')) }}</span>
                {{-- Edit hidden during pending/in-flight to avoid colliding with auto-retry job --}}
                <form method="POST" action="{{ route('fbrpos.retryFbr', $transaction->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('pos.submit_to_fbr') }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('pos.items') }}</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.th_item') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden sm:table-cell">{{ __('pos.th_hs_code') }}</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden sm:table-cell">{{ __('pos.th_uom') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.th_qty') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.th_price') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden sm:table-cell">{{ __('pos.th_tax') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.th_total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @php
                                $fmtQty = function($q) {
                                    $f = (float) $q;
                                    return $f == (int) $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
                                };
                            @endphp
                            @foreach($transaction->items as $item)
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                    {{ $item->item_name }}
                                    @if($item->is_tax_exempt)
                                    <span class="ml-1 text-xs text-amber-600">({{ __('pos.exempt') }})</span>
                                    @endif
                                    @if(($item->item_discount ?? 0) > 0)
                                    <span class="ml-1 text-xs text-red-600">−PKR {{ number_format($item->item_discount, 2) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 hidden sm:table-cell">{{ $item->hs_code ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-center text-gray-500 dark:text-gray-400 hidden sm:table-cell">{{ $item->uom ?? 'U' }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-300">{{ $fmtQty($item->quantity) }}</td>
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
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('pos.fbr_submission_logs') }}</h3>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($transaction->fbrLogs()->latest()->get() as $log)
                    <div class="px-5 py-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                @if($log->status === 'success')
                                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                    <span class="text-sm font-medium text-green-600">{{ __('pos.success_word') }}</span>
                                @elseif($log->status === 'pending')
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    <span class="text-sm font-medium text-amber-600">{{ __('pos.pending_word') }}</span>
                                @else
                                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                                    <span class="text-sm font-medium text-red-600">{{ __('pos.failed_word') }}</span>
                                @endif
                                @if($log->response_code)
                                    <span class="text-xs text-gray-400">({{ __('pos.code_colon') }} {{ $log->response_code }})</span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $log->created_at->format('d M Y h:i A') }}</span>
                        </div>
                        @if($log->error_message)
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $log->error_message }}</p>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-3">{{ __('pos.summary_word') }}</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.subtotal') }}</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->subtotal, 2) }}</span>
                    </div>
                    @if($transaction->discount_amount > 0)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.discount_word') }}</span>
                        <span class="text-red-600">-PKR {{ number_format($transaction->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.th_tax') }}</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->tax_amount, 2) }}</span>
                    </div>
                    @if($transaction->fbr_service_charge > 0)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.fbr_pos_fee') }}</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->fbr_service_charge, 2) }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-200 dark:border-gray-700">
                        <span class="text-gray-900 dark:text-white">{{ __('pos.th_total') }}</span>
                        <span class="text-blue-600">PKR {{ number_format($transaction->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-3">{{ __('pos.details_word') }}</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.customer_word') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_name ?? __('pos.walk_in') }}</span>
                    </div>
                    @if($transaction->customer_phone)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.phone_label') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_phone }}</span>
                    </div>
                    @endif
                    @if($transaction->customer_ntn)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.ntn_label') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_ntn }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.tax_period') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->created_at->format('F Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.payment') }}</span>
                        <span class="text-gray-900 dark:text-white capitalize">{{ str_replace('_', ' ', $transaction->payment_method) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.created_by') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->creator->name ?? '—' }}</span>
                    </div>
                    @if($transaction->fbr_invoice_number)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.fbr_invoice') }}</span>
                        <span class="text-blue-600 font-medium">{{ $transaction->fbr_invoice_number }}</span>
                    </div>
                    @endif
                    @if($transaction->fbr_response_code)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.fbr_code') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->fbr_response_code }}</span>
                    </div>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button onclick="openReceiptPopup()" class="flex-1 text-center py-2.5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition text-sm cursor-pointer">
                    {{ __('pos.print_receipt') }}
                </button>
                <button onclick="openReceiptPopup()" class="flex-1 text-center py-2.5 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition text-sm cursor-pointer">
                    {{ __('pos.download_pdf') }}
                </button>
            </div>
            {{-- Return entry (owner request 14 Aug 2026): completed sale bills
                 only, inside the 15-din window the server also enforces. --}}
            @if(($transaction->transaction_type ?? 'sale') !== 'return'
                && ($transaction->status ?? 'completed') === 'completed'
                && $transaction->created_at->gte(now()->subDays(\App\Http\Controllers\FbrPosPhase2Controller::RETURN_WINDOW_DAYS)))
            <a href="{{ route('fbrpos.phase2.return.form', $transaction->id) }}" class="block w-full text-center py-2.5 bg-rose-600 text-white font-semibold rounded-lg hover:bg-rose-700 transition text-sm">
                {{ __('pos.return_refund') }}
            </a>
            @endif
            <a href="{{ route('fbrpos.transactions') }}" class="block w-full text-center py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-200 dark:hover:bg-gray-700 transition text-sm">
                {{ __('pos.back_to_transactions') }}
            </a>
        </div>
    </div>
</div>

<div id="receiptPopup" style="display:none;" class="fixed inset-0 z-[60] flex items-center justify-center transition-opacity duration-300 opacity-0">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeReceiptPopup()"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-6xl mx-4 h-[90vh] flex flex-col overflow-hidden" style="max-height: 90vh;">
        <button onclick="closeReceiptPopup()" class="absolute top-4 right-4 z-10 p-2 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition text-gray-500 hover:text-gray-700 dark:text-gray-400">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full bg-blue-100">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <span id="receiptPopupBadge" class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-800">{{ __('pos.invoice_receipt') }}</span>
                    </div>
                </div>
                <div class="sm:ml-auto text-right">
                    <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ $transaction->invoice_number }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">PKR {{ number_format($transaction->total_amount, 2) }}@if($transaction->fbr_invoice_number) | FBR: {{ $transaction->fbr_invoice_number }}@endif</p>
                </div>
            </div>
        </div>
        <div class="flex-1 overflow-hidden p-4 min-h-0">
            <iframe id="fbrPosPdfIframe" src="" class="w-full h-full border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-800" ></iframe>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex-shrink-0 bg-gray-50 dark:bg-gray-900">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                <button onclick="printFbrPosPdf()" class="inline-flex items-center justify-center px-5 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    {{ __('pos.print') }}
                </button>
                <button onclick="downloadFbrPosPdf()" class="inline-flex items-center justify-center px-5 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ __('pos.download_pdf') }}
                </button>
                <button onclick="closeReceiptPopup()" class="inline-flex items-center justify-center px-5 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition sm:ml-auto">
                    {{ __('pos.close') }}
                </button>
            </div>
        </div>
    </div>
</div>
<script>
function openReceiptPopup() {
    const modal = document.getElementById('receiptPopup');
    document.getElementById('fbrPosPdfIframe').src = '{{ route('fbrpos.receipt', $transaction->id) }}';
    document.getElementById('receiptPopupBadge').textContent = @js(__('pos.invoice_receipt'));
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    history.pushState({ receiptPopup: true }, '');
    requestAnimationFrame(() => { modal.classList.remove('opacity-0'); modal.classList.add('opacity-100'); });
}
function closeReceiptPopup(skipHistory) {
    const modal = document.getElementById('receiptPopup');
    if (modal.style.display === 'none') return;
    modal.classList.remove('opacity-100');
    modal.classList.add('opacity-0');
    setTimeout(() => {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        document.getElementById('fbrPosPdfIframe').src = '';
    }, 250);
    if (!skipHistory) { try { history.back(); } catch(e) {} }
}
window.addEventListener('popstate', function(e) {
    const modal = document.getElementById('receiptPopup');
    if (modal && modal.style.display === 'flex') {
        closeReceiptPopup(true);
    }
});
function printFbrPosPdf() {
    try {
        const iframe = document.getElementById('fbrPosPdfIframe');
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    } catch (e) {
        const printWin = document.createElement('iframe');
        printWin.style.display = 'none';
        printWin.src = '{{ route('fbrpos.receipt', $transaction->id) }}';
        document.body.appendChild(printWin);
        printWin.onload = function() {
            printWin.contentWindow.focus();
            printWin.contentWindow.print();
            setTimeout(() => document.body.removeChild(printWin), 1000);
        };
    }
}
function downloadFbrPosPdf() {
    const a = document.createElement('a');
    a.href = '{{ route('fbrpos.pdf', $transaction->id) }}';
    a.download = '';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => document.body.removeChild(a), 100);
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('receiptPopup').style.display === 'flex') {
        closeReceiptPopup();
    }
});
@if(session('success') && (str_contains(session('success'), 'created') || str_contains(session('success'), 'Created')))
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('receiptPopupBadge').textContent = @js(__('pos.invoice_created_successfully'));
    openReceiptPopup();
});
@endif
</script>
</x-fbr-pos-layout>
