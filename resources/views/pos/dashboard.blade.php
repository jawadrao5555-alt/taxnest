<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">NestPOS Dashboard</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Point of Sale Overview</p>
        </div>
        <div class="flex items-center gap-3">
            <div x-data="{ praEnabled: {{ $praStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2">
                <span class="text-sm text-gray-600 dark:text-gray-400">PRA Reporting</span>
                <button @click="loading=true; fetch('{{ route('pos.api.toggle-pra') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{praEnabled=d.enabled; loading=false;})" :class="praEnabled ? 'bg-emerald-600' : 'bg-gray-300 dark:bg-gray-600'" class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out" :disabled="loading">
                    <span :class="praEnabled ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5 ml-0.5"></span>
                </button>
                <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold'" class="text-xs"></span>
            </div>
            <a href="{{ route('pos.invoice.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Invoice
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Today's Sales</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($todayStats->revenue ?? 0) }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Today's Transactions</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $todayStats->count ?? 0 }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Avg Ticket</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($todayStats->avg_ticket ?? 0) }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Monthly Revenue</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($monthStats->revenue ?? 0) }}</p>
                </div>
                <div class="h-10 w-10 rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </div>
            </div>
        </div>
    </div>

    <div x-data="{ activeTab: '{{ $drafts->count() > 0 ? 'drafts' : 'overview' }}', draftCount: {{ $drafts->count() }} }" @draft-deleted.window="draftCount = $event.detail.count" class="mb-6">
        <div class="flex items-center gap-1 border-b border-gray-200 dark:border-gray-700 mb-5">
            <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'border-emerald-500 text-emerald-600 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'" class="px-4 py-2.5 text-sm border-b-2 transition-colors duration-200 whitespace-nowrap">
                Overview
            </button>
            <button @click="activeTab = 'drafts'" :class="activeTab === 'drafts' ? 'border-amber-500 text-amber-600 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'" class="px-4 py-2.5 text-sm border-b-2 transition-colors duration-200 whitespace-nowrap flex items-center gap-2">
                Drafts
                <span x-show="draftCount > 0" x-text="draftCount" class="inline-flex items-center justify-center h-5 min-w-[20px] px-1.5 rounded-full text-xs font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"></span>
            </button>
        </div>

        <div x-show="activeTab === 'overview'" x-cloak>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-1 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Payment Breakdown (Today)</h3>
                    @forelse($paymentBreakdown as $pb)
                    <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $pb->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}
                            </span>
                            <span class="text-xs text-gray-500">{{ $pb->count }} txns</span>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">PKR {{ number_format($pb->total) }}</span>
                    </div>
                    @empty
                    <p class="text-sm text-gray-400">No transactions today</p>
                    @endforelse
                </div>

                <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Recent Transactions</h3>
                        <a href="{{ route('pos.transactions') }}" class="text-xs text-emerald-600 hover:underline">View All</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700">
                                    <th class="pb-2 pr-4">Invoice #</th>
                                    <th class="pb-2 pr-4">Customer</th>
                                    <th class="pb-2 pr-4">Payment</th>
                                    <th class="pb-2 pr-4 text-right">Amount</th>
                                    <th class="pb-2">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentTransactions as $txn)
                                <tr class="border-b border-gray-50 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('pos.transaction.show', $txn->id) }}" class="text-emerald-600 hover:underline font-medium">{{ $txn->invoice_number }}</a>
                                    </td>
                                    <td class="py-2.5 pr-4 text-gray-700 dark:text-gray-300">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            {{ ucwords(str_replace('_', ' ', $txn->payment_method)) }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-4 text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($txn->total_amount) }}</td>
                                    <td class="py-2.5 text-gray-500 text-xs">{{ $txn->created_at->diffForHumans() }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="py-8 text-center text-gray-400">No transactions yet. Create your first POS invoice!</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="activeTab === 'drafts'" x-cloak x-data="draftsManager()" x-init="init()">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-5 border-b border-gray-200 dark:border-gray-700">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Saved Drafts</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Auto-saved invoices that are not yet finalized</p>
                    </div>
                    <div class="flex items-center gap-2 mt-3 sm:mt-0">
                        <button @click="deleteAllDrafts()" x-show="drafts.length > 1" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 dark:bg-red-900/20 dark:text-red-400 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Delete All
                        </button>
                    </div>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="draft in drafts" :key="draft.id">
                        <div class="p-4 sm:p-5 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 mb-1.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">DRAFT</span>
                                        <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="draft.invoice_number"></span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                            <span x-text="draft.customer_name || 'Walk-in'"></span>
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                            <span x-text="(draft.items ? draft.items.length : 0) + ' items'"></span>
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span x-text="timeAgo(draft.updated_at)"></span>
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                            <span x-text="formatMethod(draft.payment_method)"></span>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-lg font-bold text-gray-900 dark:text-white whitespace-nowrap" x-text="'PKR ' + Number(draft.total_amount || 0).toLocaleString()"></span>
                                    <div class="flex items-center gap-2">
                                        <a :href="'/pos/invoice/create?draft_id=' + draft.id" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition shadow-sm">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            Continue
                                        </a>
                                        <button @click="deleteDraft(draft.id)" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-600 bg-red-50 dark:bg-red-900/20 dark:text-red-400 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <template x-if="draft.items && draft.items.length > 0">
                                <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                                    <div class="flex flex-wrap gap-1.5">
                                        <template x-for="item in draft.items.slice(0, 5)" :key="item.id">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                                <span x-text="item.item_name"></span>
                                                <span class="ml-1 text-gray-400" x-text="'x' + item.quantity"></span>
                                            </span>
                                        </template>
                                        <template x-if="draft.items.length > 5">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400" x-text="'+' + (draft.items.length - 5) + ' more'"></span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <template x-if="drafts.length === 0">
                    <div class="p-12 text-center">
                        <div class="h-14 w-14 mx-auto rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                            <svg class="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">No saved drafts</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">Drafts are auto-saved when you start creating an invoice</p>
                        <a href="{{ route('pos.invoice.create') }}" class="inline-flex items-center gap-1.5 mt-4 px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Create New Invoice
                        </a>
                    </div>
                </template>
            </div>
        </div>
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
                        alert('Failed to delete draft. Please try again.');
                    }
                } catch (e) {
                    alert('Network error. Please try again.');
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
                if (failed > 0) alert(failed + ' draft(s) could not be deleted. Please try again.');
            }
        };
    }
    </script>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <a href="{{ route('pos.invoice.create') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-emerald-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center mb-2 group-hover:bg-emerald-100">
                <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">New Invoice</span>
        </a>
        <a href="{{ route('pos.transactions') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-blue-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center mb-2 group-hover:bg-blue-100">
                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Transactions</span>
        </a>
        <a href="{{ route('pos.reports') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-purple-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center mb-2 group-hover:bg-purple-100">
                <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Reports</span>
        </a>
        <a href="{{ route('pos.services') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-amber-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center mb-2 group-hover:bg-amber-100">
                <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Services</span>
        </a>
        <a href="{{ route('pos.products') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-teal-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-teal-50 dark:bg-teal-900/30 flex items-center justify-center mb-2 group-hover:bg-teal-100">
                <svg class="w-5 h-5 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Products</span>
        </a>
        <a href="{{ route('pos.pra-settings') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center hover:border-red-300 hover:shadow-md transition group">
            <div class="h-10 w-10 mx-auto rounded-lg bg-red-50 dark:bg-red-900/30 flex items-center justify-center mb-2 group-hover:bg-red-100">
                <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">PRA Settings</span>
        </a>
    </div>
</div>
</x-pos-layout>