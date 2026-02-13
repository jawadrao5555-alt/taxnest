<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="/dashboard" class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-emerald-100 hover:text-emerald-700 dark:hover:bg-emerald-900/30 dark:hover:text-emerald-400 transition" title="Back to Dashboard">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <h2 class="font-bold text-xl text-gray-800 leading-tight">Risk Explanation Report - {{ $report['period'] }}</h2>
            </div>
            <div class="flex items-center space-x-2">
                <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-xs font-semibold hover:bg-gray-700 transition">Print PDF</button>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 mb-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900">{{ $report['company']->name }}</h3>
                        <p class="text-sm text-gray-500">NTN: {{ $report['company']->ntn }} | Period: {{ $report['period'] }}</p>
                    </div>
                    <div class="text-right">
                        @php
                            $badge = \App\Services\HybridComplianceScorer::getRiskBadge($report['risk_level']);
                        @endphp
                        <span class="inline-flex px-4 py-2 rounded-full text-sm font-bold {{ $badge['bg'] }} {{ $badge['text'] }}">
                            {{ $report['risk_level'] }} RISK
                        </span>
                        <p class="text-3xl font-bold text-gray-900 mt-2">{{ $report['average_score'] }}/100</p>
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-4 mb-8">
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <p class="text-2xl font-bold text-green-700">{{ $report['risk_distribution']['LOW'] }}</p>
                        <p class="text-xs text-green-600">LOW Risk</p>
                    </div>
                    <div class="text-center p-4 bg-yellow-50 rounded-lg">
                        <p class="text-2xl font-bold text-yellow-700">{{ $report['risk_distribution']['MODERATE'] }}</p>
                        <p class="text-xs text-yellow-600">MODERATE</p>
                    </div>
                    <div class="text-center p-4 bg-orange-50 rounded-lg">
                        <p class="text-2xl font-bold text-orange-700">{{ $report['risk_distribution']['HIGH'] }}</p>
                        <p class="text-xs text-orange-600">HIGH Risk</p>
                    </div>
                    <div class="text-center p-4 bg-red-50 rounded-lg">
                        <p class="text-2xl font-bold text-red-700">{{ $report['risk_distribution']['CRITICAL'] }}</p>
                        <p class="text-xs text-red-600">CRITICAL</p>
                    </div>
                </div>

                <h4 class="text-lg font-semibold text-gray-800 mb-3">Compliance Flag Summary</h4>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <div class="p-4 rounded-lg {{ $report['flag_summary']['RATE_MISMATCH'] > 0 ? 'bg-red-50 border border-red-200' : 'bg-gray-50' }}">
                        <p class="text-xl font-bold {{ $report['flag_summary']['RATE_MISMATCH'] > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $report['flag_summary']['RATE_MISMATCH'] }}</p>
                        <p class="text-xs text-gray-600">Rate Mismatch</p>
                    </div>
                    <div class="p-4 rounded-lg {{ $report['flag_summary']['BUYER_RISK'] > 0 ? 'bg-red-50 border border-red-200' : 'bg-gray-50' }}">
                        <p class="text-xl font-bold {{ $report['flag_summary']['BUYER_RISK'] > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $report['flag_summary']['BUYER_RISK'] }}</p>
                        <p class="text-xs text-gray-600">Buyer Risk (S.23)</p>
                    </div>
                    <div class="p-4 rounded-lg {{ $report['flag_summary']['BANKING_RISK'] > 0 ? 'bg-red-50 border border-red-200' : 'bg-gray-50' }}">
                        <p class="text-xl font-bold {{ $report['flag_summary']['BANKING_RISK'] > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $report['flag_summary']['BANKING_RISK'] }}</p>
                        <p class="text-xs text-gray-600">Banking (S.73)</p>
                    </div>
                    <div class="p-4 rounded-lg {{ $report['flag_summary']['STRUCTURE_ERROR'] > 0 ? 'bg-red-50 border border-red-200' : 'bg-gray-50' }}">
                        <p class="text-xl font-bold {{ $report['flag_summary']['STRUCTURE_ERROR'] > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $report['flag_summary']['STRUCTURE_ERROR'] }}</p>
                        <p class="text-xs text-gray-600">Structure Error</p>
                    </div>
                </div>

                @if($report['risky_vendors']->count() > 0)
                <h4 class="text-lg font-semibold text-gray-800 mb-3">High-Risk Vendors</h4>
                <div class="overflow-x-auto mb-8">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor NTN</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoices</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rejected</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($report['risky_vendors'] as $vendor)
                            <tr>
                                <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $vendor->vendor_ntn }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $vendor->vendor_name ?? 'N/A' }}</td>
                                <td class="px-4 py-3"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ $vendor->vendor_score }}</span></td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $vendor->total_invoices }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $vendor->rejected_invoices }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                <div class="border-t border-gray-200 pt-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-400">Report generated: {{ $report['generated_at'] }}</p>
                            <p class="text-xs text-gray-400">Invoices scored: {{ $report['total_invoices_scored'] }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-400">SHA256 Verification Hash:</p>
                            <p class="text-xs font-mono text-gray-600 break-all max-w-md">{{ $report['report_hash'] }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
