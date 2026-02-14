<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <nav class="flex items-center text-xs text-gray-400 mb-1">
                        <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 transition">Dashboard</a>
                        <svg class="w-3.5 h-3.5 mx-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span class="text-gray-600 dark:text-gray-300 font-medium">Invoices</span>
                    </nav>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Invoices</h2>
                </div>
                <div class="flex gap-2">
                    <div x-data="csvImport()" x-cloak>
                        <button @click="openModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            Import CSV
                        </button>

                        <div x-show="showModal" class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.5);">
                            <div @click.away="closeModal()" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-800 w-full max-w-4xl max-h-[90vh] overflow-y-auto mx-4">
                                <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">Import Invoices from CSV</h3>
                                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                                </div>

                                <div class="p-6">
                                    <div x-show="step === 'upload'" class="space-y-4">
                                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                                            <p class="text-sm text-blue-700 dark:text-blue-300 mb-2">Download the CSV template, fill in your invoice data, then upload it here. Each row represents one invoice item. Items with the same buyer name + NTN will be grouped into a single invoice.</p>
                                            <a href="/invoices/csv-template" class="inline-flex items-center text-sm font-semibold text-blue-600 hover:text-blue-800">
                                                <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                Download CSV Template
                                            </a>
                                        </div>

                                        <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-8 text-center">
                                            <input type="file" accept=".csv,.txt" @change="handleFileUpload($event)" class="hidden" x-ref="csvInput">
                                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            <p class="mt-2 text-sm text-gray-500">Select a CSV file to upload</p>
                                            <button @click="$refs.csvInput.click()" class="mt-3 px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition" :disabled="uploading">
                                                <span x-show="!uploading">Choose File</span>
                                                <span x-show="uploading">Uploading...</span>
                                            </button>
                                        </div>

                                        <div x-show="uploadError" class="bg-red-50 border border-red-200 rounded-lg p-3">
                                            <p class="text-sm text-red-700" x-text="uploadError"></p>
                                        </div>
                                    </div>

                                    <div x-show="step === 'preview'" class="space-y-4">
                                        <div class="flex items-center justify-between">
                                            <div class="flex gap-3">
                                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-700">Total: <span x-text="totalRows"></span></span>
                                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700">Valid: <span x-text="validCount"></span></span>
                                                <span x-show="errorCount > 0" class="px-3 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700">Errors: <span x-text="errorCount"></span></span>
                                            </div>
                                            <div class="flex gap-2">
                                                <button @click="resetUpload()" class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-300 transition">Upload Different File</button>
                                                <button @click="processCsv()" :disabled="processing || validCount === 0" class="px-4 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                                    <span x-show="!processing">Create <span x-text="validCount"></span> Draft(s)</span>
                                                    <span x-show="processing">Processing...</span>
                                                </button>
                                            </div>
                                        </div>

                                        <div x-show="errorCount > 0" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                                            <p class="text-sm font-semibold text-red-700 dark:text-red-300 mb-1">Rows with errors (will be skipped):</p>
                                            <ul class="text-xs text-red-600 dark:text-red-400 space-y-0.5 max-h-32 overflow-y-auto">
                                                <template x-for="row in rows.filter(r => !r.valid)" :key="row.row">
                                                    <li>
                                                        <span class="font-medium">Row <span x-text="row.row"></span>:</span>
                                                        <span x-text="row.errors.join('; ')"></span>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>

                                        <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                            <table class="min-w-full text-xs">
                                                <thead class="bg-gray-50 dark:bg-gray-800">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">Row</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">Status</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">Buyer</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">NTN</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">Province</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">Doc Type</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">HS Code</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">Description</th>
                                                        <th class="px-3 py-2 text-right font-medium text-gray-500">Qty</th>
                                                        <th class="px-3 py-2 text-right font-medium text-gray-500">Price</th>
                                                        <th class="px-3 py-2 text-right font-medium text-gray-500">Tax</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                                    <template x-for="row in rows" :key="row.row">
                                                        <tr :class="row.valid ? 'bg-green-50/50 dark:bg-green-900/10' : 'bg-red-50/50 dark:bg-red-900/10'">
                                                            <td class="px-3 py-2" x-text="row.row"></td>
                                                            <td class="px-3 py-2">
                                                                <span x-show="row.valid" class="inline-flex px-1.5 py-0.5 rounded text-xs font-bold bg-green-100 text-green-700">OK</span>
                                                                <span x-show="!row.valid" class="inline-flex px-1.5 py-0.5 rounded text-xs font-bold bg-red-100 text-red-700" :title="row.errors.join('; ')">Error</span>
                                                            </td>
                                                            <td class="px-3 py-2" x-text="row.data.buyer_name || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.buyer_ntn || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.destination_province || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.document_type || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.hs_code || '—'"></td>
                                                            <td class="px-3 py-2 max-w-[150px] truncate" x-text="row.data.description || '—'"></td>
                                                            <td class="px-3 py-2 text-right" x-text="row.data.quantity || '—'"></td>
                                                            <td class="px-3 py-2 text-right" x-text="row.data.price || '—'"></td>
                                                            <td class="px-3 py-2 text-right" x-text="row.data.tax || '—'"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div x-show="step === 'done'" class="text-center py-8">
                                        <svg class="mx-auto h-16 w-16 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <p class="mt-3 text-lg font-bold text-gray-800 dark:text-gray-100" x-text="resultMessage"></p>
                                        <div x-show="createdInvoices.length > 0" class="mt-4 max-h-48 overflow-y-auto">
                                            <table class="mx-auto text-sm text-left">
                                                <thead><tr><th class="px-3 py-1 text-gray-500">Invoice #</th><th class="px-3 py-1 text-gray-500">Buyer</th><th class="px-3 py-1 text-gray-500">Amount</th><th class="px-3 py-1 text-gray-500">Items</th></tr></thead>
                                                <tbody>
                                                    <template x-for="inv in createdInvoices" :key="inv.id">
                                                        <tr>
                                                            <td class="px-3 py-1 font-mono text-xs" x-text="inv.invoice_number"></td>
                                                            <td class="px-3 py-1" x-text="inv.buyer_name"></td>
                                                            <td class="px-3 py-1" x-text="'Rs. ' + Number(inv.total_amount).toLocaleString()"></td>
                                                            <td class="px-3 py-1 text-center" x-text="inv.items_count"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                        <button @click="closeModal(); window.location.reload();" class="mt-6 px-6 py-2 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition">Close & Refresh</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <a href="/invoice/create" class="inline-flex items-center px-4 py-2 bg-emerald-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 transition">
                        + New Invoice
                    </a>
                </div>
            </div>
            <div class="flex gap-2 mb-6">
                <a href="/invoices?tab=draft" class="px-4 py-2 rounded-lg text-sm font-medium transition {{ $tab === 'draft' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700' }}">
                    Drafted Invoices
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800">{{ $draftCount }}</span>
                </a>
                <a href="/invoices?tab=completed" class="px-4 py-2 rounded-lg text-sm font-medium transition {{ $tab === 'completed' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700' }}">
                    Completed Invoices
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">{{ $completedCount }}</span>
                </a>
            </div>
            <div class="mb-6">
                <form method="GET" action="/invoices" class="flex flex-col sm:flex-row gap-3">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by invoice #, FBR #, customer name, or NTN..." class="flex-1 rounded-lg bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                    @if(request('search'))
                    <a href="/invoices?tab={{ $tab }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300 transition">Clear</a>
                    @endif
                </form>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
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
                        <tbody class="divide-y divide-gray-200">
                            @forelse($invoices as $invoice)
                            <tr class="even:bg-gray-50/50 dark:even:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
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
                                            <button type="submit" class="text-purple-600 hover:text-purple-800 font-medium">Submit to FBR</button>
                                        </form>
                                        @endif
                                    @else
                                        @if($invoice->status === 'failed')
                                        <a href="/invoice/{{ $invoice->id }}/edit" class="text-amber-600 hover:text-amber-800 font-medium">Edit</a>
                                        <form method="POST" action="/invoice/{{ $invoice->id }}/retry" class="inline">
                                            @csrf
                                            <button type="submit" class="text-emerald-600 hover:text-emerald-800 font-medium">Retry</button>
                                        </form>
                                        @endif
                                        <div x-data="{ showWhtModal: false, pdfWhtRate: 0 }" class="inline-block">
                                            <button @click="showWhtModal = true" class="text-gray-600 hover:text-gray-800 font-medium">Download</button>
                                            <div x-show="showWhtModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.4);">
                                                <div @click.away="showWhtModal = false" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-800 p-6 w-80 max-w-[90vw]">
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
<script>
function csvImport() {
    return {
        showModal: false,
        step: 'upload',
        uploading: false,
        processing: false,
        uploadError: '',
        rows: [],
        totalRows: 0,
        validCount: 0,
        errorCount: 0,
        resultMessage: '',
        createdInvoices: [],

        openModal() {
            this.showModal = true;
            this.resetUpload();
        },

        closeModal() {
            this.showModal = false;
        },

        resetUpload() {
            this.step = 'upload';
            this.uploading = false;
            this.uploadError = '';
            this.rows = [];
            this.totalRows = 0;
            this.validCount = 0;
            this.errorCount = 0;
        },

        async handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            this.uploading = true;
            this.uploadError = '';

            const formData = new FormData();
            formData.append('csv_file', file);

            try {
                const response = await fetch('/invoices/csv-upload', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                const data = await response.json();

                if (!response.ok) {
                    this.uploadError = data.error || data.message || 'Upload failed.';
                    this.uploading = false;
                    return;
                }

                this.rows = data.rows;
                this.totalRows = data.total;
                this.validCount = data.valid_count;
                this.errorCount = data.error_count;
                this.step = 'preview';
            } catch (e) {
                this.uploadError = 'Network error. Please try again.';
            }

            this.uploading = false;
            event.target.value = '';
        },

        async processCsv() {
            this.processing = true;

            const validRows = this.rows.filter(r => r.valid).map(r => r.data);

            try {
                const response = await fetch('/invoices/csv-process', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ rows: validRows }),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.uploadError = data.error || data.message || 'Processing failed.';
                    this.processing = false;
                    return;
                }

                this.resultMessage = data.message;
                this.createdInvoices = data.invoices || [];
                this.step = 'done';
            } catch (e) {
                this.uploadError = 'Network error. Please try again.';
            }

            this.processing = false;
        }
    };
}
</script>
</x-app-layout>
