<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Invoice Override</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Search Invoices</h3>
                <form method="GET" action="/admin/invoice-override" class="flex gap-4">
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search by invoice number, company name, or invoice ID..." class="flex-1 rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">Search</button>
                </form>
            </div>

            <div id="statusMessage" class="hidden mb-4 px-4 py-3 rounded-lg text-sm font-medium"></div>

            @if(request('q'))
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Results for "{{ request('q') }}" <span class="text-sm font-normal text-gray-500">({{ $invoices->count() }} found)</span></h3>
                </div>

                @if($invoices->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100">
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Invoice #</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Company</th>
                                <th class="text-right py-3 px-4 font-semibold text-gray-600">Amount</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600">Status</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600">FBR Status</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">FBR Invoice #</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoices as $invoice)
                            <tr class="border-b border-gray-50 hover:bg-gray-50/50" id="invoice-row-{{ $invoice->id }}">
                                <td class="py-3 px-4 font-medium text-gray-800">
                                    {{ $invoice->display_invoice_number }}
                                    <span class="text-xs text-gray-400 block">ID: {{ $invoice->id }}</span>
                                </td>
                                <td class="py-3 px-4 text-gray-600">{{ $invoice->company->name ?? 'N/A' }}</td>
                                <td class="py-3 px-4 text-right font-medium text-gray-800">{{ number_format($invoice->total_amount, 2) }}</td>
                                <td class="py-3 px-4 text-center">
                                    <span id="status-badge-{{ $invoice->id }}" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($invoice->status === 'locked') bg-green-100 text-green-800
                                        @elseif($invoice->status === 'draft') bg-gray-100 text-gray-800
                                        @elseif($invoice->status === 'failed') bg-red-100 text-red-800
                                        @else bg-yellow-100 text-yellow-800
                                        @endif">
                                        {{ ucfirst($invoice->status) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span id="fbr-status-badge-{{ $invoice->id }}" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($invoice->fbr_status === 'production') bg-green-100 text-green-800
                                        @elseif($invoice->fbr_status === 'failed') bg-red-100 text-red-800
                                        @elseif($invoice->fbr_status === 'validation_failed') bg-orange-100 text-orange-800
                                        @elseif($invoice->fbr_status === 'pending') bg-blue-100 text-blue-800
                                        @else bg-gray-100 text-gray-500
                                        @endif">
                                        {{ $invoice->fbr_status ? ucfirst(str_replace('_', ' ', $invoice->fbr_status)) : 'None' }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-gray-600">{{ $invoice->fbr_invoice_number ?? '-' }}</td>
                                <td class="py-3 px-4 text-center">
                                    <div class="flex items-center justify-center gap-2 flex-wrap">
                                        <button onclick="overrideAction({{ $invoice->id }}, 'lock')" class="inline-flex items-center px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 transition" title="Lock Invoice">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                            Lock
                                        </button>
                                        <button onclick="overrideAction({{ $invoice->id }}, 'unlock')" class="inline-flex items-center px-3 py-1.5 bg-amber-500 text-white rounded-lg text-xs font-medium hover:bg-amber-600 transition" title="Unlock Invoice">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                            Unlock
                                        </button>
                                        <select onchange="updateFbrStatus({{ $invoice->id }}, this.value)" class="rounded-lg border-gray-300 text-xs py-1.5 pl-2 pr-7 focus:ring-emerald-500 focus:border-emerald-500">
                                            <option value="">FBR Status...</option>
                                            <option value="production">Production</option>
                                            <option value="failed">Failed</option>
                                            <option value="validation_failed">Validation Failed</option>
                                            <option value="pending">Pending</option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="px-6 py-12 text-center text-gray-500">
                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <p>No invoices found matching your search.</p>
                </div>
                @endif
            </div>
            @endif

        </div>
    </div>

    <script>
        const csrfToken = '{{ csrf_token() }}';

        function showMessage(message, isError = false) {
            const el = document.getElementById('statusMessage');
            el.textContent = message;
            el.className = 'mb-4 px-4 py-3 rounded-lg text-sm font-medium ' +
                (isError ? 'bg-red-100 text-red-800 border border-red-200' : 'bg-green-100 text-green-800 border border-green-200');
            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 5000);
        }

        function updateBadges(invoiceId, data) {
            const statusBadge = document.getElementById('status-badge-' + invoiceId);
            if (statusBadge) {
                const statusColors = {
                    'locked': 'bg-green-100 text-green-800',
                    'draft': 'bg-gray-100 text-gray-800',
                    'failed': 'bg-red-100 text-red-800',
                };
                statusBadge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' +
                    (statusColors[data.status] || 'bg-yellow-100 text-yellow-800');
                statusBadge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
            }

            const fbrBadge = document.getElementById('fbr-status-badge-' + invoiceId);
            if (fbrBadge && data.fbr_status !== undefined) {
                const fbrColors = {
                    'production': 'bg-green-100 text-green-800',
                    'failed': 'bg-red-100 text-red-800',
                    'validation_failed': 'bg-orange-100 text-orange-800',
                    'pending': 'bg-blue-100 text-blue-800',
                };
                fbrBadge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' +
                    (fbrColors[data.fbr_status] || 'bg-gray-100 text-gray-500');
                fbrBadge.textContent = data.fbr_status ? data.fbr_status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'None';
            }
        }

        function overrideAction(invoiceId, action) {
            if (!confirm('Are you sure you want to ' + action + ' this invoice?')) return;

            fetch('/admin/invoice-override/' + invoiceId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ action: action }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    showMessage(data.error, true);
                } else {
                    showMessage(data.message);
                    updateBadges(invoiceId, data.invoice);
                }
            })
            .catch(() => showMessage('An error occurred. Please try again.', true));
        }

        function updateFbrStatus(invoiceId, fbrStatus) {
            if (!fbrStatus) return;
            if (!confirm('Update FBR status to "' + fbrStatus.replace(/_/g, ' ') + '"?')) return;

            fetch('/admin/invoice-override/' + invoiceId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ action: 'update_fbr_status', fbr_status: fbrStatus }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    showMessage(data.error, true);
                } else {
                    showMessage(data.message);
                    updateBadges(invoiceId, data.invoice);
                }
            })
            .catch(() => showMessage('An error occurred. Please try again.', true));
        }
    </script>
</x-app-layout>
