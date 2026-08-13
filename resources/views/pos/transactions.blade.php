<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    @php
        $__company = \App\Models\Company::find(app('currentCompanyId'));
        // Sync-trust banner cares about SUBMISSION mode (agentHandlesPra), not raw agent_enabled —
        // Direct Production shops submit server-side, so no agent-sync banner for them.
        $__agentEnabled = $__company && $__company->agentHandlesPra();
        $__agentLastSeen = $__company?->agent_last_seen;
        $__agentOnline = $__agentEnabled && $__agentLastSeen && \Carbon\Carbon::parse($__agentLastSeen)->gt(now()->subMinutes(3));
    @endphp

    {{-- Phase 6: Agent Status Banner (trust signal) --}}
    @if($__agentEnabled)
    <div class="mb-4 flex flex-wrap items-center justify-between gap-y-1.5 gap-x-3 rounded-lg border px-4 py-2.5 text-sm
                {{ $__agentOnline ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' }}">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 min-w-0">
            <span class="relative flex h-2.5 w-2.5">
                @if($__agentOnline)
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                @else
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                @endif
            </span>
            <span class="font-semibold {{ $__agentOnline ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-800 dark:text-red-300' }}">
                {{ $__agentOnline ? __("pos.agent_online_syncing") : __("pos.agent_offline") }}
            </span>
            @if($__agentLastSeen)
                <span class="text-xs text-gray-500 dark:text-gray-400">· {{ __("pos.last_seen") }} {{ \Carbon\Carbon::parse($__agentLastSeen)->diffForHumans() }}</span>
            @endif
        </div>
        @unless($__agentOnline)
            <a href="{{ route('pos.agent') }}" class="text-xs font-semibold text-red-700 dark:text-red-300 hover:underline">{{ __("pos.open_agent_settings") }}</a>
        @endunless
    </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ ($tab ?? "pra") === "local" ? __("pos.local_transactions") : __("pos.pos_transactions") }}
        </h1>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            @php
                $failedCountQuery = \App\Models\PosTransaction::where('company_id', app('currentCompanyId'))
                    ->whereIn('pra_status', ['failed', 'offline', 'pending'])
                    ->whereNull('pra_invoice_number');
                // Task 583: mirror bulkRetryPra — a cashier's "Sync all" skips
                // return rows (manager+ only, Task 582), so the badge must not
                // count them either or the count over-promises.
                if (auth('pos')->user()?->posCashierBlocked()
                    && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type')) {
                    $failedCountQuery->where(function ($q) {
                        $q->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                    });
                }
                $failedCount = $failedCountQuery->count();
            @endphp
            @if($failedCount > 0)
            <form method="POST" action="{{ route('pos.transactions.bulk-retry-pra') }}" onsubmit="return confirm(@js(__('pos.confirm_bulk_retry_pra', ['count' => $failedCount])))">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-orange-600 text-white text-sm font-medium rounded-lg hover:bg-orange-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    {{ __("pos.sync_all_count", ["count" => $failedCount]) }}
                </button>
            </form>
            @endif
            <a href="{{ route('pos.invoice.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __("pos.new_invoice") }}
            </a>
        </div>
    </div>

    @include('pos.partials.mode-tabs', ['baseUrl' => route('pos.transactions')])

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <input type="hidden" name="tab" value="{{ $tab ?? 'pra' }}">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('pos.ph_search_invoice_customer') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <select name="payment_method" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                <option value="">{{ __("pos.all_payment_methods") }}</option>
                <option value="cash" {{ request('payment_method') === 'cash' ? 'selected' : '' }}>{{ __("pos.cash_title") }}</option>
                <option value="debit_card" {{ request('payment_method') === 'debit_card' ? 'selected' : '' }}>{{ __("pos.debit_card") }}</option>
                <option value="credit_card" {{ request('payment_method') === 'credit_card' ? 'selected' : '' }}>{{ __("pos.credit_card") }}</option>
                <option value="qr_payment" {{ request('payment_method') === 'qr_payment' ? 'selected' : '' }}>{{ __("pos.qr_raast") }}</option>
            </select>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition">{{ __("pos.filter_btn") }}</button>
                {{-- Wastage filter (Task 593): spoiled-goods return bills only --}}
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap cursor-pointer">
                    <input type="checkbox" name="wastage" value="1" {{ request()->boolean('wastage') ? 'checked' : '' }} onchange="this.form.submit()" class="rounded border-gray-300 dark:border-gray-600 text-amber-600 focus:ring-amber-500">
                    {{ __('pos.wastage_only_filter') }}
                </label>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                        <th class="px-4 py-3">{{ __("pos.invoice_hash") }}</th>
                        <th class="px-4 py-3 hidden md:table-cell">{{ __("pos.customer_word") }}</th>
                        <th class="px-4 py-3 hidden sm:table-cell">{{ __("pos.payment") }}</th>
                        <th class="px-4 py-3 text-right hidden lg:table-cell">{{ __("pos.subtotal") }}</th>
                        <th class="px-4 py-3 text-right hidden lg:table-cell">{{ __("pos.tax_word") }}</th>
                        <th class="px-4 py-3 text-right">{{ __("pos.total_word") }}</th>
                        <th class="px-4 py-3 hidden lg:table-cell">{{ __("pos.pra_fiscal_hash") }}</th>
                        <th class="px-4 py-3 hidden sm:table-cell">{{ __("pos.pra_status") }}</th>
                        <th class="px-4 py-3 hidden md:table-cell">{{ __("pos.date_word") }}</th>
                        <th class="px-4 py-3">{{ __("pos.actions_word") }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $txn)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        @php $rowIsReturn = ($txn->transaction_type ?? 'sale') === 'return'; @endphp
                        <td class="px-4 py-3 font-medium text-emerald-600">
                            <a href="{{ route('pos.transaction.show', $txn->id) }}" class="hover:underline">{{ $txn->invoice_number }}</a>
                            @if($rowIsReturn)
                                <span class="inline-flex items-center ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400 uppercase">{{ __('pos.return_badge') }}</span>
                                @if(!empty($txn->is_wastage))
                                    <span class="inline-flex items-center ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400 uppercase">{{ __('pos.return_wastage_chip') }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300 hidden md:table-cell">
                            {{ $txn->customer_name ?? __("pos.walk_in") }}
                            {{-- Order type badge (ZFC, 2 Aug 2026): Dine In / Takeaway / Delivery ka pata list se hi chale --}}
                            @if($txn->order_type && in_array($txn->order_type, ['dine_in', 'takeaway', 'delivery'], true))
                                <span class="block mt-0.5 text-[10px] font-bold uppercase tracking-wide
                                    {{ $txn->order_type === 'dine_in' ? 'text-teal-600 dark:text-teal-400' : ($txn->order_type === 'delivery' ? 'text-orange-600 dark:text-orange-400' : 'text-blue-600 dark:text-blue-400') }}">
                                    {{ __('pos.ot_' . $txn->order_type) }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $txn->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ ucwords(str_replace('_', ' ', $txn->payment_method)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 hidden lg:table-cell">{{ $rowIsReturn ? '−' : '' }}{{ number_format($txn->subtotal) }}</td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 hidden lg:table-cell">{{ $rowIsReturn ? '−' : '' }}{{ number_format($txn->tax_amount) }}</td>
                        <td class="px-4 py-3 text-right font-semibold {{ $rowIsReturn ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white' }}">{{ $rowIsReturn ? '−' : '' }}PKR {{ number_format($txn->total_amount) }}</td>
                        <td class="px-4 py-3 hidden lg:table-cell">
                            @if($txn->pra_invoice_number)
                                <span class="text-xs font-mono text-purple-700 dark:text-purple-400">{{ $txn->pra_invoice_number }}</span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            @if($txn->pra_status === 'submitted')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">{{ __("pos.submitted_word") }}</span>
                            @elseif($txn->pra_status === 'failed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">{{ __("pos.failed_word") }}</span>
                            @elseif($txn->pra_status === 'pending')
                                @if($__agentEnabled)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400" title="{{ __('pos.ti_queued_for_agent') }}">
                                        🟡 {{ __("pos.awaiting_sync") }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">{{ __("pos.pending_word") }}</span>
                                @endif
                            @elseif($txn->pra_status === 'offline')
                                @if($__agentEnabled)
                                    {{-- Phase 3: agent-enabled companies should never display the alarming "Offline" badge --}}
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400" title="{{ __('pos.ti_agent_retry_next_poll') }}">
                                        🟡 {{ __("pos.awaiting_sync") }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400">⚠️ {{ __("pos.offline") }}</span>
                                @endif
                            @elseif($txn->pra_status === 'local')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">{{ __("pos.local_word") }}</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">{{ __("pos.local_word") }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs hidden md:table-cell">{{ $txn->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('pos.receipt', $txn->id) }}" class="text-emerald-600 hover:underline text-xs font-medium">{{ __("pos.receipt_word") }}</a>
                                @if(!$txn->pra_invoice_number && !$rowIsReturn)
                                <a href="{{ route('pos.transaction.edit', $txn->id) }}" class="text-amber-600 hover:text-amber-700 text-xs font-medium" title="{{ __('pos.edit') }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                <form method="POST" action="{{ route('pos.transaction.delete', $txn->id) }}" class="inline" onsubmit="return confirm(@js(__('pos.confirm_delete_invoice', ['invoice' => $txn->invoice_number])))">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium" title="{{ __('pos.delete') }}">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                                @endif
                                {{-- Retry PRA: sale rows = everyone on the PRA stream; return rows
                                     (Task 582) = manager+ only — cashiers see the row, no button. --}}
                                @if(in_array($txn->pra_status, ['failed', 'offline', 'pending']) && !$txn->pra_invoice_number
                                    && (!$rowIsReturn || !auth('pos')->user()->posCashierBlocked()))
                                <form method="POST" action="{{ route('pos.transaction.retry-pra', $txn->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-orange-600 hover:text-orange-700 text-xs font-medium" title="{{ __('pos.ti_retry_pra') }}">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    </button>
                                </form>
                                @endif
                                {{-- LOCAL tab (owner rule Jul 2026 update): every local bill
                                     (provisional L-series OR reporting-OFF final) gets a per-bill
                                     "Submit to PRA" — CURRENT month only; older months are closed. --}}
                                @if(($tab ?? 'pra') === 'local' && !$rowIsReturn && !$txn->pra_invoice_number && ($txn->pra_status === 'local' || is_null($txn->pra_status)))
                                    @if($txn->created_at->gte(now()->startOfMonth()))
                                    <form method="POST" action="{{ route('pos.transaction.retry-pra', $txn->id) }}" class="inline" onsubmit="return confirm(@js(__('pos.confirm_submit_to_pra', ['invoice' => $txn->invoice_number])))">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-purple-600 text-white hover:bg-purple-700 transition whitespace-nowrap">
                                            {{ __("pos.submit_to_pra") }}
                                        </button>
                                    </form>
                                    @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400 whitespace-nowrap">{{ __("pos.month_closed") }}</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="px-4 py-12 text-center text-gray-400">{{ __("pos.no_transactions_found") }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($transactions->hasPages())
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $transactions->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@if($hasPinSet ?? false)
@include('pos.partials.pin-modal')
@endif
</x-pos-layout>