<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Invoice Preview — {{ $invoice->invoice_number ?? '#' . $invoice->id }}</h2>
            <div class="flex items-center space-x-3">
                <a href="/invoice/{{ $invoice->id }}" class="text-sm text-gray-600 hover:text-gray-800">Back to Invoice</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8" x-data="{ showValidation: {{ session('validation_result') ? 'true' : 'false' }} }">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('validation_result'))
            @php $vr = session('validation_result'); @endphp
            @php $vrBadge = \App\Services\HybridComplianceScorer::getRiskBadge($vr['risk_level']); @endphp
            <div x-show="showValidation" class="bg-white rounded-xl shadow-sm border {{ $vr['risk_level'] === 'CRITICAL' ? 'border-red-300' : 'border-emerald-200' }} overflow-hidden mb-8">
                <div class="px-6 py-4 border-b {{ $vr['risk_level'] === 'CRITICAL' ? 'border-red-200 bg-red-50' : 'border-emerald-100 bg-emerald-50' }} flex items-center justify-between">
                    <h3 class="text-lg font-semibold {{ $vr['risk_level'] === 'CRITICAL' ? 'text-red-800' : 'text-emerald-800' }}">Validation Result</h3>
                    <div class="flex items-center space-x-3">
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $vrBadge['bg'] }} {{ $vrBadge['text'] }}">
                            Score: {{ $vr['score'] }} — {{ $vr['risk_level'] }}
                        </span>
                        <button type="button" @click="showValidation = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach($vr['rule_flags'] as $flagName => $flagged)
                        <div class="p-3 rounded-lg border {{ $flagged ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200' }}">
                            <p class="text-xs font-medium {{ $flagged ? 'text-red-700' : 'text-green-700' }}">{{ str_replace('_', ' ', $flagName) }}</p>
                            <p class="text-sm font-bold {{ $flagged ? 'text-red-800' : 'text-green-800' }}">{{ $flagged ? 'FLAGGED' : 'OK' }}</p>
                        </div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between p-4 rounded-lg {{ $vr['fbr_status'] === 'blocked' ? 'bg-red-50' : 'bg-emerald-50' }}">
                        <div>
                            <p class="text-sm font-medium {{ $vr['fbr_status'] === 'blocked' ? 'text-red-700' : 'text-emerald-700' }}">FBR Validation Status</p>
                            <p class="text-lg font-bold {{ $vr['fbr_status'] === 'blocked' ? 'text-red-800' : 'text-emerald-800' }}">{{ strtoupper($vr['fbr_status']) }}</p>
                        </div>
                        @if($vr['fbr_status'] === 'blocked')
                        <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition">Fix Issues & Re-validate</a>
                        @else
                        <form method="POST" action="/invoice/{{ $invoice->id }}/submit" class="inline">
                            @csrf
                            <input type="hidden" name="mode" value="smart">
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Submit to PRAL</button>
                        </form>
                        @endif
                    </div>

                    @if($vr['fbr_status'] === 'blocked')
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <p class="text-sm font-semibold text-red-800">CRITICAL Risk Level Detected</p>
                        <p class="text-sm text-red-700 mt-1">This invoice has been blocked from FBR/PRAL submission due to critical compliance issues. Please fix the flagged issues and re-validate.</p>
                    </div>
                    @endif
                </div>
            </div>
            @endif

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
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tax Rate</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tax</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($invoice->items as $index => $item)
                            @php
                                $itemSubtotal = $item->price * $item->quantity;
                                $effectiveRate = $itemSubtotal > 0 ? round(($item->tax / $itemSubtotal) * 100, 2) : 0;
                            @endphp
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $index + 1 }}</td>
                                <td class="px-6 py-4 text-sm font-mono text-gray-700">{{ $item->hs_code }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ $item->description }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $item->quantity }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">Rs. {{ number_format($item->price, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">Rs. {{ number_format($itemSubtotal, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $effectiveRate }}%</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">Rs. {{ number_format($item->tax, 2) }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">Rs. {{ number_format($itemSubtotal + $item->tax, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-right text-sm font-medium text-gray-600">Subtotal</td>
                                <td class="px-6 py-4 text-right text-sm font-bold text-gray-700">Rs. {{ number_format($subtotal, 2) }}</td>
                                <td></td>
                                <td class="px-6 py-4 text-right text-sm font-bold text-gray-700">Rs. {{ number_format($totalTax, 2) }}</td>
                                <td class="px-6 py-4 text-right text-lg font-bold text-emerald-600">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Tax Breakdown</h3>
                </div>
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Tax Rate</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Tax Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach($taxBreakdown as $tb)
                                <tr>
                                    <td class="px-4 py-2 text-sm font-mono text-gray-700">{{ $tb['hs_code'] }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-700">{{ $tb['description'] }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-700 text-right">Rs. {{ number_format($tb['subtotal'], 2) }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-700 text-right">{{ $tb['rate'] }}%</td>
                                    <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right">Rs. {{ number_format($tb['tax'], 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="2" class="px-4 py-2 text-right text-sm font-bold text-gray-700">Total</td>
                                    <td class="px-4 py-2 text-right text-sm font-bold text-gray-700">Rs. {{ number_format($subtotal, 2) }}</td>
                                    <td></td>
                                    <td class="px-4 py-2 text-right text-sm font-bold text-emerald-600">Rs. {{ number_format($totalTax, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
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
                        Score: {{ $complianceReport->final_score }} — {{ $complianceReport->risk_level }}
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

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">QR Code</h3>
                </div>
                <div class="p-6">
                    @if($invoice->qr_data)
                    <div class="text-center">
                        @if($invoice->qr_image_url)
                        <img src="{{ $invoice->qr_image_url }}" alt="QR Code" class="mx-auto w-40 h-40 mb-3">
                        @endif
                        <p class="text-xs text-gray-400 font-mono break-all">{{ $invoice->qr_data }}</p>
                    </div>
                    @else
                    <div class="flex items-center justify-center h-40 bg-gray-100 rounded-lg border-2 border-dashed border-gray-300">
                        <p class="text-sm text-gray-400">QR Code will appear after PRAL submission</p>
                    </div>
                    @endif
                </div>
            </div>

            <div class="flex items-center justify-between">
                <a href="/invoice/{{ $invoice->id }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm font-medium hover:bg-gray-50 transition">Back to Invoice</a>
                <div class="flex items-center space-x-3">
                    <a href="/invoice/{{ $invoice->id }}/download" target="_blank" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Download PDF
                    </a>
                    @if($invoice->share_uuid)
                    <div class="flex items-center space-x-2">
                        <a href="https://wa.me/?text={{ urlencode('Invoice ' . $invoice->invoice_number . ': ' . url('/share/invoice/' . $invoice->share_uuid)) }}" target="_blank" class="inline-flex items-center px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-medium hover:bg-green-600 transition">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.5.5 0 00.612.638l4.682-1.23A11.94 11.94 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.387 0-4.588-.813-6.334-2.178l-.134-.107-3.39.892.907-3.313-.117-.14A9.935 9.935 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                            WhatsApp
                        </a>
                        <button onclick="navigator.clipboard.writeText('{{ url('/share/invoice/' . $invoice->share_uuid) }}').then(() => { this.textContent = 'Copied!'; setTimeout(() => { this.textContent = 'Copy Link'; }, 2000); })" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                            Copy Link
                        </button>
                    </div>
                    @endif
                    @if($invoice->status === 'draft')
                    <form method="POST" action="/invoice/{{ $invoice->id }}/validate" class="inline">
                        @csrf
                        <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition">
                            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            Validate Before PRAL
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
