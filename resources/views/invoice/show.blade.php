<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Invoice {{ $invoice->invoice_number ?? '#' . $invoice->id }}</h2>
            <div class="flex items-center space-x-3">
                @if($invoice->status === 'draft')
                <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Edit</a>
                <form method="POST" action="/invoice/{{ $invoice->id }}/submit" class="inline">
                    @csrf
                    <button type="submit" onclick="return confirm('Submit this invoice to FBR? Once submitted, it cannot be edited.')" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition">Submit to FBR</button>
                </form>
                @endif
                @if($invoice->status === 'locked')
                <form method="POST" action="{{ route('invoice.verify', $invoice->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Verify Integrity
                    </button>
                </form>
                @endif
                <a href="/invoice/{{ $invoice->id }}/pdf" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">Download PDF</a>
                <a href="/invoices" class="text-sm text-gray-600 hover:text-gray-800">Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">{{ $invoice->company->name ?? 'Company' }}</h3>
                            <p class="text-sm text-gray-500 mt-1">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex px-3 py-1 rounded-full text-sm font-medium
                                @if($invoice->status === 'draft') bg-yellow-100 text-yellow-800
                                @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                @endif">
                                {{ ucfirst($invoice->status) }}
                            </span>
                            @if($invoice->status === 'locked' && $invoice->integrity_hash)
                            <p class="text-xs text-green-600 mt-1">SHA256 Protected</p>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Buyer Details</h4>
                            <p class="text-sm font-semibold text-gray-900">{{ $invoice->buyer_name }}</p>
                            <p class="text-sm text-gray-600">NTN: {{ $invoice->buyer_ntn }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Invoice Details</h4>
                            <p class="text-sm text-gray-600">Invoice #: <span class="font-semibold text-gray-900">{{ $invoice->invoice_number ?? 'INV-' . $invoice->id }}</span></p>
                            <p class="text-sm text-gray-600">Date: <span class="font-semibold text-gray-900">{{ $invoice->created_at->format('d M Y') }}</span></p>
                            @if($invoice->integrity_hash)
                            <p class="text-xs text-gray-400 mt-2 font-mono break-all">Hash: {{ $invoice->integrity_hash }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tax</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($invoice->items as $index => $item)
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $index + 1 }}</td>
                                <td class="px-6 py-4 text-sm font-mono text-gray-700">{{ $item->hs_code }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ $item->description }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $item->quantity }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">Rs. {{ number_format($item->price, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">Rs. {{ number_format($item->tax, 2) }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">Rs. {{ number_format(($item->price * $item->quantity) + $item->tax, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-right text-sm font-bold text-gray-700 uppercase">Grand Total</td>
                                <td class="px-6 py-4 text-right text-lg font-bold text-emerald-600">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @if(!empty($complianceReport))
            <div class="bg-white rounded-xl shadow-sm border {{ $complianceReport->risk_level === 'CRITICAL' ? 'border-red-200' : ($complianceReport->risk_level === 'HIGH' ? 'border-orange-200' : 'border-gray-100') }} overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span>Compliance Analysis</span>
                    </h3>
                    @php $crBadge = \App\Services\HybridComplianceScorer::getRiskBadge($complianceReport->risk_level); @endphp
                    <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $crBadge['bg'] }} {{ $crBadge['text'] }}">
                        Score: {{ $complianceReport->final_score }} - {{ $complianceReport->risk_level }}
                    </span>
                </div>
                <div class="p-6">
                    @php $ruleFlags = $complianceReport->rule_flags ?? []; @endphp
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div class="p-3 rounded-lg {{ ($ruleFlags['RATE_MISMATCH'] ?? false) ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200' }}">
                            <p class="text-xs font-medium {{ ($ruleFlags['RATE_MISMATCH'] ?? false) ? 'text-red-700' : 'text-green-700' }}">Tax Rate</p>
                            <p class="text-sm font-bold {{ ($ruleFlags['RATE_MISMATCH'] ?? false) ? 'text-red-800' : 'text-green-800' }}">{{ ($ruleFlags['RATE_MISMATCH'] ?? false) ? 'MISMATCH' : 'OK' }}</p>
                        </div>
                        <div class="p-3 rounded-lg {{ ($ruleFlags['BUYER_RISK'] ?? false) ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200' }}">
                            <p class="text-xs font-medium {{ ($ruleFlags['BUYER_RISK'] ?? false) ? 'text-red-700' : 'text-green-700' }}">Buyer NTN (S.23)</p>
                            <p class="text-sm font-bold {{ ($ruleFlags['BUYER_RISK'] ?? false) ? 'text-red-800' : 'text-green-800' }}">{{ ($ruleFlags['BUYER_RISK'] ?? false) ? 'AT RISK' : 'OK' }}</p>
                        </div>
                        <div class="p-3 rounded-lg {{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200' }}">
                            <p class="text-xs font-medium {{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'text-red-700' : 'text-green-700' }}">Banking (S.73)</p>
                            <p class="text-sm font-bold {{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'text-red-800' : 'text-green-800' }}">{{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'VIOLATION' : 'OK' }}</p>
                        </div>
                        <div class="p-3 rounded-lg {{ ($ruleFlags['STRUCTURE_ERROR'] ?? false) ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200' }}">
                            <p class="text-xs font-medium {{ ($ruleFlags['STRUCTURE_ERROR'] ?? false) ? 'text-red-700' : 'text-green-700' }}">Structure (S.23)</p>
                            <p class="text-sm font-bold {{ ($ruleFlags['STRUCTURE_ERROR'] ?? false) ? 'text-red-800' : 'text-green-800' }}">{{ ($ruleFlags['STRUCTURE_ERROR'] ?? false) ? 'ERROR' : 'OK' }}</p>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            @if($invoice->activityLogs && $invoice->activityLogs->count() > 0)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Invoice Timeline</h3>
                </div>
                <div class="p-6">
                    <div class="relative">
                        <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200"></div>
                        @foreach($invoice->activityLogs as $log)
                        <div class="relative flex items-start space-x-4 pb-6 last:pb-0">
                            <div class="relative z-10 flex-shrink-0">
                                @if($log->action === 'created')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-green-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span>
                                @elseif($log->action === 'edited')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-blue-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                                @elseif($log->action === 'submitted')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-purple-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg></span>
                                @elseif($log->action === 'locked')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-emerald-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></span>
                                @elseif($log->action === 'retry')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-yellow-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></span>
                                @else
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-red-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-semibold text-gray-900 capitalize">{{ str_replace('_', ' ', $log->action) }}</p>
                                    <p class="text-xs text-gray-400">{{ $log->created_at->format('d M Y, h:i A') }}</p>
                                </div>
                                <p class="text-sm text-gray-500 mt-0.5">by {{ $log->user->name ?? 'System' }} &middot; {{ $log->ip_address }}</p>
                                @if($log->changes_json)
                                <div class="mt-1 text-xs text-gray-400 font-mono bg-gray-50 rounded p-2 max-h-20 overflow-y-auto">
                                    {{ json_encode($log->changes_json, JSON_PRETTY_PRINT) }}
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
