<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="/dashboard" class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-emerald-100 hover:text-emerald-700 dark:hover:bg-emerald-900/30 dark:hover:text-emerald-400 transition" title="Back to Dashboard">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <div>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-200 leading-tight flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Compliance Center
                    </h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">FBR audit-ready archive &amp; one-click Audit Pack export</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 px-4 space-y-6">

            {{-- Archive stats --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="premium-card p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-emerald-500 to-teal-500 rounded-full"></div>
                    <div class="pl-3">
                        <p class="text-[10px] font-extrabold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Invoice Archive</p>
                        <p class="text-2xl font-extrabold mt-1.5 text-gray-900 dark:text-white">{{ number_format($completedInvoices) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            completed invoice(s){{ $totalInvoices > $completedInvoices ? ' · ' . number_format($totalInvoices - $completedInvoices) . ' draft(s)' : '' }}
                        </p>
                        @if($firstInvoiceAt)
                        <p class="text-[11px] text-emerald-700 dark:text-emerald-400 font-semibold mt-2">Archived since {{ $firstInvoiceAt->format('d M Y') }}</p>
                        @endif
                    </div>
                </div>

                <div class="premium-card p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-indigo-500 to-purple-500 rounded-full"></div>
                    <div class="pl-3">
                        <p class="text-[10px] font-extrabold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Immutable Audit Trail</p>
                        <p class="text-2xl font-extrabold mt-1.5 text-gray-900 dark:text-white">{{ number_format($auditLogCount) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">tamper-evident log entrie(s)</p>
                        @if($firstAuditLogAt)
                        <p class="text-[11px] text-indigo-700 dark:text-indigo-400 font-semibold mt-2">Recording since {{ $firstAuditLogAt->format('d M Y') }}</p>
                        @endif
                    </div>
                </div>

                <div class="premium-card p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-sky-500 to-blue-500 rounded-full"></div>
                    <div class="pl-3">
                        <p class="text-[10px] font-extrabold text-gray-500 dark:text-gray-400 uppercase tracking-widest">FBR Submission Log</p>
                        <p class="text-2xl font-extrabold mt-1.5 text-gray-900 dark:text-white">{{ number_format($fbrLogCount) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">API submission record(s)</p>
                        <p class="text-[11px] text-sky-700 dark:text-sky-400 font-semibold mt-2">Every FBR call is logged</p>
                    </div>
                </div>
            </div>

            {{-- Integrity summary --}}
            <div class="premium-card p-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <h3 class="text-xs font-extrabold text-gray-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                        <span>Invoice Integrity Check (SHA-256)</span>
                    </h3>
                    @if($integrity['checked'] === 0)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">No completed invoices yet</span>
                    @elseif($integrity['failed'] > 0)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">{{ $integrity['failed'] }} failed verification</span>
                    @else
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                            <svg class="w-3.5 h-3.5 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            All checks passed
                        </span>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-800/60 rounded-xl border border-gray-100 dark:border-gray-700">
                        <p class="text-lg font-extrabold text-gray-900 dark:text-white">{{ number_format($integrity['checked']) }}</p>
                        <p class="text-[10px] font-bold text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Checked{{ $integrity['sampled'] ? ' (latest)' : '' }}</p>
                    </div>
                    <div class="text-center p-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-100 dark:border-emerald-800">
                        <p class="text-lg font-extrabold text-emerald-700 dark:text-emerald-400">{{ number_format($integrity['passed']) }}</p>
                        <p class="text-[10px] font-bold text-emerald-600/70 dark:text-emerald-500 mt-1 uppercase tracking-wider">Passed</p>
                    </div>
                    <div class="text-center p-3 {{ $integrity['failed'] > 0 ? 'bg-red-50 dark:bg-red-900/20 border-red-100 dark:border-red-800' : 'bg-gray-50 dark:bg-gray-800/60 border-gray-100 dark:border-gray-700' }} rounded-xl border">
                        <p class="text-lg font-extrabold {{ $integrity['failed'] > 0 ? 'text-red-700 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">{{ number_format($integrity['failed']) }}</p>
                        <p class="text-[10px] font-bold {{ $integrity['failed'] > 0 ? 'text-red-600/70 dark:text-red-500' : 'text-gray-500 dark:text-gray-400' }} mt-1 uppercase tracking-wider">Failed</p>
                    </div>
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-800/60 rounded-xl border border-gray-100 dark:border-gray-700">
                        <p class="text-lg font-extrabold text-gray-900 dark:text-white">{{ number_format($integrity['missing']) }}</p>
                        <p class="text-[10px] font-bold text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">No hash (legacy)</p>
                    </div>
                </div>

                @if(!empty($integrity['failed_numbers']))
                <div class="mt-3 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800">
                    <p class="text-xs font-bold text-red-700 dark:text-red-400 mb-1">Invoices that failed verification:</p>
                    <p class="text-xs text-red-600 dark:text-red-300 font-mono">{{ implode(', ', $integrity['failed_numbers']) }}{{ $integrity['failed'] > count($integrity['failed_numbers']) ? ' …' : '' }}</p>
                </div>
                @endif

                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-3">
                    Each completed invoice carries a SHA-256 hash generated at FBR submission time; it is re-computed here to prove nothing was altered afterwards.
                    {{ $integrity['sampled'] ? 'The check above covers your latest ' . number_format($integrity['checked']) . ' completed invoices — a full check runs on every Audit Pack you generate.' : '' }}
                </p>
            </div>

            {{-- Audit Pack generator --}}
            <div class="premium-card p-6"
                 @if($activePack)
                 x-data="auditPackWatch({{ $activePack->id }}, '{{ route('compliance.packs.status', $activePack) }}', '{{ $activePack->status }}', {{ (int) $activePack->progress }}, {{ (int) $activePack->processed_invoices }}, {{ (int) $activePack->total_invoices }})"
                 @endif
            >
                <h3 class="text-xs font-extrabold text-gray-900 dark:text-white uppercase tracking-widest flex items-center gap-2 mb-1">
                    <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <span>Generate FBR Audit Pack</span>
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-5">
                    One ZIP with everything an auditor or tax officer needs for the selected period:
                    invoice PDFs, invoice register (Excel + CSV), immutable audit trail, FBR submission log and an integrity summary.
                    Packs stay available for {{ $retentionDays }} days.
                </p>

                @if($activePack)
                <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-bold text-emerald-800 dark:text-emerald-300 flex items-center gap-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Building pack for {{ $activePack->date_from->format('d M Y') }} – {{ $activePack->date_to->format('d M Y') }}
                        </p>
                        <p class="text-xs font-bold text-emerald-700 dark:text-emerald-400"><span x-text="progress"></span>%</p>
                    </div>
                    <div class="w-full h-2.5 bg-emerald-100 dark:bg-emerald-900/40 rounded-full overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-500 rounded-full transition-all duration-500" :style="'width:' + progress + '%'"></div>
                    </div>
                    <p class="text-[11px] text-emerald-700/80 dark:text-emerald-400/80 mt-2">
                        <span x-show="total > 0"><span x-text="processed"></span> of <span x-text="total"></span> invoice PDF(s) done · </span>
                        You can leave this page — you will get a notification when it is ready.
                    </p>
                </div>
                @else
                <form method="POST" action="{{ route('compliance.packs.store') }}" class="space-y-4">
                    @csrf
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="setPackRange('this_month')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-emerald-100 hover:text-emerald-700 dark:hover:bg-emerald-900/30 transition">This Month</button>
                        <button type="button" onclick="setPackRange('last_month')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-emerald-100 hover:text-emerald-700 dark:hover:bg-emerald-900/30 transition">Last Month</button>
                        <button type="button" onclick="setPackRange('this_fy')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-emerald-100 hover:text-emerald-700 dark:hover:bg-emerald-900/30 transition">This FY (Jul–Jun)</button>
                        <button type="button" onclick="setPackRange('last_fy')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-emerald-100 hover:text-emerald-700 dark:hover:bg-emerald-900/30 transition">Last FY</button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                        <div>
                            <label for="date_from" class="block text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-wider mb-1.5">From (invoice date)</label>
                            <input type="date" id="date_from" name="date_from" required value="{{ old('date_from', now()->startOfMonth()->toDateString()) }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label for="date_to" class="block text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-wider mb-1.5">To (invoice date)</label>
                            <input type="date" id="date_to" name="date_to" required value="{{ old('date_to', now()->toDateString()) }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 rounded-lg font-bold text-sm text-white transition">
                                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                Generate Audit Pack
                            </button>
                        </div>
                    </div>
                    @error('date_from')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('date_to')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="text-[11px] text-gray-400 dark:text-gray-500">Covers completed (FBR-submitted / pending-verification) invoices dated in the range · up to {{ number_format($maxInvoices) }} invoices per pack.</p>
                </form>
                @endif
            </div>

            {{-- Recent packs --}}
            <div class="premium-card p-6">
                <h3 class="text-xs font-extrabold text-gray-900 dark:text-white uppercase tracking-widest mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Recent Audit Packs</span>
                </h3>

                @if($packs->isEmpty())
                <div class="text-center py-8">
                    <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No Audit Packs yet — generate your first one above.</p>
                </div>
                @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-extrabold text-gray-500 dark:text-gray-400 uppercase tracking-widest border-b border-gray-100 dark:border-gray-800">
                                <th class="py-2 pr-4">Period</th>
                                <th class="py-2 pr-4">Invoices</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Size</th>
                                <th class="py-2 pr-4">Created</th>
                                <th class="py-2 pr-4">Expires</th>
                                <th class="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($packs as $pack)
                            <tr class="border-b border-gray-50 dark:border-gray-800/60">
                                <td class="py-3 pr-4 font-semibold text-gray-800 dark:text-gray-200 whitespace-nowrap">{{ $pack->date_from->format('d M Y') }} – {{ $pack->date_to->format('d M Y') }}</td>
                                <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $pack->total_invoices ? number_format($pack->total_invoices) : '—' }}</td>
                                <td class="py-3 pr-4">
                                    @if($pack->status === 'ready')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">Ready</span>
                                    @elseif($pack->status === 'failed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400" title="{{ Str::limit($pack->error_message, 120) }}">Failed</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse mr-1.5"></span>{{ $pack->status === 'pending' ? 'Queued' : 'Building ' . $pack->progress . '%' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                    @if($pack->file_size)
                                        {{ $pack->file_size >= 1048576 ? number_format($pack->file_size / 1048576, 1) . ' MB' : number_format(max(1, $pack->file_size) / 1024) . ' KB' }}
                                    @else — @endif
                                </td>
                                <td class="py-3 pr-4 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $pack->created_at->format('d M Y H:i') }}</td>
                                <td class="py-3 pr-4 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ optional($pack->expiresAt())->format('d M Y') ?? '—' }}</td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if($pack->status === 'ready')
                                    <a href="{{ route('compliance.packs.download', $pack) }}" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 rounded-lg font-bold text-xs text-white transition">
                                        <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        Download ZIP
                                    </a>
                                    @endif
                                    @if(!$pack->isActive() || ($pack->updated_at && $pack->updated_at->lt(now()->subSeconds(180))))
                                    <form method="POST" action="{{ route('compliance.packs.destroy', $pack) }}" class="inline-block ml-1" onsubmit="return confirm('Delete this Audit Pack?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center px-2.5 py-1.5 bg-gray-100 dark:bg-gray-800 hover:bg-red-100 hover:text-red-700 dark:hover:bg-red-900/30 rounded-lg font-bold text-xs text-gray-600 dark:text-gray-300 transition" title="Delete pack">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

        </div>
    </div>

    <script>
        function setPackRange(preset) {
            const from = document.getElementById('date_from');
            const to = document.getElementById('date_to');
            if (!from || !to) return;
            const today = new Date();
            const fmt = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            let f, t;
            if (preset === 'this_month') {
                f = new Date(today.getFullYear(), today.getMonth(), 1); t = today;
            } else if (preset === 'last_month') {
                f = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                t = new Date(today.getFullYear(), today.getMonth(), 0);
            } else if (preset === 'this_fy') {
                const fyStartYear = today.getMonth() >= 6 ? today.getFullYear() : today.getFullYear() - 1;
                f = new Date(fyStartYear, 6, 1); t = today;
            } else if (preset === 'last_fy') {
                const fyStartYear = (today.getMonth() >= 6 ? today.getFullYear() : today.getFullYear() - 1) - 1;
                f = new Date(fyStartYear, 6, 1); t = new Date(fyStartYear + 1, 5, 30);
            } else { return; }
            from.value = fmt(f); to.value = fmt(t);
        }

        function auditPackWatch(packId, statusUrl, initialStatus, initialProgress, initialProcessed, initialTotal) {
            return {
                status: initialStatus,
                progress: initialProgress || 0,
                processed: initialProcessed || 0,
                total: initialTotal || 0,
                timer: null,
                init() {
                    this.timer = setInterval(() => this.poll(), 4000);
                    this.poll();
                },
                poll() {
                    fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(r => r.ok ? r.json() : null)
                        .then(data => {
                            if (!data) return;
                            this.status = data.status;
                            this.progress = data.progress || 0;
                            this.processed = data.processed || 0;
                            this.total = data.total || 0;
                            if (data.status === 'ready' || data.status === 'failed') {
                                clearInterval(this.timer);
                                window.location.reload();
                            }
                        })
                        .catch(() => {});
                }
            };
        }
    </script>
</x-app-layout>
