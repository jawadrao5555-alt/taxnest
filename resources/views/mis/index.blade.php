<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">MIS Reports</h2>
            <div class="flex items-center space-x-2">
                <a href="{{ route('mis.export', ['type' => 'monthly']) }}" class="inline-flex items-center px-3 py-2 bg-emerald-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 transition">
                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    Monthly
                </a>
                <a href="{{ route('mis.export', ['type' => 'tax']) }}" class="inline-flex items-center px-3 py-2 bg-blue-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    Tax
                </a>
                <a href="{{ route('mis.export', ['type' => 'hs']) }}" class="inline-flex items-center px-3 py-2 bg-indigo-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    HS Code
                </a>
                <a href="{{ route('mis.export', ['type' => 'vendor']) }}" class="inline-flex items-center px-3 py-2 bg-orange-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-orange-700 transition">
                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    Vendor
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <p class="text-sm font-medium text-gray-500">Current Month Invoices</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $monthlySummary['current_count'] }}</p>
                    <p class="text-sm mt-2 {{ $monthlySummary['growth_count'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        {{ $monthlySummary['growth_count'] >= 0 ? '+' : '' }}{{ $monthlySummary['growth_count'] }}% vs last month ({{ $monthlySummary['last_count'] }})
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <p class="text-sm font-medium text-gray-500">Current Month Revenue</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">Rs. {{ number_format($monthlySummary['current_revenue']) }}</p>
                    <p class="text-sm mt-2 {{ $monthlySummary['growth_revenue'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        {{ $monthlySummary['growth_revenue'] >= 0 ? '+' : '' }}{{ $monthlySummary['growth_revenue'] }}% vs last month
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <p class="text-sm font-medium text-gray-500">Last Month Invoices</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $monthlySummary['last_count'] }}</p>
                    <p class="text-sm text-gray-400 mt-2">Previous period</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <p class="text-sm font-medium text-gray-500">Last Month Revenue</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">Rs. {{ number_format($monthlySummary['last_revenue']) }}</p>
                    <p class="text-sm text-gray-400 mt-2">Previous period</p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center space-x-2">
                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                    <span>Tax Collected Summary (Last 6 Months)</span>
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Month</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tax Collected</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Effective Rate</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($taxSummary as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $row['month'] }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">Rs. {{ number_format($row['tax_collected'], 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">Rs. {{ number_format($row['subtotal'], 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $row['effective_rate'] > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $row['effective_rate'] }}%
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center space-x-2">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
                    <span>HS Code Concentration Report</span>
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Prefix</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Item Count</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Value</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Tax</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($hsConcentration as $hs)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800">{{ $hs->hs_prefix ?? 'N/A' }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">{{ $hs->item_count }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">Rs. {{ number_format($hs->total_value, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">Rs. {{ number_format($hs->total_tax, 2) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No HS code data available</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center space-x-2">
                    <svg class="w-5 h-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span>Vendor Risk Ranking</span>
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor Name</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">NTN</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Score</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Invoices</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Rejected</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tax Mismatches</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($vendorRanking as $vendor)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $vendor->vendor_name ?? 'Unknown' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $vendor->vendor_ntn }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                                        {{ $vendor->vendor_score < 40 ? 'bg-red-100 text-red-800' : ($vendor->vendor_score < 70 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') }}">
                                        {{ $vendor->vendor_score }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">{{ $vendor->total_invoices }}</td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <span class="{{ $vendor->rejected_invoices > 0 ? 'text-red-600 font-medium' : 'text-gray-700' }}">{{ $vendor->rejected_invoices }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <span class="{{ $vendor->tax_mismatches > 0 ? 'text-orange-600 font-medium' : 'text-gray-700' }}">{{ $vendor->tax_mismatches }}</span>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No vendor risk data available</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
