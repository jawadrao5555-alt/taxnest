<x-pos-layout>
<div class="p-4 sm:p-6 max-w-6xl mx-auto">
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
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $t->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $t->invoice_number }}</td>
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
        {{ __('pos.history_match_note') }}
    </div>
</div>
</x-pos-layout>
