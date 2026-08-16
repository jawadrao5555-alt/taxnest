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

    {{-- Return / credit-note flow (Task 570) --}}
    @php
        $isReturnBill = ($transaction->transaction_type ?? 'sale') === 'return';
        // Task 678: single permission verdict (returnsAllowed) + per-bill
        // stream lock + remaining returnable quantity — BOTH streams.
        $__remainingQty = $transaction->items->sum(fn ($it) => max(0, (float) $it->quantity - (float) ($it->returned_quantity ?? 0)));
        $canReturnHere = \App\Services\PosAccessService::returnsAllowed(auth('pos')->user())
            && $transaction->allowedForBillingScope(auth('pos')->user()?->posBillingScope() ?? 'both')
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type')
            && \App\Http\Controllers\PosReturnController::returnableReason($transaction) === null
            && $__remainingQty > 0;
        $returnRows = $isReturnBill || !\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type')
            ? collect() : $transaction->returns()->withoutGlobalScope('hide_archived')->get();
        // Explicit parent lookup — live has strict lazy loading, so never
        // touch $transaction->parentTransaction as a lazy attribute here.
        $returnParentRow = $isReturnBill && $transaction->parent_transaction_id
            ? \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $transaction->company_id)
                ->find($transaction->parent_transaction_id)
            : null;
    @endphp

    @if($isReturnBill)
    <div class="mb-6 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-rose-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
            <div class="text-sm text-rose-800 dark:text-rose-300">
                <span class="font-bold">{{ __('pos.return_bill_banner') }}</span>
                @if($returnParentRow)
                — {{ __('pos.original_invoice_colon') }}
                <a href="{{ route('pos.transaction.show', $transaction->parent_transaction_id) }}" class="font-mono font-semibold underline">{{ $returnParentRow->invoice_number }}</a>
                @endif
            </div>
        </div>
    </div>
    @elseif($returnRows->isNotEmpty())
    <div class="mb-6 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 rounded-xl p-4">
        <div class="text-sm text-rose-800 dark:text-rose-300">
            <span class="font-bold">{{ __('pos.bill_has_returns') }}:</span>
            @foreach($returnRows as $ret)
                <a href="{{ route('pos.transaction.show', $ret->id) }}" class="font-mono font-semibold underline mr-2">{{ $ret->invoice_number }} (Rs {{ number_format((float) $ret->total_amount) }})</a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transaction->invoice_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $transaction->created_at->format('d M Y H:i:s') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($canReturnHere)
            <a href="{{ route('pos.transaction.return-form', $transaction->id) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-rose-600 text-white text-sm font-semibold rounded-lg hover:bg-rose-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3"/></svg>
                {{ __('pos.return_refund') }}
            </a>
            @endif
            @if(!$transaction->pra_invoice_number)
            <a href="{{ route('pos.transaction.edit', $transaction->id) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500 text-white text-sm font-semibold rounded-lg hover:bg-amber-600 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                {{ __('pos.edit') }}
            </a>
            <form method="POST" action="{{ route('pos.transaction.delete', $transaction->id) }}" onsubmit="return confirm(@js(__('pos.confirm_delete_invoice_full', ['invoice' => $transaction->invoice_number])))">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    {{ __('pos.delete') }}
                </button>
            </form>
            @endif
            <button onclick="openReceiptPopup()" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                {{ __('pos.print') }}
            </button>
            <button onclick="openReceiptPopup()" class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                {{ __('pos.pdf_word') }}
            </button>
            @php
                // Task 1036: share links are FINAL-bill only (server enforces the
                // same in generateShareLink — this just hides a button that would
                // 422). Missing column fails OPEN like the server gate.
                $shareCo = \App\Models\Company::find(app('currentCompanyId'));
                $shareAllowed = !$transaction->isDeliberateProvisional()
                    && (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')
                        || (bool) ($shareCo?->pos_whatsapp_bill_enabled ?? true));
            @endphp
            @if($shareAllowed)
            <div x-data="shareInvoice({{ $transaction->id }})" class="relative">
                <button @click="toggleMenu()" type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">
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
            <a href="{{ route('pos.transactions') }}" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition">{{ __('pos.back_word') }}</a>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.invoice_numbers') }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('pos.pos_invoice_number_usin') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white font-mono">{{ $transaction->invoice_number }}</p>
            </div>
            <div class="rounded-lg p-4 border {{ $transaction->pra_invoice_number ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-700' : 'bg-gray-50 dark:bg-gray-800 border-gray-100 dark:border-gray-700' }}">
                <p class="text-xs font-medium {{ $transaction->pra_invoice_number ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' }} uppercase mb-1">{{ __('pos.pra_fiscal_invoice_number') }}</p>
                @if($transaction->pra_invoice_number)
                    <p class="text-lg font-bold text-emerald-700 dark:text-emerald-300 font-mono">{{ $transaction->pra_invoice_number }}</p>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">{{ __('pos.not_submitted_to_pra') }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.items') }}</h3>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2">{{ __('pos.item_word') }}</th>
                            <th class="pb-2">{{ __('pos.type_word') }}</th>
                            <th class="pb-2 text-right">{{ __('pos.qty') }}</th>
                            <th class="pb-2 text-right">{{ __('pos.price') }}</th>
                            <th class="pb-2 text-right">{{ __('pos.subtotal') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transaction->items as $item)
                        <tr class="border-b border-gray-50 dark:border-gray-800">
                            <td class="py-2.5 text-gray-900 dark:text-white font-medium">
                                {{ $item->item_name }}
                                @if($item->is_tax_exempt)
                                <span class="inline-block ml-1.5 text-[10px] font-bold px-1.5 py-0.5 rounded border border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-600 align-middle">NT</span>
                                @endif
                                {{-- Deal components (frozen snapshot) — indented, informational only --}}
                                @if($item->item_type === 'deal' && is_array($item->deal_snapshot))
                                <div class="mt-1 space-y-0.5">
                                    @foreach($item->deal_snapshot as $comp)
                                    <div class="text-xs text-gray-500 dark:text-gray-400 font-normal pl-3">• {{ (int)($comp['qty'] ?? 1) }}x {{ $comp['name'] ?? __('pos.item_word') }}</div>
                                    @endforeach
                                </div>
                                @endif
                            </td>
                            <td class="py-2.5">
                                <span class="text-xs px-2 py-0.5 rounded {{ $item->item_type === 'deal' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' : ($item->item_type === 'service' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400') }}">{{ ucfirst($item->item_type) }}</span>
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
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.pra_submission_logs') }}</h3>
                @foreach($transaction->praLogs as $log)
                <div class="border border-gray-100 dark:border-gray-800 rounded-lg p-3 mb-2 last:mb-0">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium px-2 py-0.5 rounded {{ $log->status === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : ($log->status === 'pending' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400') }}">{{ strtoupper($log->status) }}</span>
                        <span class="text-xs text-gray-500">{{ $log->created_at->format('d M Y H:i:s') }}</span>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('pos.response_code') }}: {{ $log->response_code ?? 'N/A' }}</p>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.summary_word') }}</h3>
                @php
                    // Tax-Inclusive (Menu-Rate-Final) bills: header subtotal is stored ex-tax
                    // (menu sum − included tax) so the report identity holds. For DISPLAY,
                    // show the menu-price subtotal (matches the item lines) + "incl." tax label.
                    $txInclusive = (bool) ($transaction->tax_inclusive ?? false);
                    // Card-save (mode 3) card/digital bills: "Menu Total" = item sum
                    // + explicit "Card Discount" saving line.
                    $txMenuRate = $txInclusive ? ($transaction->tax_menu_rate ?? null) : null;
                    $txCardSave = $txMenuRate !== null && (float) $txMenuRate > 0
                        && abs((float) $txMenuRate - (float) $transaction->tax_rate) >= 0.005;
                    $txDisplaySubtotal = $txCardSave
                        ? (float) $transaction->items->sum('subtotal')
                        : ($txInclusive
                            ? (float) $transaction->subtotal + (float) $transaction->tax_amount
                            : (float) $transaction->subtotal);
                    $txCardSaving = $txCardSave
                        ? max(0.0, round($txDisplaySubtotal - (float) $transaction->discount_amount - (float) $transaction->total_amount, 2))
                        : 0.0;
                @endphp
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ $txCardSave ? __('pos.menu_total') : __('pos.subtotal') }}</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($txDisplaySubtotal, 2) }}</span>
                    </div>
                    @if($transaction->discount_amount > 0)
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.discount_word') }} ({{ $transaction->discount_type === 'percentage' ? $transaction->discount_value . '%' : __('pos.fixed_word') }})</span>
                        <span class="text-red-600">-PKR {{ number_format($transaction->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    @if($txCardSave && $txCardSaving > 0.009)
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.card_discount') }}</span>
                        <span class="text-red-600">-PKR {{ number_format($txCardSaving, 2) }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.tax_word') }} ({{ $transaction->tax_rate }}%{{ $txInclusive ? ' ' . __('pos.incl_suffix') : '' }})</span>
                        <span class="text-gray-900 dark:text-white">PKR {{ number_format($transaction->tax_amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between pt-3 border-t border-gray-200 dark:border-gray-700">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.total_word') }}</span>
                        <span class="font-bold text-lg text-emerald-600">PKR {{ number_format($transaction->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.details_word') }}</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.payment') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</span>
                    </div>
                    @php
                        $__showTbl = $transaction->restaurantOrder?->table ?? null;
                        $__showTableLabel = $__showTbl
                            ? (($__showTbl->floor?->name ? $__showTbl->floor->name . ' · ' : '') . 'T-' . $__showTbl->table_number)
                            : null;
                    @endphp
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.customer_word') }}</span>
                        {{-- Task 791/792: dine-in bill with no customer → "Dine-in", not "Walk-in"; show table label when available --}}
                        <span class="text-gray-900 dark:text-white">
                            @if($transaction->customer_name)
                                {{ $transaction->customer_name }}
                                @if($transaction->order_type === 'dine_in' && $__showTableLabel)
                                    <span class="text-xs text-gray-400 dark:text-gray-500">· {{ $__showTableLabel }}</span>
                                @endif
                            @elseif($transaction->order_type === 'dine_in')
                                {{ __('pos.dine_in') }}@if($__showTableLabel) <span class="text-xs text-gray-400 dark:text-gray-500">· {{ $__showTableLabel }}</span>@endif
                            @else
                                {{ __('pos.walk_in') }}
                            @endif
                        </span>
                    </div>
                    @if($transaction->order_type && in_array($transaction->order_type, ['dine_in', 'takeaway', 'delivery'], true))
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">{{ __('pos.order_type') }}</span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wide
                            {{ $transaction->order_type === 'dine_in' ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300' : ($transaction->order_type === 'delivery' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300') }}">
                            {{ __('pos.ot_' . $transaction->order_type) }}
                        </span>
                    </div>
                    @endif
                    @if($transaction->customer_phone)
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.phone_word') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->customer_phone }}</span>
                    </div>
                    @endif
                    @if($transaction->terminal)
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.terminal_word') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $transaction->terminal->terminal_name }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        {{-- Return bills (Task 678): explicit "Processed by" label so the
                             owner can audit WHO made each refund. --}}
                        <span class="text-gray-500">{{ $isReturnBill ? __('pos.return_processed_by') : __('pos.created_by') }}</span>
                        <span class="{{ $isReturnBill ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white' }}">{{ $transaction->creator->name ?? 'N/A' }}</span>
                    </div>
                    {{-- Task 799: no-rider delivery — show who closed the bill and when --}}
                    @if($transaction->order_type === 'delivery' && !$transaction->rider_id && $transaction->delivered_by && $transaction->deliveredBy)
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.del_closed_by_label') }}</span>
                        <span class="text-gray-900 dark:text-white">
                            {{ $transaction->deliveredBy->name }}
                            @if($transaction->delivered_at)
                                <span class="text-xs text-gray-400 dark:text-gray-500">· {{ \Carbon\Carbon::parse($transaction->delivered_at)->format('d M H:i') }}</span>
                            @endif
                        </span>
                    </div>
                    @endif
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">{{ __('pos.pra_status') }}</span>
                        @if($transaction->pra_status === 'submitted')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">{{ __('pos.submitted_word') }}</span>
                        @elseif($transaction->pra_status === 'failed')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">{{ __('pos.failed_word') }}</span>
                        @elseif($transaction->pra_status === 'pending')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">{{ __('pos.pending_word') }}</span>
                        @elseif($transaction->pra_status === 'offline')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">{{ __('pos.offline') }}</span>
                        @elseif($transaction->pra_status === 'local')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">{{ __('pos.local_word') }}</span>
                        @elseif($transaction->pra_status === \App\Models\PosTransaction::EXEMPT_INTERNAL)
                            {{-- Task 818: all-exempt bill — same EXEMPT badge as the list view --}}
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400 uppercase">{{ __('pos.exempt_badge') }}</span>
                        @else
                            <span class="text-gray-400">{{ __('pos.local_only') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            @if($transaction->pra_status === 'local')
            <div class="bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-200 dark:border-purple-700 p-5">
                <h3 class="text-sm font-semibold text-purple-700 dark:text-purple-300 mb-2">{{ __('pos.provisional_bill') }}</h3>
                <p class="text-xs text-purple-600 dark:text-purple-400 mb-3">{{ __('pos.provisional_bill_desc') }}</p>
                @php
                    $company = \App\Models\Company::find(app('currentCompanyId'));
                @endphp
                @if($company && auth('pos')->user()?->praReportingEnabled($company))
                <form method="POST" action="{{ route('pos.transaction.retry-pra', $transaction->id) }}" onsubmit="return confirm(@js(__('pos.confirm_submit_final_pra')));">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition shadow-md shadow-purple-600/20">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        {{ __('pos.submit_to_pra_make_final') }}
                    </button>
                </form>
                @else
                <p class="text-[11px] text-purple-500/80 dark:text-purple-400/70 italic">{{ __('pos.pra_reporting_disabled_note') }}</p>
                @endif
            </div>
            @elseif($transaction->pra_status === 'offline'
                && (!$isReturnBill || !auth('pos')->user()->posCashierBlocked()))
            {{-- Task 582: return rows are manager+ only — cashiers see the bill, no sync box. --}}
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-xl border border-orange-200 dark:border-orange-700 p-5">
                <h3 class="text-sm font-semibold text-orange-800 dark:text-orange-300 mb-2">{{ __('pos.offline_pending_sync') }}</h3>
                <p class="text-xs text-orange-700 dark:text-orange-400 mb-3">{{ __('pos.offline_pending_sync_desc') }}</p>
                <form method="POST" action="{{ route('pos.transaction.retry-pra', $transaction->id) }}">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('pos.sync_to_pra_now') }}
                    </button>
                </form>
            </div>
            @elseif(!$transaction->pra_invoice_number && in_array($transaction->pra_status, ['pending', 'failed'])
                && (!$isReturnBill || !auth('pos')->user()->posCashierBlocked()))
            {{-- Task 582: return rows are manager+ only — cashiers see the bill, no retry box. --}}
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-700 p-5">
                <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">{{ __('pos.pra_retry_available') }}</h3>
                <p class="text-xs text-amber-700 dark:text-amber-400 mb-3">{{ __('pos.pra_retry_available_desc') }}</p>
                <form method="POST" action="{{ route('pos.transaction.retry-pra', $transaction->id) }}">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('pos.retry_pra_submission') }}
                    </button>
                </form>
            </div>
            @elseif($transaction->pra_status === \App\Models\PosTransaction::EXEMPT_INTERNAL
                && !$transaction->pra_invoice_number
                && !$isReturnBill
                && auth('pos')->user()?->canRequeueExemptPra())
            {{-- Task 818: owner self-serve re-queue of an exempt_internal bill from the
                 detail page too — same route/confirm/flash as the transactions-list
                 (exempt tab) button added in Task 808. Owner/admin only. --}}
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-700 p-5">
                <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">{{ __('pos.exempt_word') }} — {{ __('pos.not_submitted_to_pra') }}</h3>
                <p class="text-xs text-amber-700 dark:text-amber-400 mb-3">{{ __('pos.receipt_exempt_clarifier') }}</p>
                <form method="POST" action="{{ route('pos.transaction.requeue-exempt', $transaction->id) }}"
                      onsubmit="return confirm(@js(__('pos.confirm_requeue_exempt', ['invoice' => $transaction->invoice_number])))">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('pos.requeue_exempt_btn') }}
                    </button>
                </form>
            </div>
            @endif

            @if($transaction->pra_invoice_number)
            <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-200 dark:border-emerald-700 p-5">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <h3 class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">{{ __('pos.pra_verified') }}</h3>
                </div>
                <p class="text-xs text-emerald-700 dark:text-emerald-400 mb-3">{{ __('pos.pra_verified_desc') }}</p>
                @php
                    $praQr = $transaction->pra_invoice_number
                        ? \App\Support\QrImage::dataUri($transaction->pra_invoice_number)
                        : ($transaction->pra_qr_code ?: '');
                @endphp
                @if($praQr)
                <div class="flex flex-col items-center pt-3 border-t border-emerald-200 dark:border-emerald-700">
                    <img src="{{ $praQr }}" alt="{{ __('pos.alt_pra_verification_qr') }}" class="w-32 h-32 mb-2">
                    <p class="text-[11px] text-emerald-700 dark:text-emerald-400 font-medium mb-1">{{ __('pos.scan_with_pra_sahulat') }}</p>
                    <a href="https://reg.pra.punjab.gov.pk/IMSFiscalReport/SearchPOSInvoice_Report.aspx?PRAInvNo={{ urlencode($transaction->pra_invoice_number) }}" target="_blank" class="text-xs text-emerald-600 hover:underline">{{ __('pos.verify_on_pra_portal') }}</a>
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
</script>

<div id="receiptPopup" style="display:none;" class="fixed inset-0 z-[60] flex items-center justify-center transition-opacity duration-300 opacity-0">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeReceiptPopup()"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-6xl mx-4 h-[90vh] flex flex-col overflow-hidden" style="max-height: 90vh;">
        <button onclick="closeReceiptPopup()" class="absolute top-4 right-4 z-10 p-2 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition text-gray-500 hover:text-gray-700 dark:text-gray-400">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full bg-purple-100">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <span id="receiptPopupBadge" class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-purple-100 text-purple-800">{{ __('pos.invoice_receipt') }}</span>
                    </div>
                </div>
                <div class="sm:ml-auto text-right">
                    <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ $transaction->invoice_number }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">PKR {{ number_format($transaction->total_amount, 2) }}@if($transaction->pra_invoice_number) | PRA: {{ $transaction->pra_invoice_number }}@endif</p>
                </div>
            </div>
        </div>
        <div class="flex-1 overflow-hidden p-4 min-h-0">
            <iframe id="posPdfIframe" src="" class="w-full h-full border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-800"></iframe>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex-shrink-0 bg-gray-50 dark:bg-gray-900">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                <button onclick="printPosPdf()" class="inline-flex items-center justify-center px-5 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    {{ __('pos.print') }}
                </button>
                <button onclick="downloadPosPdf()" class="inline-flex items-center justify-center px-5 py-2.5 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 transition">
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
    document.getElementById('posPdfIframe').src = '{{ route('pos.receipt', $transaction->id) }}';
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
        document.getElementById('posPdfIframe').src = '';
    }, 250);
    if (!skipHistory) { try { history.back(); } catch(e) {} }
}
window.addEventListener('popstate', function(e) {
    const modal = document.getElementById('receiptPopup');
    if (modal && modal.style.display === 'flex') {
        closeReceiptPopup(true);
    }
});
function printPosPdf() {
    try {
        const iframe = document.getElementById('posPdfIframe');
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    } catch (e) {
        const printWin = document.createElement('iframe');
        printWin.style.display = 'none';
        printWin.src = '{{ route('pos.receipt', $transaction->id) }}';
        document.body.appendChild(printWin);
        printWin.onload = function() {
            printWin.contentWindow.focus();
            printWin.contentWindow.print();
            setTimeout(() => document.body.removeChild(printWin), 1000);
        };
    }
}
function downloadPosPdf() {
    const a = document.createElement('a');
    a.href = '{{ route('pos.invoice.pdf', $transaction->id) }}';
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
@if(session('success') && str_contains(session('success'), 'Invoice Created Successfully'))
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('receiptPopupBadge').textContent = @js(__('pos.invoice_created_successfully'));
    openReceiptPopup();
});
@endif
</script>
</x-pos-layout>
