<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto px-4 py-6">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-black text-gray-900 dark:text-white flex items-center gap-2">
                <svg class="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.74-2.991l-7-12a2 2 0 00-3.48 0l-7 12A2 2 0 005 19z"/></svg>
                FBR Fail Queue
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Failed and pending FBR submissions — retry manually or in bulk.</p>
        </div>
        <a href="{{ route('fbrpos.transactions') }}" class="text-sm text-blue-600 hover:underline">← Back to Transactions</a>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 dark:bg-emerald-900/20 dark:border-emerald-800 dark:text-emerald-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">{{ session('error') }}</div>
    @endif

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-red-200 dark:border-red-900/40 p-4 shadow-sm">
            <div class="text-[11px] uppercase tracking-wider text-red-600 font-bold">Failed</div>
            <div class="text-3xl font-black text-red-700 dark:text-red-400 mt-1">{{ (int)($stats->failed_count ?? 0) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-yellow-200 dark:border-yellow-900/40 p-4 shadow-sm">
            <div class="text-[11px] uppercase tracking-wider text-yellow-600 font-bold">Pending</div>
            <div class="text-3xl font-black text-yellow-700 dark:text-yellow-400 mt-1">{{ (int)($stats->pending_count ?? 0) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-emerald-200 dark:border-emerald-900/40 p-4 shadow-sm">
            <div class="text-[11px] uppercase tracking-wider text-emerald-600 font-bold">Submitted</div>
            <div class="text-3xl font-black text-emerald-700 dark:text-emerald-400 mt-1">{{ (int)($stats->submitted_count ?? 0) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 shadow-sm">
            <div class="text-[11px] uppercase tracking-wider text-gray-500 font-bold">Failed Amount</div>
            <div class="text-2xl font-black text-gray-900 dark:text-white mt-1">Rs {{ number_format((float)($stats->failed_amount ?? 0), 0) }}</div>
        </div>
    </div>

    {{-- Action Bar --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-4 shadow-sm">
        <div class="flex flex-wrap items-center gap-3 justify-between">
            <form method="GET" class="flex-1 min-w-[200px] max-w-md">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by invoice # or customer..."
                    class="w-full h-10 px-3 rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
            </form>

            @if(($stats->failed_count ?? 0) + ($stats->pending_count ?? 0) > 0)
            <form method="POST" action="{{ route('fbrpos.failQueue.retryAll') }}"
                onsubmit="return confirm('Schedule auto-retry for all {{ ($stats->failed_count ?? 0) + ($stats->pending_count ?? 0) }} failed/pending invoices? Each will retry up to 3 times.');">
                @csrf
                <button type="submit" class="h-10 px-5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white text-sm font-bold rounded-lg shadow-md flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Retry All ({{ ($stats->failed_count ?? 0) + ($stats->pending_count ?? 0) }})
                </button>
            </form>
            @endif
        </div>
    </div>

    {{-- Network Status --}}
    <div x-data="{ online: navigator.onLine }"
         x-init="window.addEventListener('online', () => online = true); window.addEventListener('offline', () => online = false);"
         x-show="!online" x-cloak
         class="mb-4 p-4 rounded-xl bg-red-50 border-l-4 border-red-500 text-red-800 dark:bg-red-900/30 dark:text-red-300 flex items-center gap-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a4.978 4.978 0 01-1.414-2.83m-1.414 5.658a9 9 0 01-2.167-9.238m7.824 2.167a1 1 0 111.414 1.415m-1.414-1.415L3 3m8.293 8.293l1.414 1.414"/></svg>
        <div>
            <div class="font-bold">No Internet Connection</div>
            <div class="text-sm">Retry actions are disabled until you reconnect.</div>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800 table-cards">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-bold text-gray-600 dark:text-gray-400">Invoice</th>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-bold text-gray-600 dark:text-gray-400">Customer</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-bold text-gray-600 dark:text-gray-400">Amount</th>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-bold text-gray-600 dark:text-gray-400">Status</th>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-bold text-gray-600 dark:text-gray-400">Last Error</th>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-bold text-gray-600 dark:text-gray-400">Date</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-bold text-gray-600 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800" x-data="{ online: navigator.onLine }" x-init="window.addEventListener('online', () => online = true); window.addEventListener('offline', () => online = false);">
                    @forelse($transactions as $tx)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                        <td class="px-4 py-3 text-sm">
                            <a href="{{ route('fbrpos.show', $tx->id) }}" class="font-bold text-blue-600 hover:underline">{{ $tx->invoice_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                            {{ $tx->customer_name ?: '—' }}
                            @if($tx->customer_phone) <div class="text-xs text-gray-500">{{ $tx->customer_phone }}</div> @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-right tabular-nums font-semibold text-gray-900 dark:text-white">Rs {{ number_format($tx->total_amount, 2) }}</td>
                        <td class="px-4 py-3">
                            @if($tx->fbr_status === 'failed')
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-xs font-bold"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Failed</span>
                            @elseif($tx->fbr_status === 'pending')
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 text-xs font-bold"><span class="w-1.5 h-1.5 rounded-full bg-yellow-500 animate-pulse"></span> Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 max-w-xs">
                            @php $lastLog = $tx->fbrLogs->first(); @endphp
                            @if($lastLog && $lastLog->error_message)
                                <span title="{{ $lastLog->error_message }}">{{ \Illuminate\Support\Str::limit($lastLog->error_message, 80) }}</span>
                            @else
                                <span class="text-gray-400 italic">No error log</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $tx->created_at->format('d M H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('fbrpos.editFailed', $tx->id) }}"
                                   class="px-3 py-1.5 rounded-lg text-xs font-bold bg-amber-600 hover:bg-amber-700 text-white shadow-sm flex items-center gap-1"
                                   title="Edit line items (HS code, qty, tax) and retry">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Edit
                                </a>
                                <form method="POST" action="{{ route('fbrpos.failQueue.retryOne', $tx->id) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                        :disabled="!online"
                                        :class="online ? 'bg-blue-600 hover:bg-blue-700 text-white' : 'bg-gray-300 text-gray-500 cursor-not-allowed'"
                                        class="px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm flex items-center gap-1"
                                        title="Retry submission as-is (no changes)">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Retry
                                    </button>
                                </form>
                                <a href="{{ route('fbrpos.show', $tx->id) }}" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200">View</a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center">
                        <svg class="w-16 h-16 mx-auto text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div class="mt-3 text-lg font-bold text-gray-700 dark:text-gray-300">All clear!</div>
                        <div class="text-sm text-gray-500">No failed or pending FBR submissions.</div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($transactions->hasPages())
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-800">{{ $transactions->links() }}</div>
        @endif
    </div>

    <div class="mt-4 p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-sm text-blue-800 dark:text-blue-300">
        <div class="font-bold mb-1">ℹ How auto-retry works:</div>
        <ul class="list-disc list-inside space-y-0.5 text-xs">
            <li>Each retry attempts FBR submission up to <b>3 times</b> with delays of <b>10s, 20s, 30s</b>.</li>
            <li>Duplicate protection: a transaction already submitted to FBR will never be re-sent.</li>
            <li>You'll receive a push notification on success or after all retries fail.</li>
            <li>Retry actions auto-disable when offline.</li>
        </ul>
    </div>
</div>
</x-fbr-pos-layout>
