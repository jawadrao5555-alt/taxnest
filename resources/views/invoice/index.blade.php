<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <nav class="flex items-center text-xs text-gray-400 mb-1">
                    <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 transition">Dashboard</a>
                    <svg class="w-3.5 h-3.5 mx-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    <span class="text-gray-600 dark:text-gray-300 font-medium">Invoices</span>
                </nav>
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Invoices</h2>
            </div>
            <a href="/invoice/create" class="inline-flex items-center px-4 py-2 bg-emerald-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 transition">
                + New Invoice
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex gap-2 mb-6">
                <a href="/invoices?tab=draft" class="px-4 py-2 rounded-lg text-sm font-medium transition {{ $tab === 'draft' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    Drafted Invoices
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800">{{ $draftCount }}</span>
                </a>
                <a href="/invoices?tab=completed" class="px-4 py-2 rounded-lg text-sm font-medium transition {{ $tab === 'completed' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    Completed Invoices
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">{{ $completedCount }}</span>
                </a>
            </div>
            <div class="mb-6">
                <form method="GET" action="/invoices" class="flex flex-col sm:flex-row gap-3">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by invoice #, FBR #, customer name, or NTN..." class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                    @if(request('search'))
                    <a href="/invoices?tab={{ $tab }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300 transition">Clear</a>
                    @endif
                </form>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0 z-10">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Invoice #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Buyer</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">NTN</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Branch</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Items</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($invoices as $invoice)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 whitespace-nowrap">
    @if($invoice->fbr_invoice_number)
        <div class="text-sm font-semibold text-emerald-700">{{ $invoice->fbr_invoice_number }}</div>
        <div class="text-xs text-gray-400">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number }}</div>
    @else
        <div class="text-sm font-medium text-gray-900">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-'.$invoice->id }}</div>
    @endif
</td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs">
                                    @if($invoice->document_type === 'Credit Note')
                                        <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-medium">CN</span>
                                    @elseif($invoice->document_type === 'Debit Note')
                                        <span class="px-2 py-0.5 rounded bg-purple-100 text-purple-800 font-medium">DN</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-600 font-medium">INV</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ $invoice->buyer_name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $invoice->buyer_ntn }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $invoice->branch->name ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $invoice->items->count() }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold
                                        @if($invoice->status === 'draft') bg-gray-200 text-gray-700
                                        @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                        @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                        @elseif($invoice->status === 'failed') bg-red-100 text-red-800
                                        @elseif($invoice->status === 'pending_verification') bg-amber-100 text-amber-800
                                        @endif">
                                        {{ $invoice->status === 'pending_verification' ? 'Pending' : ucfirst($invoice->status) }}
                                    </span>
                                    @if($invoice->fbr_status && $invoice->fbr_status !== 'pending')
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium ml-1
                                        @if($invoice->fbr_status === 'submitted') bg-blue-100 text-blue-700
                                        @elseif($invoice->fbr_status === 'validated') bg-emerald-100 text-emerald-700
                                        @elseif($invoice->fbr_status === 'failed') bg-red-100 text-red-700
                                        @endif">
                                        {{ ucfirst($invoice->fbr_status) }}
                                    </span>
                                    @endif
                                    @if($invoice->status === 'failed')
                                    @php
                                        $lastLog = $invoice->fbrLogs->first();
                                        $failReason = '';
                                        if ($lastLog && $lastLog->response_payload) {
                                            $resp = json_decode($lastLog->response_payload, true);
                                            if (!empty($resp['errors'])) {
                                                $errs = is_array($resp['errors']) ? $resp['errors'] : [$resp['errors']];
                                                $failReason = implode(', ', array_slice($errs, 0, 2));
                                            } elseif (!empty($resp['error'])) {
                                                $failReason = $resp['error'];
                                            }
                                        }
                                    @endphp
                                    @if($failReason)
                                    <p class="text-xs text-red-600 mt-1 max-w-[200px] truncate" title="{{ $failReason }}">{{ $failReason }}</p>
                                    @endif
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $invoice->created_at->format('d M Y') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm space-x-2">
                                    <a href="/invoice/{{ $invoice->id }}" class="text-emerald-600 hover:text-emerald-800 font-medium">View</a>
                                    @if($tab === 'draft')
                                        @if($invoice->status === 'draft')
                                        <a href="/invoice/{{ $invoice->id }}/edit" class="text-blue-600 hover:text-blue-800 font-medium">Edit</a>
                                        <form method="POST" action="/invoice/{{ $invoice->id }}/submit" class="inline">
                                            @csrf
                                            <button type="submit" class="text-purple-600 hover:text-purple-800 font-medium" onclick="return confirm('Submit this invoice to FBR?')">Submit to FBR</button>
                                        </form>
                                        @endif
                                    @else
                                        @if($invoice->status === 'failed')
                                        <a href="/invoice/{{ $invoice->id }}/edit" class="text-amber-600 hover:text-amber-800 font-medium">Edit</a>
                                        <form method="POST" action="/invoice/{{ $invoice->id }}/retry" class="inline">
                                            @csrf
                                            <button type="submit" class="text-emerald-600 hover:text-emerald-800 font-medium" onclick="return confirm('Retry FBR submission?')">Retry</button>
                                        </form>
                                        @endif
                                        <div x-data="{ showWhtModal: false, pdfWhtRate: 0 }" class="inline-block">
                                            <button @click="showWhtModal = true" class="text-gray-600 hover:text-gray-800 font-medium">Download</button>
                                            <div x-show="showWhtModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.4);">
                                                <div @click.away="showWhtModal = false" class="bg-white rounded-2xl shadow-2xl border border-gray-200 p-6 w-80 max-w-[90vw]">
                                                    <div class="flex items-center justify-between mb-4">
                                                        <p class="text-base font-bold text-gray-800">Withholding Tax Rate</p>
                                                        <button @click="showWhtModal = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                                                    </div>
                                                    <div class="space-y-2 mb-4">
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 0 ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 hover:bg-gray-50'">
                                                            <input type="radio" value="0" x-model.number="pdfWhtRate" class="text-emerald-500">
                                                            <span class="text-sm font-semibold text-gray-800">No WHT (0%)</span>
                                                        </label>
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 0.5 ? 'border-amber-400 bg-amber-50' : 'border-gray-200 hover:bg-gray-50'">
                                                            <input type="radio" value="0.5" x-model.number="pdfWhtRate" class="text-amber-500">
                                                            <span class="text-sm font-semibold text-gray-800">WHT 0.5%</span>
                                                        </label>
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 2.5 ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:bg-gray-50'">
                                                            <input type="radio" value="2.5" x-model.number="pdfWhtRate" class="text-red-500">
                                                            <span class="text-sm font-semibold text-gray-800">WHT 2.5%</span>
                                                        </label>
                                                    </div>
                                                    <a :href="'/invoice/{{ $invoice->id }}/download?wht_rate=' + pdfWhtRate" target="_blank" class="block w-full text-center px-4 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition">Download PDF</a>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="10" class="px-6 py-12 text-center">
                                    <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                    <p class="mt-2 text-gray-500">No invoices yet</p>
                                    <a href="/invoice/create" class="mt-3 inline-block text-emerald-600 hover:text-emerald-700 font-medium">Create your first invoice</a>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($invoices->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">
                    {{ $invoices->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
