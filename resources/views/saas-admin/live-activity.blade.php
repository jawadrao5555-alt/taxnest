<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
        <h1 class="text-2xl font-bold text-white">Live Activity</h1>
        <span class="text-xs text-gray-500">Auto-refresh 60s &middot; {{ now()->format('h:i A') }}</span>
    </div>
    <p class="text-sm text-gray-400 mb-6">POS shops — who is online right now and today's billing ({{ \Illuminate\Support\Carbon::parse($bizDate)->format('d M Y') }}, business day).</p>

    {{-- Summary tiles --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-4 py-3">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Abhi online</p>
            <p class="text-xl font-bold text-emerald-400">{{ $summary['online'] }}</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-4 py-3">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Aaj ke bills</p>
            <p class="text-xl font-bold text-white">{{ number_format($summary['bills']) }}</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-4 py-3">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Aaj ki billing</p>
            <p class="text-xl font-bold text-white">Rs {{ number_format($summary['total']) }}</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-4 py-3">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Active dukanein</p>
            <p class="text-xl font-bold text-white">{{ $summary['active_shops'] }}</p>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-white">POS companies</h3>
            @if($totalCompanies > $rows->count())
                <span class="text-xs text-gray-500">Top {{ $rows->count() }} of {{ $totalCompanies }}</span>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Dukan</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-right">Aaj ke bills</th>
                        <th class="px-4 py-3 text-right">Aaj ka total (Rs)</th>
                        <th class="px-4 py-3 text-center">PRA / Local</th>
                        <th class="px-4 py-3 text-right">Aakhri bill</th>
                        <th class="px-4 py-3 text-right">Aakhri activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($rows as $r)
                    <tr class="hover:bg-gray-800/40">
                        <td class="px-4 py-3" data-label="Dukan">
                            <span class="font-medium text-white">{{ $r->name }}</span>
                            @if($r->status !== 'active' && $r->status !== 'approved')
                                <span class="ml-1 text-[10px] text-amber-400">({{ $r->status }})</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center" data-label="Status">
                            @if($r->online)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 text-xs font-semibold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Online
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-gray-700/40 text-gray-400 text-xs">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-500"></span> Offline
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-white" data-label="Aaj ke bills">{{ number_format($r->bill_count) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-white" data-label="Aaj ka total">{{ number_format($r->total) }}</td>
                        <td class="px-4 py-3 text-center text-xs" data-label="PRA / Local">
                            @if($r->bill_count > 0)
                                <span class="text-sky-400" title="PRA submitted">{{ $r->pra_submitted }}</span>
                                <span class="text-gray-600">/</span>
                                <span class="text-gray-300" title="Local / NULL">{{ $r->local_bills }}</span>
                                @if($r->other_bills > 0)
                                    <span class="text-gray-600">/</span>
                                    <span class="text-amber-400" title="Pending / offline / failed">{{ $r->other_bills }}</span>
                                @endif
                            @else
                                <span class="text-gray-600">&mdash;</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300" data-label="Aakhri bill">
                            {{ $r->last_bill_at ? $r->last_bill_at->format('h:i A') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-400 text-xs" data-label="Aakhri activity">
                            {{ $r->last_seen ? $r->last_seen->diffForHumans() : '—' }}
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No POS companies found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-gray-800 text-[11px] text-gray-500">
            Online = staff activity within the last ~5 minutes (heartbeat). PRA / Local = today's bill breakdown (PRA-submitted / local or NULL / remaining pending-offline-failed).
        </div>
    </div>
</div>

<script>
    // "Live" page — refresh every 60s so the owner sees fresh numbers.
    setTimeout(function () { window.location.reload(); }, 60000);
</script>
</x-admin-layout>
