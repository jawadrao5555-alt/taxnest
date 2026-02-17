@extends('layouts.app')

@section('title', 'WHT Manager')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white">WHT Manager</h1>
            <p class="text-sm text-gray-500 mt-1">View and correct Withholding Tax rates on all locked invoices</p>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Locked</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-white mt-1">{{ $stats['total_locked'] }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">With WHT</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">{{ $stats['with_wht'] }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">No WHT (0%)</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">{{ $stats['no_wht'] }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total WHT Amount</p>
            <p class="text-2xl font-bold text-amber-600 mt-1">Rs. {{ number_format($stats['total_wht_amount'], 2) }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <form method="GET" action="/wht-management" class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by buyer name, invoice number..."
                        class="w-full px-4 py-2 text-sm border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div class="flex gap-2">
                    <select name="filter" class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-white">
                        <option value="">All Invoices</option>
                        <option value="with_wht" {{ request('filter') === 'with_wht' ? 'selected' : '' }}>With WHT</option>
                        <option value="no_wht" {{ request('filter') === 'no_wht' ? 'selected' : '' }}>No WHT (0%)</option>
                    </select>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                    @if(request('search') || request('filter'))
                    <a href="/wht-management" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800">
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Invoice #</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">FBR #</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Buyer</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Total</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">WHT Rate</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">WHT Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($invoices as $invoice)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-900/50' : '' }}"
                        x-data="whtRow_{{ $invoice->id }}()" id="whtRow{{ $invoice->id }}">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-white">
                            <a href="/invoice/{{ $invoice->id }}" class="text-emerald-600 hover:text-emerald-700 hover:underline">
                                {{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? '#'.$invoice->id }}
                            </a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $invoice->fbr_invoice_number ?? '-' }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300 max-w-[200px] truncate">{{ $invoice->buyer_name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $invoice->created_at->format('d M Y') }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-700 dark:text-gray-300 font-medium">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span x-text="currentRate + '%'"
                                :class="currentRate > 0 ? 'bg-blue-100 text-blue-700 border-blue-200' : 'bg-gray-100 text-gray-600 border-gray-200'"
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium" :class="currentRate > 0 ? 'text-blue-600' : 'text-gray-400'">
                            Rs. <span x-text="parseFloat(currentAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            @if($invoice->status === 'locked' && $invoice->fbr_status === 'production')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 border border-emerald-200">FBR Locked</span>
                            @elseif($invoice->status === 'draft')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 border border-amber-200">Draft</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200">{{ ucfirst($invoice->status) }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex items-center justify-center gap-2">
                                <button @click="showModal = true" class="inline-flex items-center px-3 py-1.5 bg-amber-50 border border-amber-200 text-amber-700 rounded-lg text-xs font-bold hover:bg-amber-100 transition">
                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Correct
                                </button>
                                <a href="/invoice/{{ $invoice->id }}/download" target="_blank" class="inline-flex items-center px-3 py-1.5 bg-gray-50 border border-gray-200 text-gray-600 rounded-lg text-xs font-medium hover:bg-gray-100 transition">
                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    PDF
                                </a>
                            </div>

                            <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.4);">
                                <div @click.away="showModal = false" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-800 p-6 w-96 max-w-[90vw]">
                                    <div class="flex items-center justify-between mb-4">
                                        <div>
                                            <p class="text-base font-bold text-gray-800 dark:text-white">Correct WHT Rate</p>
                                            <p class="text-xs text-gray-500 mt-0.5">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number }} - {{ $invoice->buyer_name }}</p>
                                        </div>
                                        <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                                    </div>
                                    <p class="text-xs text-gray-500 mb-1">Current: <span class="font-bold" x-text="currentRate + '%'"></span></p>
                                    <p class="text-xs text-amber-600 mb-3">Invoice and PDF will update with the new rate.</p>
                                    <div class="space-y-2 mb-4">
                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                            :class="newRate == 0 ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 hover:bg-gray-50'">
                                            <input type="radio" value="0" x-model.number="newRate" class="text-emerald-500">
                                            <span class="text-sm font-semibold text-gray-800">No WHT (0%)</span>
                                        </label>
                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                            :class="newRate == 0.5 ? 'border-amber-400 bg-amber-50' : 'border-gray-200 hover:bg-gray-50'">
                                            <input type="radio" value="0.5" x-model.number="newRate" class="text-amber-500">
                                            <span class="text-sm font-semibold text-gray-800">WHT 0.5%</span>
                                        </label>
                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                            :class="newRate == 1 ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'">
                                            <input type="radio" value="1" x-model.number="newRate" class="text-blue-500">
                                            <span class="text-sm font-semibold text-gray-800">WHT 1%</span>
                                        </label>
                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                            :class="newRate == 2 ? 'border-orange-400 bg-orange-50' : 'border-gray-200 hover:bg-gray-50'">
                                            <input type="radio" value="2" x-model.number="newRate" class="text-orange-500">
                                            <span class="text-sm font-semibold text-gray-800">WHT 2%</span>
                                        </label>
                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                            :class="newRate == 2.5 ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:bg-gray-50'">
                                            <input type="radio" value="2.5" x-model.number="newRate" class="text-red-500">
                                            <span class="text-sm font-semibold text-gray-800">WHT 2.5%</span>
                                        </label>
                                    </div>
                                    <button @click="saveCorrection()" :disabled="saving || newRate == currentRate" class="w-full px-4 py-2.5 bg-amber-600 text-white rounded-lg text-sm font-bold hover:bg-amber-700 transition disabled:opacity-50">
                                        <span x-show="!saving">Update WHT Rate</span>
                                        <span x-show="saving" x-cloak>Saving...</span>
                                    </button>
                                    <template x-if="successMsg">
                                        <p class="mt-2 text-xs text-emerald-600 font-medium text-center" x-text="successMsg"></p>
                                    </template>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <script>
                    function whtRow_{{ $invoice->id }}() {
                        return {
                            currentRate: {{ $invoice->wht_rate ?? 0 }},
                            currentAmount: {{ $invoice->wht_amount ?? 0 }},
                            newRate: {{ $invoice->wht_rate ?? 0 }},
                            showModal: false,
                            saving: false,
                            successMsg: '',
                            async saveCorrection() {
                                this.saving = true;
                                this.successMsg = '';
                                try {
                                    const fd = new FormData();
                                    fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
                                    fd.append('wht_rate', this.newRate);
                                    const res = await fetch('/invoice/{{ $invoice->id }}/correct-wht-ajax', {
                                        method: 'POST',
                                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                        body: fd
                                    });
                                    const data = await res.json();
                                    if (data.status === 'ok') {
                                        this.currentRate = data.wht_rate;
                                        this.currentAmount = data.wht_amount;
                                        this.successMsg = data.message || 'Updated!';
                                        setTimeout(() => { this.showModal = false; this.successMsg = ''; }, 1200);
                                    } else {
                                        alert(data.message || 'Failed to update');
                                    }
                                } catch(e) {
                                    alert('Network error');
                                }
                                this.saving = false;
                            }
                        };
                    }
                    </script>
                    @empty
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                            <p class="mt-2 text-gray-500">No WHT-locked invoices found</p>
                            <p class="text-xs text-gray-400 mt-1">Invoices will appear here after WHT rate is locked</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($invoices->hasPages())
        <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800">
            {{ $invoices->appends(request()->query())->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
