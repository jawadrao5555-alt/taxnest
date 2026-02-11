<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <h2 class="font-bold text-xl text-gray-800 leading-tight">
                    Dashboard - {{ $company->name ?? 'My Company' }}
                </h2>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold {{ $riskBadge['bg'] }} {{ $riskBadge['text'] }}">
                    {{ $hybridScore }} - {{ $riskBadge['label'] }}
                </span>
            </div>
            <div class="flex items-center space-x-3">
                <a href="/compliance/certificate" target="_blank" class="inline-flex items-center px-3 py-2 bg-indigo-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition">
                    Certificate
                </a>
                <a href="/invoice/create" class="inline-flex items-center px-4 py-2 bg-emerald-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 transition">
                    + New Invoice
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(!empty($trialInfo) && $trialInfo['is_trial'])
            <div class="mb-6 bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Trial Mode Active</p>
                        <p class="text-xs text-blue-600">{{ $trialInfo['days_left'] }} days remaining (expires {{ $trialInfo['ends_at'] }})</p>
                    </div>
                </div>
                <a href="/billing/plans" class="px-4 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">Upgrade Now</a>
            </div>
            @endif

            @if(!empty($trialInfo) && !empty($trialInfo['is_expired']) && $trialInfo['is_expired'])
            <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                    <div>
                        <p class="text-sm font-semibold text-red-800">Trial Expired</p>
                        <p class="text-xs text-red-600">FBR submissions are blocked. Subscribe to continue.</p>
                    </div>
                </div>
                <a href="/billing/plans" class="px-4 py-1.5 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition">Subscribe Now</a>
            </div>
            @endif

            @if($notifications->count() > 0)
            <div class="mb-6 space-y-2">
                @foreach($notifications as $notif)
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 flex items-center space-x-3">
                    <svg class="w-5 h-5 text-yellow-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-yellow-800">{{ $notif->title }}</p>
                        <p class="text-xs text-yellow-600">{{ $notif->message }}</p>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            @php $usagePercent = $invoiceLimit > 0 ? min(100, ($invoicesUsed / $invoiceLimit) * 100) : 0; @endphp
            @if($usagePercent >= 80)
            <div class="mb-6 bg-orange-50 border border-orange-200 rounded-xl p-4 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                    <div>
                        <p class="text-sm font-semibold text-orange-800">Usage Warning - {{ round($usagePercent) }}% Used</p>
                        <p class="text-xs text-orange-600">You've used {{ $invoicesUsed }} of {{ $invoiceLimit }} invoices. Upgrade to avoid hitting your limit.</p>
                    </div>
                </div>
                <a href="/billing/plans" class="px-4 py-1.5 bg-orange-600 text-white text-xs font-semibold rounded-lg hover:bg-orange-700 transition">Upgrade Plan</a>
            </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Invoices</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1" x-data="{ count: 0 }" x-init="let target = {{ $totalInvoices }}; let interval = setInterval(() => { if(count < target) count++; else clearInterval(interval); }, 30)" x-text="count">0</p>
                        </div>
                        <div class="p-3 bg-blue-50 rounded-lg">
                            <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center space-x-2 text-sm">
                        <span class="text-yellow-600 font-medium">{{ $draftCount }} drafts</span>
                        <span class="text-gray-300">|</span>
                        <span class="text-blue-600 font-medium">{{ $submittedCount }} submitted</span>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Revenue</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">Rs. {{ number_format($totalRevenue) }}</p>
                        </div>
                        <div class="p-3 bg-emerald-50 rounded-lg">
                            <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">FBR Locked</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1" x-data="{ count: 0 }" x-init="let target = {{ $lockedCount }}; let interval = setInterval(() => { if(count < target) count++; else clearInterval(interval); }, 30)" x-text="count">0</p>
                        </div>
                        <div class="p-3 bg-purple-50 rounded-lg">
                            <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">FBR Success</p>
                            <p class="text-3xl font-bold {{ $fbrSuccessRate >= 80 ? 'text-green-600' : ($fbrSuccessRate >= 50 ? 'text-yellow-600' : 'text-red-600') }} mt-1">{{ $fbrSuccessRate }}%</p>
                        </div>
                        <div class="p-3 bg-indigo-50 rounded-lg">
                            <svg class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Plan Usage</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">{{ $invoicesUsed }}/{{ $invoiceLimit }}</p>
                        </div>
                        <div class="p-3 bg-orange-50 rounded-lg">
                            <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        </div>
                    </div>
                    @if($subscription)
                    <p class="mt-2 text-sm text-gray-500">{{ $subscription->pricingPlan->name }} Plan</p>
                    <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                        <div class="h-2 rounded-full {{ $usagePercent > 80 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $usagePercent }}%"></div>
                    </div>
                    @endif
                </div>
            </div>

            @if(count($smartInsights) > 0)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center space-x-2">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                    <span>Smart Insights</span>
                </h3>
                <div class="space-y-3">
                    @foreach($smartInsights as $insight)
                    <div class="flex items-start space-x-3 p-3 rounded-lg {{ $insight['type'] === 'danger' ? 'bg-red-50' : 'bg-yellow-50' }}">
                        <div class="flex-shrink-0 mt-0.5">
                            @if($insight['icon'] === 'clock')
                            <svg class="w-5 h-5 {{ $insight['type'] === 'danger' ? 'text-red-600' : 'text-yellow-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            @elseif($insight['icon'] === 'refresh')
                            <svg class="w-5 h-5 {{ $insight['type'] === 'danger' ? 'text-red-600' : 'text-yellow-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            @elseif($insight['icon'] === 'alert')
                            <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                            @else
                            <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                            @endif
                        </div>
                        <div>
                            <p class="text-sm font-semibold {{ $insight['type'] === 'danger' ? 'text-red-800' : 'text-yellow-800' }}">{{ $insight['title'] }}</p>
                            <p class="text-xs {{ $insight['type'] === 'danger' ? 'text-red-600' : 'text-yellow-600' }} mt-0.5">{{ $insight['message'] }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Draft Aging Breakdown</h3>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center p-4 bg-green-50 rounded-lg">
                            <p class="text-2xl font-bold text-green-700">{{ $draftAging['1_day'] }}</p>
                            <p class="text-sm text-green-600 mt-1">Less than 1 day</p>
                        </div>
                        <div class="text-center p-4 bg-yellow-50 rounded-lg">
                            <p class="text-2xl font-bold text-yellow-700">{{ $draftAging['3_days'] }}</p>
                            <p class="text-sm text-yellow-600 mt-1">1 - 3 days</p>
                        </div>
                        <div class="text-center p-4 bg-red-50 rounded-lg">
                            <p class="text-2xl font-bold text-red-700">{{ $draftAging['7_plus'] }}</p>
                            <p class="text-sm text-red-600 mt-1">7+ days old</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Industry Benchmark</h3>
                    <div class="flex items-center justify-between mb-4">
                        <div class="text-center">
                            <p class="text-3xl font-bold {{ $industryBenchmark['above_average'] ? 'text-green-600' : 'text-red-600' }}">{{ $complianceScore }}</p>
                            <p class="text-xs text-gray-500 mt-1">Your Score</p>
                        </div>
                        <div class="text-center px-6">
                            <p class="text-sm font-medium {{ $industryBenchmark['above_average'] ? 'text-green-600' : 'text-red-600' }}">
                                {{ $industryBenchmark['above_average'] ? 'Above' : 'Below' }} Average
                            </p>
                            <div class="flex items-center justify-center mt-1">
                                @if($industryBenchmark['above_average'])
                                <svg class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" /></svg>
                                @else
                                <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" /></svg>
                                @endif
                            </div>
                        </div>
                        <div class="text-center">
                            <p class="text-3xl font-bold text-gray-400">{{ $industryBenchmark['average'] }}</p>
                            <p class="text-xs text-gray-500 mt-1">Industry Avg</p>
                        </div>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3 relative">
                        <div class="h-3 rounded-full bg-indigo-500" style="width: {{ $complianceScore }}%"></div>
                        <div class="absolute top-0 h-3 w-0.5 bg-gray-800" style="left: {{ $industryBenchmark['average'] }}%"></div>
                    </div>
                    <div class="flex justify-between text-xs text-gray-400 mt-1">
                        <span>0</span>
                        <span>Industry Avg: {{ $industryBenchmark['average'] }}</span>
                        <span>100</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border {{ $riskBadge['border'] ?? 'border-gray-100' }} p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span>Audit Probability Meter</span>
                    </h3>
                    <div class="flex items-center justify-center mb-4">
                        <div class="relative w-40 h-40">
                            <canvas id="auditGauge" width="160" height="160"></canvas>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-3xl font-bold {{ $auditProbability['level'] === 'LOW' ? 'text-green-600' : ($auditProbability['level'] === 'MODERATE' ? 'text-yellow-600' : ($auditProbability['level'] === 'HIGH' ? 'text-orange-600' : 'text-red-600')) }}">{{ $auditProbability['probability'] }}%</span>
                                <span class="text-xs text-gray-500">Audit Risk</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-center">
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $auditProbability['level'] === 'LOW' ? 'bg-green-100 text-green-800' : ($auditProbability['level'] === 'MODERATE' ? 'bg-yellow-100 text-yellow-800' : ($auditProbability['level'] === 'HIGH' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800')) }}">
                            {{ $auditProbability['level'] }} RISK
                        </span>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="p-2 bg-gray-50 rounded">
                            <p class="font-bold text-gray-700">{{ $auditProbability['factors']['compliance_score'] }}</p>
                            <p class="text-gray-500">Score</p>
                        </div>
                        <div class="p-2 bg-gray-50 rounded">
                            <p class="font-bold text-gray-700">{{ $auditProbability['factors']['critical_reports_3m'] }}</p>
                            <p class="text-gray-500">Critical (3m)</p>
                        </div>
                        <div class="p-2 bg-gray-50 rounded">
                            <p class="font-bold text-gray-700">{{ $auditProbability['factors']['high_risk_reports_3m'] }}</p>
                            <p class="text-gray-500">High Risk (3m)</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        <span>Vendor Risk Panel</span>
                    </h3>
                    @if($vendorRisks->count() > 0)
                    <div class="space-y-3">
                        @foreach($vendorRisks as $vendor)
                        <div class="flex items-center justify-between p-3 rounded-lg {{ $vendor->vendor_score < 40 ? 'bg-red-50' : ($vendor->vendor_score < 70 ? 'bg-yellow-50' : 'bg-green-50') }}">
                            <div>
                                <p class="text-sm font-medium text-gray-800">{{ $vendor->vendor_name ?? $vendor->vendor_ntn }}</p>
                                <p class="text-xs text-gray-500">NTN: {{ $vendor->vendor_ntn }} | {{ $vendor->total_invoices }} invoices</p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold {{ $vendor->vendor_score < 40 ? 'text-red-600' : ($vendor->vendor_score < 70 ? 'text-yellow-600' : 'text-green-600') }}">{{ $vendor->vendor_score }}</p>
                                <p class="text-xs text-gray-500">Risk Score</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-center text-gray-400 py-8">No vendor risk data yet. Submit invoices to build vendor profiles.</p>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Invoice Status</h3>
                    <canvas id="statusChart" height="200"></canvas>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Monthly Invoices</h3>
                    <canvas id="monthlyChart" height="200"></canvas>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Compliance Trend (6 Months)</h3>
                    <canvas id="complianceChart" height="200"></canvas>
                </div>
            </div>

            @if($recentAnomalies->count() > 0)
            <div class="bg-white rounded-xl shadow-sm border border-red-100 p-6 mb-8">
                <h3 class="text-lg font-semibold text-red-800 mb-4 flex items-center space-x-2">
                    <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                    <span>Active Anomalies</span>
                </h3>
                <div class="space-y-2">
                    @foreach($recentAnomalies as $anomaly)
                    <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                        <div class="flex items-center space-x-3">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $anomaly->type === 'invoice_spike' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800' }}">
                                {{ str_replace('_', ' ', ucfirst($anomaly->type)) }}
                            </span>
                            <span class="text-sm text-gray-700">{{ $anomaly->description }}</span>
                        </div>
                        <span class="text-xs text-gray-500">{{ $anomaly->created_at->diffForHumans() }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Invoices</h3>
                        <a href="/invoices" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Buyer</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($recentInvoices as $invoice)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                        <a href="/invoice/{{ $invoice->id }}" class="text-emerald-600 hover:underline">{{ $invoice->invoice_number ?? 'INV-' . $invoice->id }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $invoice->buyer_name }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">Rs. {{ number_format($invoice->total_amount) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                            @if($invoice->status === 'draft') bg-yellow-100 text-yellow-800
                                            @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                            @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                            @endif">{{ ucfirst($invoice->status) }}</span>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No invoices yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Activity</h3>
                    </div>
                    <div class="p-4 max-h-96 overflow-y-auto">
                        @forelse($recentActivity as $activity)
                        <div class="flex items-start space-x-3 py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                            <div class="flex-shrink-0 mt-1">
                                @if($activity->action === 'created')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-green-100"><svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span>
                                @elseif($activity->action === 'edited')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100"><svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                                @elseif($activity->action === 'submitted')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-100"><svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg></span>
                                @elseif($activity->action === 'locked')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-emerald-100"><svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></span>
                                @else
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-100"><svg class="w-4 h-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800">
                                    <span class="font-medium">{{ $activity->user->name ?? 'System' }}</span>
                                    <span class="text-gray-500">{{ $activity->action }}</span>
                                    <a href="/invoice/{{ $activity->invoice_id }}" class="text-emerald-600 hover:underline font-medium">Invoice #{{ $activity->invoice->invoice_number ?? $activity->invoice_id }}</a>
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $activity->created_at->diffForHumans() }} &middot; {{ $activity->ip_address }}</p>
                            </div>
                        </div>
                        @empty
                        <p class="text-center text-gray-400 py-6">No activity yet</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Draft', 'Submitted', 'Locked'],
                    datasets: [{
                        data: [{{ $statusData['draft'] }}, {{ $statusData['submitted'] }}, {{ $statusData['locked'] }}],
                        backgroundColor: ['#fbbf24', '#3b82f6', '#10b981'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });

            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: {!! json_encode($monthlyData->pluck('month_label')) !!},
                    datasets: [{
                        label: 'Invoices',
                        data: {!! json_encode($monthlyData->pluck('count')) !!},
                        backgroundColor: '#10b981',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });

            const complianceCtx = document.getElementById('complianceChart').getContext('2d');
            new Chart(complianceCtx, {
                type: 'line',
                data: {
                    labels: {!! json_encode(collect($complianceTrend)->pluck('month')) !!},
                    datasets: [{
                        label: 'Compliance %',
                        data: {!! json_encode(collect($complianceTrend)->pluck('score')) !!},
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });

            const gaugeCanvas = document.getElementById('auditGauge');
            if (gaugeCanvas) {
                const gCtx = gaugeCanvas.getContext('2d');
                const prob = {{ $auditProbability['probability'] }};
                const cx = 80, cy = 90, r = 60;
                const startAngle = Math.PI;
                const endAngle = 2 * Math.PI;
                const valueAngle = startAngle + (prob / 100) * Math.PI;

                gCtx.beginPath();
                gCtx.arc(cx, cy, r, startAngle, endAngle);
                gCtx.lineWidth = 12;
                gCtx.strokeStyle = '#e5e7eb';
                gCtx.lineCap = 'round';
                gCtx.stroke();

                gCtx.beginPath();
                gCtx.arc(cx, cy, r, startAngle, valueAngle);
                gCtx.lineWidth = 12;
                gCtx.strokeStyle = prob < 25 ? '#10b981' : (prob < 50 ? '#f59e0b' : (prob < 70 ? '#f97316' : '#ef4444'));
                gCtx.lineCap = 'round';
                gCtx.stroke();
            }
        });
    </script>
</x-app-layout>
