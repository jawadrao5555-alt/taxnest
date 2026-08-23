<x-pos-layout>
{{-- Bill quick-view (owner request, 23 Aug 2026): saving a customer's history
     is only worth something if the shop can open a row and see what was
     actually ordered. Rows that are spend RECORDS of deleted bills stay
     unclickable — there are no items left to show. --}}
<div class="p-4 sm:p-6 max-w-6xl mx-auto" x-data="historyBills()">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <a href="{{ route('pos.customers') }}" class="text-xs text-purple-600 hover:text-purple-700">← {{ __('pos.back_to_customers') }}</a>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white mt-1">
                {{ $customer->name }}
                {{-- Task 1161: khamosh-repeat chip — same PosRepeatCustomerAlert service as the dashboard card. --}}
                @if(!empty($inactiveInfo))
                <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300 align-middle whitespace-nowrap" title="{{ __('pos.inactive_orders_count', ['count' => $inactiveInfo['orders']]) }}">{{ __('pos.inactive_chip', ['days' => $inactiveInfo['days']]) }}</span>
                @endif
            </h1>
            <p class="text-sm text-gray-500">
                {{ $customer->phone ?: __('pos.no_phone') }}
                @if($customer->city) · {{ $customer->city }} @endif
                · <span class="capitalize">{{ $customer->type }}</span>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('pos.customers.history.export', $customer->id) }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md transition">{{ __('pos.download_csv') }}</a>
            <a href="{{ route('pos.customers.history.pdf', $customer->id) }}" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md transition">{{ __('pos.download_pdf') }}</a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.total_orders') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($totalOrders) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.total_spent') }}</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">PKR {{ number_format($totalSpent, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.avg_order') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($avgOrder, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.last_order') }}</p>
            <p class="text-sm font-bold text-gray-900 dark:text-white mt-2">{{ $lastOrder ? $lastOrder->created_at->format('d M Y') : '—' }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">{{ __('pos.receipt_date') }}</th>
                        <th class="px-4 py-3">{{ __('pos.invoice_no_col') }}</th>
                        <th class="px-4 py-3 text-center hidden sm:table-cell">{{ __('pos.mode_col') }}</th>
                        <th class="px-4 py-3 hidden md:table-cell">{{ __('pos.payment') }}</th>
                        <th class="px-4 py-3 text-right hidden sm:table-cell">{{ __('pos.receipt_tax') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.total_word') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($transactions as $t)
                    @php $openable = !($t->is_spend_snapshot ?? false) && !empty($t->id); @endphp
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }} {{ $openable ? 'cursor-pointer hover:bg-purple-50 dark:hover:bg-purple-900/20' : '' }}"
                        @if($openable) role="button" tabindex="0" @click="openBill({{ (int) $t->id }})" @keydown.enter.prevent="openBill({{ (int) $t->id }})" title="{{ __('pos.view_details') }}" @else title="{{ __('pos.bill_record_only_hint') }}" @endif>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $t->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3 font-medium {{ $openable ? 'text-purple-700 dark:text-purple-300 underline decoration-dotted underline-offset-2' : 'text-gray-900 dark:text-white' }}">{{ $t->invoice_number }}</td>
                        <td class="px-4 py-3 text-center hidden sm:table-cell">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $t->isLocalBill() ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ $t->isLocalBill() ? (($t->is_spend_snapshot ?? false) ? __('pos.local_record') : __('pos.local_word')) : __('pos.pra_word') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 hidden md:table-cell">{{ ucwords(str_replace('_', ' ', (string) $t->payment_method)) }}</td>
                        <td class="px-4 py-3 text-right text-gray-500 hidden sm:table-cell">{{ number_format($t->tax_amount, 0) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($t->total_amount, 0) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-500">{{ __('pos.no_purchase_history') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 text-xs text-gray-400 text-center">
        {{ __('pos.click_row_for_items') }}<br>
        {{ __('pos.history_match_note') }}
    </div>

    {{-- Bill items modal --}}
    <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6" style="background: rgba(0,0,0,.55)" @click.self="close()" @keydown.escape.window="close()">
        <div class="bg-white dark:bg-gray-900 w-full max-w-2xl max-h-[85vh] rounded-2xl shadow-2xl flex flex-col overflow-hidden">
            <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-gray-200 dark:border-gray-800">
                <div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('pos.bill_detail_title') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        <span class="font-semibold" x-text="bill.invoice || ''"></span>
                        <template x-if="bill.date"><span> · <span x-text="bill.date"></span></span></template>
                        <template x-if="bill.payment"><span> · <span x-text="bill.payment"></span></span></template>
                    </p>
                </div>
                <button type="button" @click="close()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none px-1">&times;</button>
            </div>

            <div class="px-5 py-4 overflow-y-auto">
                <template x-if="loading">
                    <p class="text-sm text-gray-500 py-6 text-center">{{ __('pos.loading_dots') }}</p>
                </template>
                <template x-if="!loading && error">
                    <p class="text-sm text-rose-600 py-6 text-center" x-text="error"></p>
                </template>
                <template x-if="!loading && !error">
                    <div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-800">
                                    <th class="py-2">{{ __('pos.item_word') }}</th>
                                    <th class="py-2 text-center w-16">{{ __('pos.qty_word') }}</th>
                                    <th class="py-2 text-right w-24">{{ __('pos.rate_word') }}</th>
                                    <th class="py-2 text-right w-28">{{ __('pos.amount_word') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="(it, i) in bill.items" :key="i">
                                    <tr>
                                        <td class="py-2 pr-2 text-gray-900 dark:text-white">
                                            <span x-text="it.name"></span>
                                            <template x-if="it.notes">
                                                <span class="block text-[11px] text-gray-400" x-text="it.notes"></span>
                                            </template>
                                        </td>
                                        <td class="py-2 text-center text-gray-700 dark:text-gray-300" x-text="fmtQty(it.qty)"></td>
                                        <td class="py-2 text-right text-gray-500" x-text="fmt(it.price)"></td>
                                        <td class="py-2 text-right font-semibold text-gray-900 dark:text-white" x-text="fmt(it.total)"></td>
                                    </tr>
                                </template>
                                <template x-if="!bill.items.length">
                                    <tr><td colspan="4" class="py-6 text-center text-gray-500">{{ __('pos.no_items') }}</td></tr>
                                </template>
                            </tbody>
                        </table>

                        <div class="mt-4 border-t border-gray-200 dark:border-gray-800 pt-3 space-y-1 text-sm">
                            <div class="flex justify-between text-gray-500"><span>{{ __('pos.subtotal') }}</span><span x-text="fmt(bill.subtotal)"></span></div>
                            <template x-if="bill.discount > 0">
                                <div class="flex justify-between text-gray-500"><span>{{ __('pos.discount_word') }}</span><span x-text="'-' + fmt(bill.discount)"></span></div>
                            </template>
                            <template x-if="bill.tax > 0">
                                <div class="flex justify-between text-gray-500"><span>{{ __('pos.tax_word') }}</span><span x-text="fmt(bill.tax)"></span></div>
                            </template>
                            <div class="flex justify-between text-base font-bold text-gray-900 dark:text-white pt-1"><span>{{ __('pos.total_word') }}</span><span x-text="'PKR ' + fmt(bill.total)"></span></div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="px-5 py-3 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-2">
                <template x-if="bill.url">
                    <a :href="bill.url" class="px-4 py-2 text-sm font-semibold text-white bg-purple-600 hover:bg-purple-700 rounded-lg">{{ __('pos.open_full_bill') }}</a>
                </template>
                <button type="button" @click="close()" class="px-4 py-2 text-sm font-semibold text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.close') }}</button>
            </div>
        </div>
    </div>

    <script>
    function historyBills() {
        return {
            open: false,
            loading: false,
            error: '',
            bill: { invoice: '', date: '', payment: '', items: [], subtotal: 0, discount: 0, tax: 0, total: 0, url: '' },
            fmt(n) { return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); },
            fmtQty(n) { var v = Number(n || 0); return v % 1 === 0 ? String(v) : String(v); },
            close() { this.open = false; },
            async openBill(id) {
                this.open = true;
                this.loading = true;
                this.error = '';
                this.bill = { invoice: '', date: '', payment: '', items: [], subtotal: 0, discount: 0, tax: 0, total: 0, url: '' };
                try {
                    // Relative URL on purpose (absolute https breaks plain-http dev).
                    const r = await fetch(@json(route('pos.customers.history.bill', ['id' => 'ID'], false)).replace('ID', id), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!r.ok) { this.error = @json(__('pos.failed_word')); this.loading = false; return; }
                    const data = await r.json();
                    if (!data || !data.success) { this.error = @json(__('pos.failed_word')); this.loading = false; return; }
                    this.bill = data;
                } catch (e) {
                    this.error = @json(__('pos.network_error'));
                }
                this.loading = false;
            },
        };
    }
    </script>
</div>
</x-pos-layout>
