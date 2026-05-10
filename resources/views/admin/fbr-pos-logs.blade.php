<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight flex items-center gap-2">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a4 4 0 014-4h4M9 17H7a2 2 0 01-2-2V5a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2h-2"/></svg>
                FBR POS — Submission Logs & Stats
            </h2>
            <div class="flex items-center gap-3 text-sm">
                <a href="/admin/fbr-logs" class="text-gray-600 hover:text-gray-800 dark:text-gray-300">DI FBR Logs</a>
                <a href="/admin/dashboard" class="text-gray-600 hover:text-gray-800 dark:text-gray-300">← Dashboard</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- Stats Header --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-xl p-4 text-white shadow">
                    <div class="text-[10px] uppercase tracking-wider opacity-80">Last 7 Days</div>
                    <div class="text-3xl font-black mt-1">{{ (int)($stats->total ?? 0) }}</div>
                    <div class="text-[11px] opacity-80">Total Attempts</div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-emerald-200 dark:border-emerald-900/40 p-4 shadow-sm">
                    <div class="text-[10px] uppercase tracking-wider text-emerald-600 font-bold">Success</div>
                    <div class="text-3xl font-black text-emerald-700 dark:text-emerald-400 mt-1">{{ (int)($stats->success ?? 0) }}</div>
                    <div class="text-[11px] text-gray-500">7-day rate: <b class="text-emerald-600">{{ $successRate }}%</b></div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-red-200 dark:border-red-900/40 p-4 shadow-sm">
                    <div class="text-[10px] uppercase tracking-wider text-red-600 font-bold">Failed</div>
                    <div class="text-3xl font-black text-red-700 dark:text-red-400 mt-1">{{ (int)($stats->failed ?? 0) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-yellow-200 dark:border-yellow-900/40 p-4 shadow-sm">
                    <div class="text-[10px] uppercase tracking-wider text-yellow-600 font-bold">Pending</div>
                    <div class="text-3xl font-black text-yellow-700 dark:text-yellow-400 mt-1">{{ (int)($stats->pending ?? 0) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 shadow-sm">
                    <div class="text-[10px] uppercase tracking-wider text-gray-500 font-bold">All-Time Submitted</div>
                    <div class="text-2xl font-black text-gray-900 dark:text-white mt-1">{{ (int)($txStats->submitted ?? 0) }}</div>
                    <div class="text-[11px] text-gray-500">Rs {{ number_format((float)($txStats->submitted_amount ?? 0), 0) }}</div>
                </div>
            </div>

            {{-- Filters --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 shadow-sm">
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[140px]">
                        <label class="block text-[11px] font-bold text-gray-600 dark:text-gray-400 mb-1">Status</label>
                        <select name="status" class="h-9 px-2 rounded border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="">All</option>
                            <option value="success" @selected(request('status')==='success')>Success</option>
                            <option value="failed" @selected(request('status')==='failed')>Failed</option>
                            <option value="pending" @selected(request('status')==='pending')>Pending</option>
                        </select>
                    </div>
                    <div class="min-w-[200px]">
                        <label class="block text-[11px] font-bold text-gray-600 dark:text-gray-400 mb-1">Company</label>
                        <select name="company_id" class="h-9 px-2 rounded border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="">All companies</option>
                            @foreach($companies as $c)
                                <option value="{{ $c->id }}" @selected((int)request('company_id')===$c->id)>{{ $c->company_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="h-9 px-4 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded">Apply</button>
                    @if(request('status') || request('company_id'))
                        <a href="{{ route('admin.fbrPosLogs') }}" class="h-9 px-3 inline-flex items-center text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400">Clear</a>
                    @endif
                </form>
            </div>

            {{-- Logs Table --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-[11px] font-bold text-gray-600 dark:text-gray-400 uppercase">Time</th>
                                <th class="px-4 py-3 text-left text-[11px] font-bold text-gray-600 dark:text-gray-400 uppercase">Company</th>
                                <th class="px-4 py-3 text-left text-[11px] font-bold text-gray-600 dark:text-gray-400 uppercase">Invoice</th>
                                <th class="px-4 py-3 text-left text-[11px] font-bold text-gray-600 dark:text-gray-400 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-[11px] font-bold text-gray-600 dark:text-gray-400 uppercase">HTTP</th>
                                <th class="px-4 py-3 text-left text-[11px] font-bold text-gray-600 dark:text-gray-400 uppercase">Error / Response</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @forelse($logs as $log)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">{{ $log->company->company_name ?? '#'.$log->company_id }}</td>
                                    <td class="px-4 py-3 text-xs">
                                        @if($log->transaction)
                                            <a href="/fbr-pos/transactions/{{ $log->transaction->id }}" class="font-bold text-blue-600 hover:underline">{{ $log->transaction->invoice_number }}</a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($log->status === 'success')
                                            <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-bold">Success</span>
                                        @elseif($log->status === 'failed')
                                            <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-bold">Failed</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 text-[11px] font-bold">{{ ucfirst($log->status) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ $log->response_code ?: '—' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 max-w-md">
                                        @if($log->error_message)
                                            <span class="text-red-600 dark:text-red-400" title="{{ $log->error_message }}">{{ \Illuminate\Support\Str::limit($log->error_message, 100) }}</span>
                                        @elseif($log->response_payload)
                                            <details class="cursor-pointer">
                                                <summary class="text-blue-600 hover:underline">View response</summary>
                                                <pre class="mt-1 text-[10px] bg-gray-100 dark:bg-gray-800 p-2 rounded overflow-x-auto max-h-40">{{ json_encode($log->response_payload, JSON_PRETTY_PRINT) }}</pre>
                                            </details>
                                        @else
                                            <span class="text-gray-400 italic">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">No FBR POS logs yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($logs->hasPages())
                    <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-800">{{ $logs->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
