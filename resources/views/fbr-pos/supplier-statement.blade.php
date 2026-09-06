<x-fbr-pos-layout>
{{-- Distributor statement (Task 1580): every purchase, payment, return and
     claim credit for one supplier with a running balance. Numbers come from
     SupplierLedgerService only — the page never sums anything itself. --}}
@php
    $kindLabels = [
        'purchase' => __('pos.sl_kind_purchase'),
        'payment' => __('pos.sl_kind_payment'),
        'return' => __('pos.sl_kind_return'),
        'claim' => __('pos.sl_kind_claim'),
    ];
    $methodLabels = [
        'cash' => __('pos.sl_method_cash'),
        'bank' => __('pos.sl_method_bank'),
        'online' => __('pos.sl_method_online'),
        'cheque' => __('pos.sl_method_cheque'),
    ];
    $qs = array_filter(['from' => $from, 'to' => $to]);
@endphp
<div class="max-w-6xl mx-auto" x-data="{ payOpen: false, voidId: 0 }">
    <a href="{{ route('fbrpos.stock') }}#suppliers" class="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline mb-3">← {{ __('pos.stock_page_title') }}</a>

    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">{{ $supplier->name }}
                @unless($supplier->is_active)<span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300 align-middle">{{ __('pos.stock_sup_inactive') }}</span>@endunless
                <x-new-badge feature="fbr_supplier_ledger" />
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.sl_statement_title') }}{{ $supplier->phone ? ' · ' . $supplier->phone : '' }}{{ $supplier->city ? ' · ' . $supplier->city : '' }}@if(($multiBranch ?? false)) · {{ $activeBranchName ?? __('pos.branch_all') }}@endif</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($supplier->is_active)
            <button type="button" @click="payOpen = true" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-sm font-bold hover:bg-purple-700">{{ __('pos.sl_pay_btn') }}</button>
            <a href="{{ route('fbrpos.stock.returns', ['supplier_id' => $supplier->id]) }}" class="px-4 py-2 rounded-xl border-2 border-gray-200 dark:border-gray-600 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">↩ {{ __('pos.sl_return_new_btn') }}</a>
            @endif
            @if($waUrl)
            <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="px-4 py-2 rounded-xl bg-green-600 text-white text-sm font-bold hover:bg-green-700 inline-flex items-center gap-1.5">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.1-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5c.2-.2.2-.3.3-.5.1-.2 0-.4 0-.5-.1-.1-.7-1.6-.9-2.2-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.2-.3-.3-.6-.4zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2z"/></svg>
                {{ __('pos.sl_wa_btn') }}
            </a>
            @endif
            <a href="{{ route('fbrpos.stock.supplier.statement.pdf', array_merge(['id' => $supplier->id], $qs)) }}" class="px-3 py-2 rounded-xl border-2 border-gray-200 dark:border-gray-600 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">PDF</a>
            <a href="{{ route('fbrpos.stock.supplier.statement', array_merge(['id' => $supplier->id, 'export' => 'csv'], $qs)) }}" class="px-3 py-2 rounded-xl border-2 border-gray-200 dark:border-gray-600 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">Excel/CSV</a>
        </div>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ $errors->first() }}</div>@endif

    @include('fbr-pos.partials.branch-bar')

    {{-- Balance tiles (all-time picture, independent of the date filter) --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.sl_col_billed') }}</p>
            <p class="text-xl font-extrabold text-gray-900 dark:text-white mt-1">Rs {{ number_format($balance->billed, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.sl_col_paid') }}</p>
            <p class="text-xl font-extrabold text-green-700 dark:text-green-400 mt-1">Rs {{ number_format($balance->paid, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.sl_col_returns') }}</p>
            <p class="text-xl font-extrabold text-blue-700 dark:text-blue-400 mt-1">Rs {{ number_format($balance->returned, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.sl_col_claim_credits') }}</p>
            <p class="text-xl font-extrabold text-blue-700 dark:text-blue-400 mt-1">Rs {{ number_format($balance->credited, 0) }}</p>
        </div>
        <div class="col-span-2 lg:col-span-1 bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 {{ $balance->balance > 0.004 ? 'border-amber-500' : ($balance->balance < -0.004 ? 'border-green-500' : 'border-gray-200 dark:border-gray-700') }}">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ $balance->balance < -0.004 ? __('pos.sl_advance') : __('pos.sl_baqaya') }}</p>
            <p class="text-xl font-extrabold mt-1 {{ $balance->balance > 0.004 ? 'text-amber-700 dark:text-amber-400' : 'text-gray-900 dark:text-white' }}">Rs {{ number_format(abs($balance->balance), 2) }}</p>
        </div>
    </div>

    {{-- Period filter --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_from') }}</label>
            <input type="date" name="from" value="{{ $from }}" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
        </div>
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_to') }}</label>
            <input type="date" name="to" value="{{ $to }}" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg bg-gray-800 dark:bg-gray-600 text-white text-sm font-bold hover:bg-gray-900">{{ __('pos.sl_filter_btn') }}</button>
        @if($from || $to)
        <a href="{{ route('fbrpos.stock.supplier.statement', $supplier->id) }}" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('pos.stock_corr_clear_filters') }}</a>
        @endif
        <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">
            {{ __('pos.sl_period_summary', [
                'billed' => number_format($statement['period']['billed'], 0),
                'paid' => number_format($statement['period']['paid'], 0),
                'credit' => number_format($statement['period']['returned'] + $statement['period']['credited'], 0),
            ]) }}
        </span>
    </form>

    {{-- Statement table --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden mb-6">
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-2">{{ __('pos.sl_col_date') }}</th>
                    <th class="px-4 py-2">{{ __('pos.sl_col_type') }}</th>
                    <th class="px-4 py-2">{{ __('pos.sl_col_ref') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('pos.sl_col_debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('pos.sl_col_credit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('pos.sl_col_balance') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-t dark:border-gray-700 bg-gray-50/60 dark:bg-gray-700/30">
                    <td class="px-4 py-2 text-gray-500" colspan="5">{{ __('pos.sl_opening_balance') }}</td>
                    <td class="px-4 py-2 text-right font-bold text-gray-900 dark:text-white">{{ number_format($statement['opening'], 2) }}</td>
                    <td></td>
                </tr>
                @forelse($statement['rows'] as $r)
                <tr class="border-t dark:border-gray-700 {{ $r['void'] ? 'opacity-60' : '' }}">
                    <td data-label="{{ __('pos.sl_col_date') }}" class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M Y') }}</td>
                    <td data-label="{{ __('pos.sl_col_type') }}" class="px-4 py-2">
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-bold
                            {{ $r['kind'] === 'purchase' ? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' : '' }}
                            {{ $r['kind'] === 'payment' ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' : '' }}
                            {{ in_array($r['kind'], ['return', 'claim']) ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : '' }}">{{ $kindLabels[$r['kind']] ?? $r['kind'] }}</span>
                        @if($r['void'])<span class="ml-1 inline-block px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400">{{ __('pos.sl_void_tag') }}</span>@endif
                    </td>
                    <td data-label="{{ __('pos.sl_col_ref') }}" class="px-4 py-2 text-gray-700 dark:text-gray-200">
                        @if($r['kind'] === 'payment')
                            <span class="font-semibold">{{ $methodLabels[$r['ref']] ?? $r['ref'] }}</span>
                        @elseif($r['kind'] === 'return')
                            <a href="{{ route('fbrpos.stock.return.print', $r['id']) }}" class="font-semibold text-blue-600 dark:text-blue-400 hover:underline">{{ $r['ref'] }}</a>
                        @else
                            <span class="font-semibold">{{ $r['ref'] }}</span>
                        @endif
                        @if($r['detail'] !== '')<span class="text-xs text-gray-400 ml-1">{{ $r['detail'] }}</span>@endif
                    </td>
                    <td data-label="{{ __('pos.sl_col_debit') }}" class="px-4 py-2 text-right {{ $r['void'] ? 'line-through' : '' }}">{{ $r['debit'] > 0 ? number_format($r['debit'], 2) : '' }}</td>
                    <td data-label="{{ __('pos.sl_col_credit') }}" class="px-4 py-2 text-right text-green-700 dark:text-green-400 {{ $r['void'] ? 'line-through' : '' }}">{{ $r['credit'] > 0 ? number_format($r['credit'], 2) : '' }}</td>
                    <td data-label="{{ __('pos.sl_col_balance') }}" class="px-4 py-2 text-right font-bold text-gray-900 dark:text-white">{{ number_format($r['balance'], 2) }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        @if($r['kind'] === 'payment' && !$r['void'])
                        <form method="POST" action="{{ route('fbrpos.stock.payment.void', $r['id']) }}" onsubmit="return confirm(@js(__('pos.sl_payment_void_confirm')))">
                            @csrf
                            <input type="hidden" name="return_to" value="statement">
                            <button type="submit" class="px-2.5 py-1 rounded-lg border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 text-xs font-bold hover:bg-red-50 dark:hover:bg-red-900/20">{{ __('pos.sl_payment_void_btn') }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr class="border-t dark:border-gray-700"><td colspan="7" class="px-4 py-6 text-center text-gray-400">{{ __('pos.sl_no_rows') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/40 font-bold">
                    <td class="px-4 py-2" colspan="3">{{ __('pos.sl_closing_balance') }}</td>
                    <td class="px-4 py-2 text-right">{{ number_format($statement['period']['billed'], 2) }}</td>
                    <td class="px-4 py-2 text-right text-green-700 dark:text-green-400">{{ number_format($statement['period']['paid'] + $statement['period']['returned'] + $statement['period']['credited'], 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-900 dark:text-white">{{ number_format($statement['closing'], 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="text-xs text-gray-400 mb-6">{{ __('pos.sl_statement_note') }}</p>

    @if($supplier->is_active)
    {{-- Payment modal — same form as the stock page, plus "against bill". --}}
    <div x-show="payOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="payOpen = false">
        <div class="absolute inset-0 bg-black/50" @click="payOpen = false"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <form method="POST" action="{{ route('fbrpos.stock.payment.store') }}">
                @csrf
                <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
                <input type="hidden" name="return_to" value="statement">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="font-bold text-gray-900 dark:text-white truncate">{{ __('pos.sl_pay_modal_title') }}</h3>
                        <p class="text-xs text-gray-500 truncate">{{ $supplier->name }}</p>
                    </div>
                    <button type="button" @click="payOpen = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none px-1">&times;</button>
                </div>
                <div class="px-5 py-4 space-y-3 text-sm">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_pay_amount_lbl') }}</label>
                        <input type="number" name="amount" value="{{ $balance->balance > 0 ? number_format($balance->balance, 2, '.', '') : '' }}" step="0.01" min="0.01" required
                               class="w-full border rounded-lg px-3 py-2 text-lg font-bold dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_pay_method_lbl') }}</label>
                            <select name="method" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                                @foreach($methodLabels as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_pay_date_lbl') }}</label>
                            <input type="date" name="paid_on" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        </div>
                    </div>
                    @if($bills->isNotEmpty())
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_against_bill_lbl') }}</label>
                        <select name="purchase_order_id" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            <option value="">{{ __('pos.sl_against_bill_none') }}</option>
                            @foreach($bills as $b)
                            <option value="{{ $b->id }}">{{ $b->po_number }}{{ $b->supplier_invoice_no ? ' · #' . $b->supplier_invoice_no : '' }} · Rs {{ number_format($b->total_amount, 0) }} · {{ ($b->received_date ?? $b->created_at)?->format('d M Y') }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                    <input type="text" name="reference" maxlength="64" placeholder="{{ __('pos.sl_paid_ref_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    <input type="text" name="notes" maxlength="500" placeholder="{{ __('pos.stock_note_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    @if($multiBranch ?? false)
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_branch_lbl') }}</label>
                        <select name="branch_id" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ (int) ($activeBranchId ?? 0) === (int) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                </div>
                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700 flex gap-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white font-bold hover:bg-purple-700">{{ __('pos.sl_pay_save_btn') }}</button>
                    <button type="button" @click="payOpen = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('pos.stock_sup_cancel') }}</button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
</x-fbr-pos-layout>
