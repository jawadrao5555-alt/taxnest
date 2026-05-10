<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Failed FBR Invoices &mdash; Retry Manager</h2>
            <a href="/admin/dashboard" class="text-sm text-gray-600 hover:text-gray-800 dark:text-gray-100">Back to Admin</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if(!$flagEnabled)
                <div class="bg-amber-50 border border-amber-300 text-amber-900 rounded-lg p-4">
                    <strong>Feature flag OFF.</strong> Set <code>FEATURE_FBR_RETRY_SYSTEM=true</code> in your environment to enable retry actions. Currently view-only.
                </div>
            @endif

            @if(session('success'))
                <div class="bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-lg p-4">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-rose-50 border border-rose-300 text-rose-900 rounded-lg p-4">{{ session('error') }}</div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                    <div class="text-xs text-gray-500 uppercase">Total Failed</div>
                    <div class="text-2xl font-bold">{{ $stats['total'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                    <div class="text-xs text-gray-500 uppercase">Retryable (&lt;3)</div>
                    <div class="text-2xl font-bold text-emerald-600">{{ $stats['retryable'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                    <div class="text-xs text-gray-500 uppercase">Exhausted (=3)</div>
                    <div class="text-2xl font-bold text-rose-600">{{ $stats['exhausted'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                    <div class="text-xs text-gray-500 uppercase">Never Retried</div>
                    <div class="text-2xl font-bold text-gray-600">{{ $stats['never_retried'] }}</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 table-cards">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice ID</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Number</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Retry</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last Error</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($invoices as $inv)
                            <tr>
                                <td class="px-3 py-2 text-sm">#{{ $inv->id }}</td>
                                <td class="px-3 py-2 text-sm font-mono">{{ $inv->invoice_number }}</td>
                                <td class="px-3 py-2 text-sm">{{ $inv->company->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm">{{ $inv->invoice_date }}</td>
                                <td class="px-3 py-2 text-sm">
                                    <span class="px-2 py-0.5 rounded text-xs {{ ($inv->retry_count ?? 0) >= 3 ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800' }}">
                                        {{ $inv->retry_count ?? 0 }}/3
                                    </span>
                                    @if($inv->last_retry_at)
                                        <div class="text-xs text-gray-500 mt-0.5">{{ \Carbon\Carbon::parse($inv->last_retry_at)->diffForHumans() }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-rose-700 max-w-md truncate" title="{{ $errorByInvoice[$inv->id] ?? '' }}">
                                    {{ \Illuminate\Support\Str::limit($errorByInvoice[$inv->id] ?? '—', 80) }}
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    @if($flagEnabled && ($inv->retry_count ?? 0) < 3)
                                        <form method="POST" action="/admin/fbr/retry/{{ $inv->id }}" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1 bg-emerald-600 text-white text-xs rounded hover:bg-emerald-700">Retry</button>
                                        </form>
                                    @elseif(!$flagEnabled)
                                        <span class="text-xs text-gray-400">disabled</span>
                                    @else
                                        <span class="text-xs text-rose-600">exhausted</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-sm text-gray-500">
                                    No failed invoices &mdash; all submissions are healthy.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $invoices->links() }}</div>

        </div>
    </div>
</x-admin-layout>
