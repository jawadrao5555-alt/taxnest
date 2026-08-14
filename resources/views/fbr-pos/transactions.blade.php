<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    @include('fbr-pos.partials.back-link')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.transactions') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $tab === 'local' ? __('pos.local_offline_sales') : __('pos.fbr_pos_sales_history') }}</p>
        </div>
        <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('pos.new_sale') }}
        </a>
    </div>

    @include('fbr-pos.partials.mode-tabs', ['tab' => $tab, 'hasPinSet' => $hasPinSet, 'localCount' => $localCount, 'baseUrl' => route('fbrpos.transactions')])

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.total_word') }}</p>
            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $stats->total ?? 0 }}</p>
        </div>
        @if($tab === 'fbr')
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.submitted_word') }}</p>
            <p class="text-lg font-bold text-green-600">{{ $stats->submitted ?? 0 }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.pending_word') }}</p>
            <p class="text-lg font-bold text-amber-600">{{ $stats->pending ?? 0 }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.failed_word') }}</p>
            <p class="text-lg font-bold text-red-600">{{ $stats->failed ?? 0 }}</p>
        </div>
        @else
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center sm:col-span-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.local_revenue') }}</p>
            <p class="text-lg font-bold text-amber-600">PKR {{ number_format($localRevenue ?? 0) }}</p>
        </div>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md mb-4 p-4">
        <form method="GET" action="{{ route('fbrpos.transactions') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('pos.search_invoice_customer_ph') }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
            @if($tab === 'fbr')
            <select name="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                <option value="">{{ __('pos.all_status') }}</option>
                <option value="submitted" {{ request('status') === 'submitted' ? 'selected' : '' }}>{{ __('pos.submitted_word') }}</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('pos.pending_word') }}</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>{{ __('pos.failed_word') }}</option>
            </select>
            @else
            <div></div>
            @endif
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
            <div class="flex gap-2">
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                    class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">{{ __('pos.filter_word') }}</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.invoice_hash') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden sm:table-cell">{{ __('pos.customer_word') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.amount_word') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden md:table-cell">{{ __('pos.payment') }}</th>
                        @if($tab === 'fbr')
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">FBR</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden lg:table-cell">{{ __('pos.fbr_invoice') }}</th>
                        @else
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.mode_word') }}</th>
                        @endif
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase hidden md:table-cell">{{ __('pos.date_word') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.action_word') }}</th>
                    </tr>
                </thead>
                @php
                    // Return button window (owner rule 14 Aug 2026) — same
                    // constant the server enforces in returnForm/processReturn.
                    $__fbrReturnCutoff = now()->subDays(\App\Http\Controllers\FbrPosPhase2Controller::RETURN_WINDOW_DAYS);
                @endphp
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($transactions as $txn)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                        <td class="px-4 py-3 text-sm font-medium {{ $tab === 'local' ? 'text-amber-600' : 'text-blue-600' }}">{{ $txn->invoice_number }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 hidden sm:table-cell">{{ $txn->customer_name ?? __('pos.walk_in') }}</td>
                        <td class="px-4 py-3 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($txn->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-center hidden md:table-cell">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ str_replace('_', ' ', $txn->payment_method) }}</span>
                        </td>
                        @if($tab === 'fbr')
                        <td class="px-4 py-3 text-center">
                            @if($txn->fbr_status === 'submitted')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ __('pos.submitted_word') }}</span>
                            @elseif($txn->fbr_status === 'failed')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ __('pos.failed_word') }}</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{{ __('pos.pending_word') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 hidden lg:table-cell">{{ $txn->fbr_invoice_number ?? '—' }}</td>
                        @else
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{{ __('pos.local_word') }}</span>
                        </td>
                        @endif
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ $txn->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-2 whitespace-nowrap">
                                <a href="{{ route('fbrpos.show', $txn->id) }}" class="text-blue-600 hover:text-blue-700 text-sm font-medium">{{ __('pos.view_word') }}</a>
                                {{-- Return button (owner request 14 Aug 2026): start a return
                                     straight from the receipts list — completed sale rows only,
                                     inside the 15-din window the server also enforces. --}}
                                @if(($txn->transaction_type ?? 'sale') !== 'return'
                                    && ($txn->status ?? 'completed') === 'completed'
                                    && $txn->created_at->gte($__fbrReturnCutoff))
                                <a href="{{ route('fbrpos.phase2.return.form', $txn->id) }}" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 dark:bg-rose-900/20 dark:text-rose-300 dark:border-rose-800 dark:hover:bg-rose-900/40 transition" title="{{ __('pos.return_refund') }}">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3"/></svg>
                                    {{ __('pos.return_action') }}
                                </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $tab === 'fbr' ? 8 : 7 }}" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">
                            {{ $tab === 'local' ? __('pos.no_local_transactions_found') : __('pos.no_transactions_found') }}
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($transactions->hasPages())
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $transactions->links() }}
        </div>
        @endif
    </div>
</div>

@include('fbr-pos.partials.pin-modal')
</x-fbr-pos-layout>
