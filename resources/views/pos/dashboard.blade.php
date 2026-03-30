<x-pos-layout>
@php
    $posUser = auth('pos')->user();
    $isCashier = $posUser && $posUser->isPosCashier();
@endphp

<style>
    @keyframes slideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes countUp { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
    @keyframes fadeScale { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
    @keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
    @keyframes pulse-glow { 0%, 100% { box-shadow: 0 0 0 0 rgba(124,58,237,0.2); } 50% { box-shadow: 0 0 0 8px rgba(124,58,237,0); } }
    .slide-up { animation: slideUp 0.4s ease-out forwards; }
    .slide-up-1 { animation-delay: 0ms; }
    .slide-up-2 { animation-delay: 60ms; }
    .slide-up-3 { animation-delay: 120ms; }
    .slide-up-4 { animation-delay: 180ms; }
    .slide-up-5 { animation-delay: 240ms; }
    .count-up { animation: countUp 0.5s ease-out forwards; }
    .fade-scale { animation: fadeScale 0.3s ease-out forwards; }
    .stat-card { position: relative; overflow: hidden; border-radius: 16px; }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; opacity: 0.03; background: repeating-linear-gradient(45deg, transparent, transparent 8px, currentColor 8px, currentColor 9px); }
    .stat-card:hover { transform: translateY(-2px); transition: transform 0.2s ease; }
    .glass-card { background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.4); }
    .dark .glass-card { background: rgba(17,24,39,0.7); border: 1px solid rgba(255,255,255,0.05); }
    .progress-bar { height: 3px; border-radius: 2px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 2px; transition: width 1.2s ease-out; }
    .chart-container { position: relative; }
    .table-row-hover:hover { background: linear-gradient(90deg, rgba(124,58,237,0.02) 0%, transparent 100%); }
    .dark .table-row-hover:hover { background: linear-gradient(90deg, rgba(124,58,237,0.06) 0%, transparent 100%); }
</style>

<div class="max-w-7xl mx-auto space-y-5">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 slide-up slide-up-1">
        <div>
            <h1 class="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">
                {{ $isCashier ? 'Quick Overview' : 'Dashboard' }}
            </h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ now()->format('l, d M Y') }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if(!$isCashier)
            <div x-data="{ praEnabled: {{ $praStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 px-3 py-1.5 rounded-xl glass-card">
                <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">PRA</span>
                <button @click="loading=true; fetch('{{ route('pos.api.toggle-pra') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{praEnabled=d.enabled; loading=false;})" :class="praEnabled ? 'bg-purple-600' : 'bg-gray-300 dark:bg-gray-600'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out" :disabled="loading">
                    <span :class="praEnabled ? 'translate-x-4' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                </button>
                <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-purple-600 font-bold' : 'text-gray-400 font-semibold'" class="text-[10px]"></span>
            </div>
            @endif
            <a href="{{ route('pos.invoice.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-purple-600 to-violet-600 text-white text-xs font-bold rounded-xl hover:from-purple-700 hover:to-violet-700 transition shadow-md shadow-purple-500/20">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                New Sale
            </a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 slide-up slide-up-2">
        <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-700 p-4 shadow-lg shadow-purple-500/15">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-purple-100/70">Today's Revenue</span>
                    <div class="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center">
                        <span class="text-[10px] font-extrabold text-white">Rs</span>
                    </div>
                </div>
                <p class="text-xl font-extrabold text-white count-up">{{ number_format($todayStats->revenue ?? 0) }}</p>
                <div class="progress-bar bg-white/10 mt-2">
                    <div class="progress-fill bg-white/40" style="width: {{ min(100, ($monthStats->revenue ?? 1) > 0 ? (($todayStats->revenue ?? 0) / ($monthStats->revenue ?? 1) * 100) : 0) }}%"></div>
                </div>
                <p class="text-[8px] text-purple-200/50 mt-1">of monthly target</p>
            </div>
        </div>

        <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-700 p-4 shadow-lg shadow-blue-500/15">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-blue-100/70">Transactions</span>
                    <div class="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                </div>
                <p class="text-xl font-extrabold text-white count-up">{{ $todayStats->count ?? 0 }}</p>
                <div class="progress-bar bg-white/10 mt-2">
                    <div class="progress-fill bg-white/40" style="width: {{ min(100, ($todayStats->count ?? 0) * 5) }}%"></div>
                </div>
                <p class="text-[8px] text-blue-200/50 mt-1">today's orders</p>
            </div>
        </div>

        <div class="stat-card bg-gradient-to-br from-amber-500 to-orange-600 p-4 shadow-lg shadow-amber-500/15">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-amber-100/70">Avg Ticket</span>
                    <div class="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4"/></svg>
                    </div>
                </div>
                <p class="text-xl font-extrabold text-white count-up">{{ number_format($todayStats->avg_ticket ?? 0) }}</p>
                <div class="progress-bar bg-white/10 mt-2">
                    <div class="progress-fill bg-white/40" style="width: 60%"></div>
                </div>
                <p class="text-[8px] text-amber-200/50 mt-1">per transaction</p>
            </div>
        </div>

        <div class="stat-card bg-gradient-to-br from-emerald-500 to-emerald-700 p-4 shadow-lg shadow-emerald-500/15">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-emerald-100/70">Monthly Total</span>
                    <div class="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                </div>
                <p class="text-xl font-extrabold text-white count-up">{{ number_format($monthStats->revenue ?? 0) }}</p>
                <div class="progress-bar bg-white/10 mt-2">
                    <div class="progress-fill bg-white/40" style="width: 75%"></div>
                </div>
                <p class="text-[8px] text-emerald-200/50 mt-1">this month</p>
            </div>
        </div>
    </div>

    <div x-data="{ activeTab: '{{ $drafts->count() > 0 ? 'drafts' : 'overview' }}', draftCount: {{ $drafts->count() }} }" @draft-deleted.window="draftCount = $event.detail.count" class="slide-up slide-up-3">

        <div class="flex items-center gap-1 mb-4">
            <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'bg-purple-600 text-white shadow-md shadow-purple-500/20' : 'glass-card text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'" class="px-4 py-1.5 text-[11px] font-bold rounded-lg transition-all duration-200">
                Overview
            </button>
            <button @click="activeTab = 'drafts'" :class="activeTab === 'drafts' ? 'bg-amber-500 text-white shadow-md shadow-amber-500/20' : 'glass-card text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'" class="px-4 py-1.5 text-[11px] font-bold rounded-lg transition-all duration-200 flex items-center gap-1.5">
                Drafts
                <span x-show="draftCount > 0" x-text="draftCount" class="inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full text-[9px] font-bold" :class="activeTab === 'drafts' ? 'bg-white/30' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400'"></span>
            </button>
        </div>

        <div x-show="activeTab === 'overview'" x-cloak>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                <div class="glass-card rounded-2xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Payment Split</h3>
                        <span class="text-[8px] text-gray-400 font-medium">TODAY</span>
                    </div>
                    @forelse($paymentBreakdown as $pb)
                    <div class="flex items-center justify-between py-2.5 border-b border-gray-100/50 dark:border-gray-800/50 last:border-0">
                        <div class="flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center {{ $pb->payment_method === 'cash' ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-blue-50 dark:bg-blue-900/20' }}">
                                @if($pb->payment_method === 'cash')
                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                @else
                                <svg class="w-3.5 h-3.5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                @endif
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</p>
                                <p class="text-[9px] text-gray-400">{{ $pb->count }} transactions</p>
                            </div>
                        </div>
                        <span class="text-xs font-bold text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</span>
                    </div>
                    @empty
                    <div class="py-6 text-center">
                        <div class="w-10 h-10 rounded-xl bg-gray-50 dark:bg-gray-800 flex items-center justify-center mx-auto mb-2">
                            <svg class="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <p class="text-[11px] text-gray-400">No sales today</p>
                    </div>
                    @endforelse
                </div>

                <div class="lg:col-span-2 glass-card rounded-2xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Recent Transactions</h3>
                        <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-purple-600 hover:text-purple-700 dark:text-purple-400 transition">VIEW ALL</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left text-[9px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider border-b border-gray-100 dark:border-gray-800">
                                    <th class="pb-2.5 pr-4">Invoice</th>
                                    <th class="pb-2.5 pr-4">Customer</th>
                                    <th class="pb-2.5 pr-4">Method</th>
                                    <th class="pb-2.5 pr-4 text-right">Amount</th>
                                    <th class="pb-2.5">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentTransactions as $txn)
                                <tr class="table-row-hover border-b border-gray-50 dark:border-gray-800/50 last:border-0 transition-colors">
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('pos.transaction.show', $txn->id) }}" class="text-[11px] text-purple-600 hover:text-purple-800 font-bold">{{ $txn->invoice_number }}</a>
                                    </td>
                                    <td class="py-2.5 pr-4 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] font-bold uppercase {{ $txn->payment_method === 'cash' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400' : 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' }}">
                                            {{ $txn->payment_method }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-4 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td>
                                    <td class="py-2.5 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="activeTab === 'drafts'" x-cloak x-data="draftsManager()" x-init="init()">
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-5 border-b border-gray-100/50 dark:border-gray-800/50">
                    <div>
                        <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Saved Drafts</h3>
                        <p class="text-[10px] text-gray-400 mt-0.5">Auto-saved invoices not yet finalized</p>
                    </div>
                    <button @click="deleteAllDrafts()" x-show="drafts.length > 1" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold text-red-600 bg-red-50 dark:bg-red-900/20 dark:text-red-400 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition mt-2 sm:mt-0">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete All
                    </button>
                </div>
                <div class="divide-y divide-gray-50 dark:divide-gray-800/50">
                    <template x-for="draft in drafts" :key="draft.id">
                        <div class="p-4 sm:p-5 table-row-hover transition-colors">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-extrabold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 uppercase">Draft</span>
                                        <span class="text-[11px] font-bold text-gray-900 dark:text-white" x-text="draft.invoice_number"></span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[9px] text-gray-400">
                                        <span x-text="draft.customer_name || 'Walk-in'"></span>
                                        <span x-text="(draft.items ? draft.items.length : 0) + ' items'"></span>
                                        <span x-text="timeAgo(draft.updated_at)"></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-extrabold text-gray-900 dark:text-white" x-text="'Rs.' + Number(draft.total_amount || 0).toLocaleString()"></span>
                                    <a :href="'/pos/invoice/create?draft_id=' + draft.id" class="inline-flex items-center gap-1 px-3 py-1.5 text-[10px] font-bold text-white bg-purple-600 rounded-lg hover:bg-purple-700 transition shadow-sm">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        Continue
                                    </a>
                                    <button @click="deleteDraft(draft.id)" class="p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </div>
                            <template x-if="draft.items && draft.items.length > 0">
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <template x-for="item in draft.items.slice(0, 5)" :key="item.id">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                            <span x-text="item.item_name"></span>
                                            <span class="ml-1 text-gray-300 dark:text-gray-600" x-text="'x' + item.quantity"></span>
                                        </span>
                                    </template>
                                    <template x-if="draft.items.length > 5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] bg-gray-50 text-gray-400 dark:bg-gray-800" x-text="'+' + (draft.items.length - 5) + ' more'"></span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
                <template x-if="drafts.length === 0">
                    <div class="p-10 text-center">
                        <div class="w-12 h-12 mx-auto rounded-2xl bg-gray-50 dark:bg-gray-800 flex items-center justify-center mb-3">
                            <svg class="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <p class="text-[11px] text-gray-400">No drafts saved</p>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @if(!$isCashier)
    <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2.5 slide-up slide-up-4">
        <a href="{{ route('pos.invoice.create') }}" class="glass-card rounded-xl p-3 text-center hover:shadow-md hover:border-purple-200 dark:hover:border-purple-800 transition-all group">
            <div class="w-8 h-8 mx-auto rounded-lg bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center mb-1.5 group-hover:bg-purple-100 dark:group-hover:bg-purple-900/30 transition">
                <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            </div>
            <span class="text-[9px] font-bold text-gray-600 dark:text-gray-400">New Sale</span>
        </a>
        <a href="{{ route('pos.transactions') }}" class="glass-card rounded-xl p-3 text-center hover:shadow-md hover:border-blue-200 dark:hover:border-blue-800 transition-all group">
            <div class="w-8 h-8 mx-auto rounded-lg bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center mb-1.5 group-hover:bg-blue-100 transition">
                <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <span class="text-[9px] font-bold text-gray-600 dark:text-gray-400">Transactions</span>
        </a>
        <a href="{{ route('pos.reports') }}" class="glass-card rounded-xl p-3 text-center hover:shadow-md hover:border-amber-200 dark:hover:border-amber-800 transition-all group">
            <div class="w-8 h-8 mx-auto rounded-lg bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center mb-1.5 group-hover:bg-amber-100 transition">
                <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <span class="text-[9px] font-bold text-gray-600 dark:text-gray-400">Reports</span>
        </a>
        <a href="{{ route('pos.products') }}" class="glass-card rounded-xl p-3 text-center hover:shadow-md hover:border-emerald-200 dark:hover:border-emerald-800 transition-all group">
            <div class="w-8 h-8 mx-auto rounded-lg bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center mb-1.5 group-hover:bg-emerald-100 transition">
                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <span class="text-[9px] font-bold text-gray-600 dark:text-gray-400">Products</span>
        </a>
        <a href="{{ route('pos.customers') }}" class="glass-card rounded-xl p-3 text-center hover:shadow-md hover:border-pink-200 dark:hover:border-pink-800 transition-all group">
            <div class="w-8 h-8 mx-auto rounded-lg bg-pink-50 dark:bg-pink-900/20 flex items-center justify-center mb-1.5 group-hover:bg-pink-100 transition">
                <svg class="w-4 h-4 text-pink-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <span class="text-[9px] font-bold text-gray-600 dark:text-gray-400">Customers</span>
        </a>
        <a href="{{ route('pos.day-close') }}" class="glass-card rounded-xl p-3 text-center hover:shadow-md hover:border-indigo-200 dark:hover:border-indigo-800 transition-all group">
            <div class="w-8 h-8 mx-auto rounded-lg bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center mb-1.5 group-hover:bg-indigo-100 transition">
                <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <span class="text-[9px] font-bold text-gray-600 dark:text-gray-400">Day Close</span>
        </a>
    </div>
    @endif
</div>

<script>
function draftsManager() {
    return {
        drafts: @json($drafts),
        init() {},
        timeAgo(dateStr) {
            if (!dateStr) return '';
            const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        },
        formatMethod(m) {
            return m ? m.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Cash';
        },
        async deleteDraft(id) {
            if (!confirm('Delete this draft? This cannot be undone.')) return;
            try {
                const res = await fetch('/pos/api/draft/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
                });
                if (res.ok) {
                    this.drafts = this.drafts.filter(d => d.id !== id);
                    window.dispatchEvent(new CustomEvent('draft-deleted', { detail: { count: this.drafts.length } }));
                } else {
                    alert('Failed to delete draft.');
                }
            } catch (e) {
                alert('Network error.');
            }
        },
        async deleteAllDrafts() {
            if (!confirm('Delete ALL drafts? This cannot be undone.')) return;
            let failed = 0;
            for (const draft of [...this.drafts]) {
                try {
                    const res = await fetch('/pos/api/draft/' + draft.id, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
                    });
                    if (res.ok) {
                        this.drafts = this.drafts.filter(d => d.id !== draft.id);
                    } else { failed++; }
                } catch (e) { failed++; }
            }
            window.dispatchEvent(new CustomEvent('draft-deleted', { detail: { count: this.drafts.length } }));
            if (failed > 0) alert(failed + ' draft(s) could not be deleted.');
        }
    };
}
</script>
</x-pos-layout>
