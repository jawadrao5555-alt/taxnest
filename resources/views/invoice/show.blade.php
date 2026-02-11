<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Invoice {{ $invoice->display_invoice_number }}</h2>
            <div class="flex items-center space-x-3">
                @if($invoice->status === 'draft')
                <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Edit</a>
                <div x-data="{ showSubmitModal: false }">
                    <button @click="showSubmitModal = true" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition">Submit to PRAL</button>
                    <div x-show="showSubmitModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                        <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4 p-6">
                            <h3 class="text-lg font-bold text-gray-900 mb-4">Submit to PRAL</h3>
                            <div class="space-y-4">
                                <form method="POST" action="/invoice/{{ $invoice->id }}/submit">
                                    @csrf
                                    <input type="hidden" name="mode" value="smart">
                                    <button type="submit" class="w-full p-4 border-2 border-emerald-200 rounded-lg hover:bg-emerald-50 text-left">
                                        <p class="font-semibold text-emerald-700">Smart Mode (Recommended)</p>
                                        <p class="text-xs text-gray-500">Runs compliance scoring, blocks CRITICAL risk invoices</p>
                                    </button>
                                </form>
                                <div x-data="{ showOverride: false }">
                                    <button @click="showOverride = !showOverride" class="w-full p-4 border-2 border-orange-200 rounded-lg hover:bg-orange-50 text-left">
                                        <p class="font-semibold text-orange-700">Direct MIS Mode</p>
                                        <p class="text-xs text-gray-500">Skips compliance check - requires override reason</p>
                                    </button>
                                    <form x-show="showOverride" method="POST" action="/invoice/{{ $invoice->id }}/submit" class="mt-3">
                                        @csrf
                                        <input type="hidden" name="mode" value="direct_mis">
                                        <textarea name="override_reason" required minlength="10" placeholder="Enter override reason (min 10 characters)..." class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                                        <button type="submit" class="mt-2 w-full px-4 py-2 bg-orange-600 text-white rounded-lg text-sm font-medium">Submit with Override</button>
                                    </form>
                                </div>
                            </div>
                            <button @click="showSubmitModal = false" class="mt-4 w-full text-center text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                        </div>
                    </div>
                </div>
                <div x-data="fbrValidator()">
                    <button @click="doValidate()" :disabled="validating" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700 transition disabled:opacity-50">
                        <svg x-show="validating" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Validate FBR Payload
                    </button>
                    <div x-show="validationResult" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="validationResult = null">
                        <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 p-6 max-h-[80vh] overflow-y-auto">
                            <h3 class="text-lg font-bold text-gray-900 mb-4">FBR Payload Validation</h3>
                            <div x-show="validationResult?.status === 'valid'" class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
                                <p class="text-sm text-emerald-700 font-medium" x-text="validationResult?.message"></p>
                            </div>
                            <div x-show="validationResult?.status === 'invalid'" class="space-y-2">
                                <div class="p-3 bg-red-50 border border-red-200 rounded-lg">
                                    <p class="text-sm text-red-700 font-medium mb-2">Validation Failed</p>
                                    <template x-for="err in validationResult?.errors || []">
                                        <p class="text-xs text-red-600" x-text="err"></p>
                                    </template>
                                </div>
                            </div>
                            <div x-show="validationResult?.status === 'error'" class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                                <p class="text-sm text-amber-700" x-text="validationResult?.message"></p>
                            </div>
                            <button @click="validationResult = null" class="mt-4 w-full text-center text-sm text-gray-500 hover:text-gray-700">Close</button>
                        </div>
                    </div>
                </div>
                <script>
                function fbrValidator() {
                    return {
                        validating: false,
                        validationResult: null,
                        async doValidate() {
                            this.validating = true;
                            this.validationResult = null;
                            try {
                                let res = await fetch('/invoice/{{ $invoice->id }}/validate-fbr', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json' }
                                });
                                this.validationResult = await res.json();
                            } catch(e) {
                                this.validationResult = { status: 'error', message: 'Failed to validate. Please try again.' };
                            }
                            this.validating = false;
                        }
                    };
                }
                </script>
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
                <a href="/invoice/{{ $invoice->id }}/preview" class="inline-flex items-center px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium hover:bg-teal-700 transition">Preview</a>
                <a href="/invoice/{{ $invoice->id }}/download" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">Download PDF</a>
                @if($invoice->share_uuid)
                <a href="https://wa.me/?text={{ urlencode('Invoice ' . $invoice->display_invoice_number . ': ' . url('/share/invoice/' . $invoice->share_uuid)) }}" target="_blank" class="inline-flex items-center px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-medium hover:bg-green-600 transition">WhatsApp</a>
                <button onclick="navigator.clipboard.writeText('{{ url('/share/invoice/' . $invoice->share_uuid) }}').then(() => { this.textContent = 'Copied!'; setTimeout(() => { this.textContent = 'Copy Link'; }, 2000); })" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Copy Link</button>
                @endif
                <a href="/invoices" class="text-sm text-gray-600 hover:text-gray-800">Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">{{ $invoice->company->name ?? 'Company' }}</h3>
                            <p class="text-sm text-gray-500 mt-1">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex px-3 py-1 rounded-full text-sm font-bold
                                @if($invoice->status === 'draft') bg-gray-200 text-gray-700
                                @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                @elseif($invoice->status === 'failed') bg-red-100 text-red-800
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
                            <p class="text-sm text-gray-600">Internal #: <span class="font-semibold text-gray-900">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</span></p>
@if($invoice->fbr_invoice_number)
<p class="text-sm text-gray-600">FBR #: <span class="font-semibold text-emerald-700">{{ $invoice->fbr_invoice_number }}</span></p>
@endif
@if($invoice->fbr_submission_date)
<p class="text-sm text-gray-600">FBR Date: <span class="font-semibold text-gray-900">{{ $invoice->fbr_submission_date->format('d M Y H:i') }}</span></p>
@endif
                            <p class="text-sm text-gray-600">Date: <span class="font-semibold text-gray-900">{{ $invoice->created_at->format('d M Y') }}</span></p>
                            @if($invoice->branch)
                            <p class="text-sm text-gray-600">Branch: <span class="font-semibold text-gray-900">{{ $invoice->branch->name }}</span></p>
                            @endif
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

            @if(!empty($riskAnalysis) && $riskAnalysis['risk_count'] > 0)
            <div class="bg-white rounded-xl shadow-sm border {{ $riskAnalysis['risk_color']['border'] }} overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span>Intelligence Risk Analysis</span>
                    </h3>
                    <div class="flex items-center space-x-3">
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $riskAnalysis['risk_color']['bg'] }} {{ $riskAnalysis['risk_color']['text'] }}">
                            Score: {{ $riskAnalysis['risk_score'] }}/100 - {{ ucfirst($riskAnalysis['risk_level']) }}
                        </span>
                        <span class="text-xs text-gray-500">{{ $riskAnalysis['risk_count'] }} risk(s)</span>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        @foreach($riskAnalysis['risks'] as $risk)
                        <div class="flex items-start space-x-3 p-3 rounded-lg {{ $risk['severity'] === 'high' ? 'bg-red-50 border border-red-200' : ($risk['severity'] === 'medium' ? 'bg-yellow-50 border border-yellow-200' : 'bg-blue-50 border border-blue-200') }}">
                            @if($risk['severity'] === 'high')
                            <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                            @else
                            <svg class="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                            @endif
                            <div class="flex-1">
                                <p class="text-sm font-medium {{ $risk['severity'] === 'high' ? 'text-red-800' : 'text-yellow-800' }}">
                                    {{ ucfirst(str_replace('_', ' ', $risk['type'])) }}
                                    <span class="text-xs font-normal ml-1">(weight: {{ $risk['weight'] }})</span>
                                </p>
                                <p class="text-sm {{ $risk['severity'] === 'high' ? 'text-red-700' : 'text-yellow-700' }} mt-0.5">{{ $risk['message'] }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @if($riskAnalysis['should_block'])
                    <div class="mt-4 p-3 bg-red-100 border border-red-300 rounded-lg">
                        <p class="text-sm font-bold text-red-800">FBR submission will be BLOCKED due to critical risk level. Resolve the issues above before submitting.</p>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            @if(!empty($sroSuggestions) && count($sroSuggestions) > 0)
            <div class="bg-white rounded-xl shadow-sm border border-blue-200 overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-blue-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                        <span>SRO Suggestions</span>
                    </h3>
                    <span class="text-xs text-blue-600 bg-blue-50 px-2 py-1 rounded">Non-mandatory - Verify before use</span>
                </div>
                <div class="p-6">
                    <div class="space-y-2">
                        @foreach($sroSuggestions as $itemIndex => $suggestion)
                        <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-100">
                            <div>
                                <p class="text-sm font-medium text-blue-800">Item #{{ $itemIndex + 1 }}: {{ $suggestion['sro'] }} / Serial {{ $suggestion['serial'] }}</p>
                                <p class="text-xs text-blue-600">{{ $suggestion['description'] }}</p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $suggestion['confidence'] === 'high' ? 'bg-green-100 text-green-700' : ($suggestion['confidence'] === 'medium' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-700') }}">
                                    {{ ucfirst($suggestion['confidence']) }} confidence
                                </span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            @if(!empty($vendorRisk) && $vendorRisk->vendor_score < 70)
            <div class="bg-white rounded-xl shadow-sm border border-orange-200 overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-orange-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                        <span>Vendor Risk Alert</span>
                    </h3>
                    <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $vendorRisk->vendor_score < 40 ? 'bg-red-100 text-red-800' : 'bg-orange-100 text-orange-800' }}">
                        Score: {{ $vendorRisk->vendor_score }}/100
                    </span>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="p-3 bg-gray-50 rounded-lg text-center">
                            <p class="text-xs text-gray-500">Total Invoices</p>
                            <p class="text-lg font-bold text-gray-900">{{ $vendorRisk->total_invoices }}</p>
                        </div>
                        <div class="p-3 {{ $vendorRisk->rejected_invoices > 0 ? 'bg-red-50' : 'bg-gray-50' }} rounded-lg text-center">
                            <p class="text-xs text-gray-500">Rejected</p>
                            <p class="text-lg font-bold {{ $vendorRisk->rejected_invoices > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $vendorRisk->rejected_invoices }}</p>
                        </div>
                        <div class="p-3 {{ $vendorRisk->tax_mismatches > 0 ? 'bg-orange-50' : 'bg-gray-50' }} rounded-lg text-center">
                            <p class="text-xs text-gray-500">Tax Mismatches</p>
                            <p class="text-lg font-bold {{ $vendorRisk->tax_mismatches > 0 ? 'text-orange-700' : 'text-gray-900' }}">{{ $vendorRisk->tax_mismatches }}</p>
                        </div>
                        <div class="p-3 {{ $vendorRisk->anomaly_count > 0 ? 'bg-yellow-50' : 'bg-gray-50' }} rounded-lg text-center">
                            <p class="text-xs text-gray-500">Anomalies</p>
                            <p class="text-lg font-bold {{ $vendorRisk->anomaly_count > 0 ? 'text-yellow-700' : 'text-gray-900' }}">{{ $vendorRisk->anomaly_count }}</p>
                        </div>
                    </div>
                </div>
            </div>
            @endif

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
