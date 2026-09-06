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
            @elseif($transaction->isNonIntegratedBill())
                {{-- Optional FBR integration (Sep 2026): a plain bill (reporting OFF /
                     converted). No FBR chip, no Submit/Retry — nothing to send. --}}
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300" data-bill-kind="sale">{{ __('pos.bill_no_fbr_word') }}</span>
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
            @if($transaction->isNonIntegratedBill())
            @php $showSimpleQr = \App\Support\QrImage::dataUri($transaction->simpleQrPayload($transaction->company ?? null)); @endphp
            @if($showSimpleQr)
            {{-- Simple details QR — same payload as the printed receipt (Sep 2026). --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 text-center" data-simple-qr="1">
                <img src="{{ $showSimpleQr }}" alt="QR" class="w-28 h-28 mx-auto rounded bg-white p-1">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{ __('pos.receipt_scan_details') }}</p>
            </div>
            @endif
            @endif
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
                    {{-- Task 799: no-rider delivery — show who closed the bill and when --}}
                    @if($transaction->order_type === 'delivery' && !$transaction->rider_id && $transaction->delivered_by && $transaction->deliveredBy)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.del_closed_by_label') }}</span>
                        <span class="text-gray-900 dark:text-white">
                            {{ $transaction->deliveredBy->name }}
                            @if($transaction->delivered_at)
                                <span class="text-xs text-gray-400 dark:text-gray-500">· {{ \Carbon\Carbon::parse($transaction->delivered_at)->format('d M H:i') }}</span>
                            @endif
                        </span>
                    </div>
                    @endif
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
            {{-- Task 1271 (PRA Task 1036 port): share links are FINAL-bill only (server
                 enforces the same in generateShareLink — this just hides a button that
                 would 422). Missing column fails OPEN like the server gate. Shared PDF
                 keeps the FBR invoice number + Tax Asaan QR — never PRA branding. --}}
            @php
                $shareCo = \App\Models\Company::find(app('currentCompanyId'));
                $shareAllowed = !$transaction->isDeliberateProvisional()
                    && (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')
                        || (bool) ($shareCo?->pos_whatsapp_bill_enabled ?? true))
                    && \App\Services\PosFeatureService::planAllows($shareCo, 'whatsapp_enabled');
            @endphp
            @if($shareAllowed)
            <div x-data="shareInvoice({{ $transaction->id }})" class="relative">
                <button @click="toggleMenu()" type="button" class="w-full inline-flex items-center justify-center gap-2 py-2.5 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    {{ __('pos.share_word') }}
                </button>
                <div x-show="open" x-cloak @click.away="open = false" x-transition class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 z-50 overflow-hidden">
                    <div class="py-1">
                        <button @click="shareWhatsApp()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-green-500" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            {{ __('pos.whatsapp_word') }}
                        </button>
                        <button @click="shareEmail()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            {{ __('pos.email_word') }}
                        </button>
                        <button @click="copySmsText()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                            {{ __('pos.sms_text') }}
                        </button>
                        <div class="border-t border-gray-100 dark:border-gray-700"></div>
                        <button @click="copyLink()" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                            <span x-text="copied ? @js(__('pos.link_copied')) : @js(__('pos.copy_link'))"></span>
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
            @endif
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
// Task 1271 (PRA Task 1036 port): share dropdown — link mints lazily on first
// open; shared PDF is the FBR bill (FBR number + Tax Asaan QR).
function shareInvoice(transactionId) {
    return {
        open: false,
        shareUrl: null,
        loading: false,
        copied: false,
        toast: false,
        toastMsg: '',
        invoiceNumber: @js($transaction->fbr_invoice_number ?: $transaction->invoice_number),
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
                const resp = await fetch(`/fbr-pos/transaction/${transactionId}/share-link`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                });
                const data = await resp.json();
                this.shareUrl = data.url;
            } catch (e) {
                this.showToast(@js(__('pos.failed_generate_share_link')));
            }
            this.loading = false;
        },

        shareWhatsApp() {
            if (!this.shareUrl) return;
            const text = `${@js(__('pos.invoice_word'))} ${this.invoiceNumber}\n${@js(__('pos.total_word'))}: PKR ${this.totalAmount}\n${@js(__('pos.view_download_pdf'))}: ${this.shareUrl}`;
            window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
            this.open = false;
        },

        shareEmail() {
            if (!this.shareUrl) return;
            const subject = `${@js(__('pos.invoice_word'))} ${this.invoiceNumber} - PKR ${this.totalAmount}`;
            const body = `${@js(__('pos.email_body_intro'))}\n\n${@js(__('pos.invoice_number_label'))}: ${this.invoiceNumber}\n${@js(__('pos.total_amount_label'))}: PKR ${this.totalAmount}\n\n${@js(__('pos.view_download_pdf'))}: ${this.shareUrl}\n\n${@js(__('pos.thank_you_business'))}`;
            window.open(`mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`);
            this.open = false;
        },

        copySmsText() {
            if (!this.shareUrl) return;
            const text = `${@js(__('pos.invoice_word'))} ${this.invoiceNumber} | PKR ${this.totalAmount} | PDF: ${this.shareUrl}`;
            navigator.clipboard.writeText(text).then(() => {
                this.showToast(@js(__('pos.sms_text_copied')));
            });
            this.open = false;
        },

        copyLink() {
            if (!this.shareUrl) return;
            navigator.clipboard.writeText(this.shareUrl).then(() => {
                this.copied = true;
                this.showToast(@js(__('pos.share_link_copied')));
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
