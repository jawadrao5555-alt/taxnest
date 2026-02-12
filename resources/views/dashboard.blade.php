<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                    Dashboard
                </h2>
                <span class="text-sm text-gray-500 dark:text-gray-400 font-medium">{{ $company->name ?? 'My Company' }}</span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $riskBadge['bg'] }} {{ $riskBadge['text'] }}">
                    {{ $hybridScore }} - {{ $riskBadge['label'] }}
                </span>
            </div>
            <div class="flex items-center space-x-2">
                <a href="/compliance/certificate" target="_blank" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 rounded-lg text-xs font-semibold text-white hover:bg-indigo-700 transition">
                    Certificate
                </a>
                <a href="/invoice/create" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 rounded-lg text-xs font-semibold text-white hover:bg-emerald-700 transition">
                    + New Invoice
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(!empty($trialInfo) && $trialInfo['is_trial'])
            <div class="mb-4 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-3 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-blue-100 dark:bg-blue-800 rounded-lg">
                        <svg class="w-4 h-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Trial Mode - {{ $trialInfo['days_left'] }} days left</p>
                    </div>
                </div>
                <a href="/billing/plans" class="px-3 py-1 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">Upgrade</a>
            </div>
            @endif

            @if(!empty($trialInfo) && !empty($trialInfo['is_expired']) && $trialInfo['is_expired'])
            <div class="mb-4 bg-gradient-to-r from-red-50 to-rose-50 dark:from-red-900/20 dark:to-rose-900/20 border border-red-200 dark:border-red-800 rounded-xl p-3 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-red-100 dark:bg-red-800 rounded-lg">
                        <svg class="w-4 h-4 text-red-600 dark:text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-red-800 dark:text-red-200">Trial Expired - FBR submissions blocked</p>
                    </div>
                </div>
                <a href="/billing/plans" class="px-3 py-1 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition">Subscribe</a>
            </div>
            @endif

            @if($notifications->count() > 0)
            <div class="mb-4 space-y-1.5">
                @foreach($notifications as $notif)
                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-2.5 flex items-center space-x-2">
                    <svg class="w-4 h-4 text-amber-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                    <p class="text-sm text-amber-800 dark:text-amber-200"><span class="font-semibold">{{ $notif->title }}</span> - {{ $notif->message }}</p>
                </div>
                @endforeach
            </div>
            @endif

            @php $usagePercent = $invoiceLimit > 0 ? min(100, ($invoicesUsed / $invoiceLimit) * 100) : 0; @endphp
            @if($usagePercent >= 80)
            <div class="mb-4 bg-gradient-to-r from-orange-50 to-amber-50 dark:from-orange-900/20 dark:to-amber-900/20 border border-orange-200 dark:border-orange-800 rounded-xl p-3 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-orange-100 dark:bg-orange-800 rounded-lg">
                        <svg class="w-4 h-4 text-orange-600 dark:text-orange-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                    </div>
                    <p class="text-sm font-semibold text-orange-800 dark:text-orange-200">{{ round($usagePercent) }}% Used - {{ $invoicesUsed }}/{{ $invoiceLimit }} invoices</p>
                </div>
                <a href="/billing/plans" class="px-3 py-1 bg-orange-600 text-white text-xs font-semibold rounded-lg hover:bg-orange-700 transition">Upgrade</a>
            </div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
                <div class="bg-gradient-to-br from-white to-blue-50/50 dark:from-gray-800 dark:to-blue-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-blue-100 dark:bg-blue-800 rounded-lg">
                            <svg class="w-4 h-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-data="{ count: 0 }" x-init="let target = {{ $totalInvoices }}; let interval = setInterval(() => { if(count < target) count++; else clearInterval(interval); }, 30)" x-text="count">0</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Total Invoices</p>
                    <div class="mt-2 flex items-center space-x-1.5 text-xs">
                        <span class="text-amber-600 font-medium">{{ $draftCount }}d</span>
                        <span class="text-gray-300">|</span>
                        <span class="text-blue-600 font-medium">{{ $submittedCount }}s</span>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-white to-emerald-50/50 dark:from-gray-800 dark:to-emerald-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-emerald-100 dark:bg-emerald-800 rounded-lg">
                            <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">Rs. {{ number_format($totalRevenue) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Total Revenue</p>
                </div>

                <div class="bg-gradient-to-br from-white to-purple-50/50 dark:from-gray-800 dark:to-purple-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-purple-100 dark:bg-purple-800 rounded-lg">
                            <svg class="w-4 h-4 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-data="{ count: 0 }" x-init="let target = {{ $lockedCount }}; let interval = setInterval(() => { if(count < target) count++; else clearInterval(interval); }, 30)" x-text="count">0</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">FBR Locked</p>
                </div>

                <div class="bg-gradient-to-br from-white to-indigo-50/50 dark:from-gray-800 dark:to-indigo-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-indigo-100 dark:bg-indigo-800 rounded-lg">
                            <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold {{ $fbrSuccessRate >= 80 ? 'text-emerald-600' : ($fbrSuccessRate >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $fbrSuccessRate }}%</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">FBR Success</p>
                </div>

                <div class="bg-gradient-to-br from-white to-orange-50/50 dark:from-gray-800 dark:to-orange-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-orange-100 dark:bg-orange-800 rounded-lg">
                            <svg class="w-4 h-4 text-orange-600 dark:text-orange-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $invoicesUsed }}<span class="text-sm text-gray-400">/{{ $invoiceLimit }}</span></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Plan Usage</p>
                    @if($subscription)
                    <div class="mt-2 w-full bg-gray-200 dark:bg-gray-600 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full {{ $usagePercent > 80 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $usagePercent }}%"></div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">{{ $subscription->pricingPlan->name }}</p>
                    @endif
                </div>
            </div>

            @if($planTier === 'retail')
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Recent Invoices</h3>
                    <a href="/invoices" class="text-xs text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoice #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Buyer</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($recentInvoices->take(5) as $inv)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-3 py-2 text-sm font-mono font-medium text-gray-900 dark:text-gray-100">{{ $inv->invoice_number ?? 'INV-'.$inv->id }}</td>
                            <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $inv->buyer_name }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-bold
                                    @if($inv->status === 'locked') bg-green-100 text-green-800
                                    @elseif($inv->status === 'submitted') bg-blue-100 text-blue-800
                                    @elseif($inv->status === 'draft') bg-gray-100 text-gray-700
                                    @else bg-red-100 text-red-800
                                    @endif">{{ ucfirst($inv->status) }}</span>
                            </td>
                            <td class="px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 text-right">Rs. {{ number_format($inv->total_amount, 2) }}</td>
                            <td class="px-3 py-2 text-sm text-gray-500 text-right">{{ $inv->created_at->format('d M Y') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No invoices yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif

            @if($planTier !== 'retail')
            @if(count($smartInsights) > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 mb-4">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3 flex items-center space-x-2">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                    <span>Smart Insights</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    @foreach($smartInsights as $insight)
                    <div class="flex items-start space-x-2 p-2 rounded-lg {{ $insight['type'] === 'danger' ? 'bg-red-50 dark:bg-red-900/20' : 'bg-amber-50 dark:bg-amber-900/20' }}">
                        <div class="flex-shrink-0 mt-0.5">
                            @if($insight['icon'] === 'clock')
                            <svg class="w-4 h-4 {{ $insight['type'] === 'danger' ? 'text-red-500' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            @elseif($insight['icon'] === 'refresh')
                            <svg class="w-4 h-4 {{ $insight['type'] === 'danger' ? 'text-red-500' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            @else
                            <svg class="w-4 h-4 {{ $insight['type'] === 'danger' ? 'text-red-500' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                            @endif
                        </div>
                        <div>
                            <p class="text-xs font-semibold {{ $insight['type'] === 'danger' ? 'text-red-800 dark:text-red-300' : 'text-amber-800 dark:text-amber-300' }}">{{ $insight['title'] }}</p>
                            <p class="text-xs {{ $insight['type'] === 'danger' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">{{ $insight['message'] }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            @endif

            @if($planTier !== 'retail')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Draft Aging</h3>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="text-center p-2 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <p class="text-lg font-bold text-green-700 dark:text-green-400">{{ $draftAging['1_day'] }}</p>
                            <p class="text-xs text-green-600 dark:text-green-500">&lt;1 day</p>
                        </div>
                        <div class="text-center p-2 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                            <p class="text-lg font-bold text-amber-700 dark:text-amber-400">{{ $draftAging['3_days'] }}</p>
                            <p class="text-xs text-amber-600 dark:text-amber-500">1-3 days</p>
                        </div>
                        <div class="text-center p-2 bg-red-50 dark:bg-red-900/20 rounded-lg">
                            <p class="text-lg font-bold text-red-700 dark:text-red-400">{{ $draftAging['7_plus'] }}</p>
                            <p class="text-xs text-red-600 dark:text-red-500">7+ days</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Industry Benchmark</h3>
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-center">
                            <p class="text-2xl font-bold {{ $industryBenchmark['above_average'] ? 'text-emerald-600' : 'text-red-600' }}">{{ $complianceScore }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Your Score</p>
                        </div>
                        <div class="text-center px-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold {{ $industryBenchmark['above_average'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">
                                {{ $industryBenchmark['above_average'] ? 'Above' : 'Below' }} Avg
                            </span>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-gray-400">{{ $industryBenchmark['average'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Industry</p>
                        </div>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2 relative">
                        <div class="h-2 rounded-full bg-indigo-500" style="width: {{ $complianceScore }}%"></div>
                        <div class="absolute top-0 h-2 w-0.5 bg-gray-800 dark:bg-gray-200" style="left: {{ $industryBenchmark['average'] }}%"></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border {{ $riskBadge['border'] ?? 'border-gray-100' }} dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span>Audit Probability</span>
                    </h3>
                    <div class="flex items-center space-x-4 mb-3">
                        <div class="relative w-24 h-24 flex-shrink-0">
                            <canvas id="auditGauge" width="96" height="96"></canvas>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-lg font-bold {{ $auditProbability['level'] === 'LOW' ? 'text-green-600' : ($auditProbability['level'] === 'MODERATE' ? 'text-amber-600' : ($auditProbability['level'] === 'HIGH' ? 'text-orange-600' : 'text-red-600')) }}">{{ $auditProbability['probability'] }}%</span>
                                <span class="text-xs text-gray-400">Risk</span>
                            </div>
                        </div>
                        <div class="flex-1">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-bold mb-2 {{ $auditProbability['level'] === 'LOW' ? 'bg-green-100 text-green-800' : ($auditProbability['level'] === 'MODERATE' ? 'bg-amber-100 text-amber-800' : ($auditProbability['level'] === 'HIGH' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800')) }}">
                                {{ $auditProbability['level'] }}
                            </span>
                            <div class="grid grid-cols-3 gap-1.5 mt-2">
                                <div class="p-1.5 bg-gray-50 dark:bg-gray-700 rounded text-center">
                                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300">{{ $auditProbability['factors']['compliance_score'] }}</p>
                                    <p class="text-xs text-gray-400">Score</p>
                                </div>
                                <div class="p-1.5 bg-gray-50 dark:bg-gray-700 rounded text-center">
                                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300">{{ $auditProbability['factors']['critical_reports_3m'] }}</p>
                                    <p class="text-xs text-gray-400">Critical</p>
                                </div>
                                <div class="p-1.5 bg-gray-50 dark:bg-gray-700 rounded text-center">
                                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300">{{ $auditProbability['factors']['active_anomalies'] }}</p>
                                    <p class="text-xs text-gray-400">Anomalies</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-2 bg-indigo-50 dark:bg-indigo-900/20 rounded text-xs text-center">
                        <p class="text-indigo-600 dark:text-indigo-400 font-mono">{{ $complianceDetails['formula'] }}</p>
                    </div>
                </div>

                @if($planTier === 'enterprise')
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        <span>Vendor Risk</span>
                    </h3>
                    @if($vendorRisks->count() > 0)
                    <div class="space-y-2">
                        @foreach($vendorRisks as $vendor)
                        <div class="flex items-center justify-between p-2 rounded-lg {{ $vendor->vendor_score < 40 ? 'bg-red-50 dark:bg-red-900/20' : ($vendor->vendor_score < 70 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-green-50 dark:bg-green-900/20') }}">
                            <div>
                                <p class="text-xs font-medium text-gray-800 dark:text-gray-200">{{ $vendor->vendor_name ?? $vendor->vendor_ntn }}</p>
                                <p class="text-xs text-gray-400">{{ $vendor->total_invoices }} inv</p>
                            </div>
                            <p class="text-sm font-bold {{ $vendor->vendor_score < 40 ? 'text-red-600' : ($vendor->vendor_score < 70 ? 'text-amber-600' : 'text-green-600') }}">{{ $vendor->vendor_score }}</p>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-center text-gray-400 dark:text-gray-500 py-6 text-xs">No vendor risk data yet</p>
                    @endif
                </div>
                @endif
            </div>

            @if($companyRiskSummary['total_active_risks'] > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-orange-200 dark:border-orange-800 overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-orange-100 dark:border-orange-800 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span>Risk Summary</span>
                    </h3>
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-bold bg-orange-100 text-orange-800">{{ $companyRiskSummary['total_active_risks'] }} Active</span>
                </div>
                <div class="p-3">
                    <div class="grid grid-cols-4 gap-2">
                        <div class="p-2 bg-red-50 dark:bg-red-900/20 rounded-lg text-center">
                            <p class="text-base font-bold text-red-700 dark:text-red-400">{{ $companyRiskSummary['severity_breakdown']['high'] }}</p>
                            <p class="text-xs text-red-600">High</p>
                        </div>
                        <div class="p-2 bg-amber-50 dark:bg-amber-900/20 rounded-lg text-center">
                            <p class="text-base font-bold text-amber-700 dark:text-amber-400">{{ $companyRiskSummary['severity_breakdown']['medium'] }}</p>
                            <p class="text-xs text-amber-600">Medium</p>
                        </div>
                        <div class="p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-center">
                            <p class="text-base font-bold text-blue-700 dark:text-blue-400">{{ $companyRiskSummary['severity_breakdown']['low'] }}</p>
                            <p class="text-xs text-blue-600">Low</p>
                        </div>
                        <div class="p-2 bg-gray-50 dark:bg-gray-700 rounded-lg text-center">
                            <p class="text-base font-bold text-gray-700 dark:text-gray-300">{{ count($companyRiskSummary['risks_by_type']) }}</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">Types</p>
                        </div>
                    </div>
                    @if(!empty($companyRiskSummary['risks_by_type']))
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach($companyRiskSummary['risks_by_type'] as $type => $count)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $type)) }}: {{ $count }}</span>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
            @endif
            @endif

            @if($planTier !== 'retail')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Invoice Status</h3>
                    <div style="height: 140px;"><canvas id="statusChart"></canvas></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Monthly Invoices</h3>
                    <div style="height: 140px;"><canvas id="monthlyChart"></canvas></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Compliance Trend</h3>
                    <div style="height: 140px;"><canvas id="complianceChart"></canvas></div>
                </div>
            </div>
            @endif

            @if($planTier === 'enterprise')
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-indigo-200 dark:border-indigo-800 p-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                        <span>Risk Heatmap</span>
                    </h3>
                    <a href="/executive-dashboard" class="inline-flex items-center px-2.5 py-1 bg-indigo-600 text-white text-xs font-semibold rounded-lg hover:bg-indigo-700 transition">Executive View</a>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <div>
                        <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">By HS Category</h4>
                        <div class="space-y-1.5">
                            @foreach($riskHeatmapData['hs_categories'] ?? [] as $hs)
                            <div class="flex items-center justify-between p-2 rounded {{ $hs['risk_pct'] > 60 ? 'bg-red-50 dark:bg-red-900/20' : ($hs['risk_pct'] > 30 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-green-50 dark:bg-green-900/20') }}">
                                <span class="text-xs font-mono font-medium text-gray-700 dark:text-gray-300">{{ $hs['label'] }}</span>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-gray-400">{{ $hs['total'] }}</span>
                                    <span class="text-xs font-bold {{ $hs['risk_pct'] > 60 ? 'text-red-600' : ($hs['risk_pct'] > 30 ? 'text-amber-600' : 'text-green-600') }}">{{ $hs['risk_pct'] }}%</span>
                                    <div class="w-12 bg-gray-200 dark:bg-gray-600 rounded-full h-1.5">
                                        <div class="h-1.5 rounded-full {{ $hs['risk_pct'] > 60 ? 'bg-red-500' : ($hs['risk_pct'] > 30 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ min(100, $hs['risk_pct']) }}%"></div>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                            @if(empty($riskHeatmapData['hs_categories'] ?? []))
                            <p class="text-xs text-gray-400 text-center py-3">No HS data yet</p>
                            @endif
                        </div>
                    </div>
                    <div>
                        <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">By Branch</h4>
                        <div class="space-y-1.5">
                            @foreach($riskHeatmapData['branches'] ?? [] as $br)
                            <div class="flex items-center justify-between p-2 rounded {{ $br['risk_pct'] > 60 ? 'bg-red-50 dark:bg-red-900/20' : ($br['risk_pct'] > 30 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-green-50 dark:bg-green-900/20') }}">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $br['label'] }}</span>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-gray-400">{{ $br['total_invoices'] }} inv</span>
                                    <span class="text-xs font-bold {{ $br['risk_pct'] > 60 ? 'text-red-600' : ($br['risk_pct'] > 30 ? 'text-amber-600' : 'text-green-600') }}">{{ $br['risk_pct'] }}%</span>
                                    <div class="w-12 bg-gray-200 dark:bg-gray-600 rounded-full h-1.5">
                                        <div class="h-1.5 rounded-full {{ $br['risk_pct'] > 60 ? 'bg-red-500' : ($br['risk_pct'] > 30 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ min(100, $br['risk_pct']) }}%"></div>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                            @if(empty($riskHeatmapData['branches'] ?? []))
                            <p class="text-xs text-gray-400 text-center py-3">No branch data yet</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 mb-4">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3 flex items-center space-x-2">
                    <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                    <span>Audit Probability Engine</span>
                </h3>
                <div class="grid grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-2 mb-3">
                    @foreach($auditEngine['factors'] as $key => $factor)
                    <div class="text-center p-2 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5 truncate">{{ $factor['label'] }}</p>
                        <p class="text-sm font-bold {{ $factor['weight'] > 50 ? 'text-red-600' : ($factor['weight'] > 25 ? 'text-amber-600' : 'text-green-600') }}">{{ $factor['value'] }}</p>
                        <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-1 mt-1.5">
                            <div class="h-1 rounded-full {{ $factor['weight'] > 50 ? 'bg-red-500' : ($factor['weight'] > 25 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ min(100, $factor['weight']) }}%"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="flex items-center justify-between p-2 rounded-lg" style="background-color: {{ $auditEngine['color'] }}15; border: 1px solid {{ $auditEngine['color'] }}30">
                    <div>
                        <span class="text-xs font-semibold" style="color: {{ $auditEngine['color'] }}">Audit: {{ $auditEngine['probability'] }}%</span>
                        <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full font-bold text-white" style="background-color: {{ $auditEngine['color'] }}">{{ $auditEngine['level'] }}</span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-mono">{{ $auditEngine['formula'] }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">MoM Growth (6M)</h3>
                    <div style="height: 160px;"><canvas id="momGrowthChart"></canvas></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Tax Variance</h3>
                    <div style="height: 160px;"><canvas id="taxVarianceChart"></canvas></div>
                </div>
            </div>

            @if($hsRiskData->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 mb-4">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Risk Heatmap by HS Code</h3>
                <div style="height: 160px;"><canvas id="hsRiskChart"></canvas></div>
            </div>
            @endif
            @endif

            @if($recentAnomalies->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-red-100 dark:border-red-800 p-4 mb-4">
                <h3 class="text-sm font-semibold text-red-800 dark:text-red-300 mb-3 flex items-center space-x-2">
                    <svg class="w-4 h-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                    <span>Active Anomalies</span>
                </h3>
                <div class="space-y-1.5">
                    @foreach($recentAnomalies as $anomaly)
                    <div class="flex items-center justify-between p-2 bg-red-50 dark:bg-red-900/20 rounded-lg">
                        <div class="flex items-center space-x-2">
                            <span class="inline-flex px-1.5 py-0.5 rounded-full text-xs font-medium {{ $anomaly->type === 'invoice_spike' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800' }}">
                                {{ str_replace('_', ' ', ucfirst($anomaly->type)) }}
                            </span>
                            <span class="text-xs text-gray-700 dark:text-gray-300">{{ $anomaly->description }}</span>
                        </div>
                        <span class="text-xs text-gray-400">{{ $anomaly->created_at->diffForHumans() }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            @if($planTier === 'enterprise')
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <div class="bg-gradient-to-br from-white to-emerald-50/50 dark:from-gray-800 dark:to-emerald-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Compliance %</p>
                            <p class="text-xl font-bold mt-1 {{ $kpis['compliance_percent'] >= 70 ? 'text-emerald-600' : ($kpis['compliance_percent'] >= 40 ? 'text-orange-600' : 'text-red-600') }}">{{ $kpis['compliance_percent'] }}%</p>
                        </div>
                        <div class="p-2 bg-emerald-100 dark:bg-emerald-800 rounded-lg">
                            <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Locked / Total</p>
                </div>
                <div class="bg-gradient-to-br from-white to-blue-50/50 dark:from-gray-800 dark:to-blue-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Avg Invoice</p>
                            <p class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-1">Rs. {{ number_format($kpis['avg_invoice_value']) }}</p>
                        </div>
                        <div class="p-2 bg-blue-100 dark:bg-blue-800 rounded-lg">
                            <svg class="w-4 h-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Per invoice avg</p>
                </div>
                <div class="bg-gradient-to-br from-white to-red-50/50 dark:from-gray-800 dark:to-red-900/10 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Rejection Rate</p>
                            <p class="text-xl font-bold mt-1 {{ $kpis['rejection_rate'] <= 10 ? 'text-emerald-600' : ($kpis['rejection_rate'] <= 30 ? 'text-orange-600' : 'text-red-600') }}">{{ $kpis['rejection_rate'] }}%</p>
                        </div>
                        <div class="p-2 bg-red-100 dark:bg-red-800 rounded-lg">
                            <svg class="w-4 h-4 text-red-600 dark:text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Failed / Total</p>
                </div>
            </div>
            @endif

            @if($planTier === 'enterprise')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
                @if($topCustomers->count() > 0)
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Top 5 Customers</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Customer</th>
                                <th class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total</th>
                                <th class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Inv</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($topCustomers as $cust)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-3 py-1.5 text-xs font-medium text-gray-900 dark:text-gray-100">{{ $cust->buyer_name }}</td>
                                <td class="px-3 py-1.5 text-xs font-semibold text-emerald-600 text-right">Rs. {{ number_format($cust->total_amount) }}</td>
                                <td class="px-3 py-1.5 text-xs text-gray-500 text-right">{{ $cust->invoice_count }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                @if($branchComparison->count() > 0)
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Branch Comparison</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Branch</th>
                                <th class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Inv</th>
                                <th class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Revenue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($branchComparison as $branch)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-3 py-1.5 text-xs font-medium text-gray-900 dark:text-gray-100">{{ $branch->branch_name }}</td>
                                <td class="px-3 py-1.5 text-xs text-gray-500 text-right">{{ $branch->invoice_count }}</td>
                                <td class="px-3 py-1.5 text-xs font-semibold text-emerald-600 text-right">Rs. {{ number_format($branch->total_revenue) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Recent Invoices</h3>
                        <a href="/invoices" class="text-xs text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
                    </div>
                    <div class="p-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @forelse($recentInvoices as $invoice)
                        <a href="/invoice/{{ $invoice->id }}" class="block p-2.5 rounded-lg border border-gray-200 dark:border-gray-600 hover:border-emerald-300 hover:shadow-sm transition group">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-xs font-mono text-gray-500 dark:text-gray-400">{{ $invoice->invoice_number ?? 'INV-' . $invoice->id }}</span>
                                <span class="inline-flex px-1.5 py-0.5 rounded-full text-xs font-bold
                                    @if($invoice->status === 'draft') bg-gray-200 text-gray-700
                                    @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                    @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                    @else bg-red-100 text-red-800
                                    @endif">{{ ucfirst($invoice->status) }}</span>
                            </div>
                            <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $invoice->buyer_name }}</p>
                            <div class="flex items-center justify-between mt-1.5">
                                <span class="text-xs font-bold text-emerald-600">Rs. {{ number_format($invoice->total_amount) }}</span>
                                <span class="text-xs text-gray-400">{{ $invoice->created_at->format('d M') }}</span>
                            </div>
                        </a>
                        @empty
                        <div class="col-span-2 text-center py-6 text-gray-400 text-xs">No invoices yet</div>
                        @endforelse
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Recent Activity</h3>
                    </div>
                    <div class="p-3 max-h-72 overflow-y-auto">
                        @forelse($recentActivity as $activity)
                        <div class="flex items-start space-x-2 py-1.5 {{ !$loop->last ? 'border-b border-gray-50 dark:border-gray-700' : '' }}">
                            <div class="flex-shrink-0 mt-0.5">
                                @if($activity->action === 'created')
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-green-100 dark:bg-green-800"><svg class="w-3 h-3 text-green-600 dark:text-green-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span>
                                @elseif($activity->action === 'edited')
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-800"><svg class="w-3 h-3 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                                @elseif($activity->action === 'submitted')
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-800"><svg class="w-3 h-3 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg></span>
                                @elseif($activity->action === 'locked')
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-800"><svg class="w-3 h-3 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></span>
                                @else
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 dark:bg-gray-700"><svg class="w-3 h-3 text-gray-600 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-gray-800 dark:text-gray-200">
                                    <span class="font-medium">{{ $activity->user->name ?? 'System' }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ $activity->action }}</span>
                                    <a href="/invoice/{{ $activity->invoice_id }}" class="text-emerald-600 hover:underline font-medium">#{{ $activity->invoice->invoice_number ?? $activity->invoice_id }}</a>
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $activity->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                        @empty
                        <p class="text-center text-gray-400 py-4 text-xs">No activity yet</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chartFont = { family: "'Inter', sans-serif", size: 10 };
            const gridColor = 'rgba(0,0,0,0.05)';

            const statusEl = document.getElementById('statusChart');
            if (statusEl) {
            new Chart(statusEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Draft', 'Submitted', 'Locked'],
                    datasets: [{
                        data: [{{ $statusData['draft'] }}, {{ $statusData['submitted'] }}, {{ $statusData['locked'] }}],
                        backgroundColor: ['#fbbf24', '#3b82f6', '#10b981'],
                        borderWidth: 0,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: { legend: { position: 'right', labels: { font: chartFont, boxWidth: 8, padding: 8 } } }
                }
            });
            }

            const monthlyEl = document.getElementById('monthlyChart');
            if (monthlyEl) {
            new Chart(monthlyEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: {!! json_encode($monthlyData->pluck('month_label')) !!},
                    datasets: [{
                        label: 'Invoices',
                        data: {!! json_encode($monthlyData->pluck('count')) !!},
                        backgroundColor: '#10b981',
                        borderRadius: 4,
                        barThickness: 16
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, font: chartFont }, grid: { color: gridColor } },
                        x: { ticks: { font: chartFont }, grid: { display: false } }
                    }
                }
            });
            }

            const complianceEl = document.getElementById('complianceChart');
            if (complianceEl) {
            new Chart(complianceEl.getContext('2d'), {
                type: 'line',
                data: {
                    labels: {!! json_encode(collect($complianceTrend)->pluck('month')) !!},
                    datasets: [{
                        label: 'Compliance %',
                        data: {!! json_encode(collect($complianceTrend)->pluck('score')) !!},
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.08)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#6366f1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { font: chartFont }, grid: { color: gridColor } },
                        x: { ticks: { font: chartFont }, grid: { display: false } }
                    }
                }
            });
            }

            const momEl = document.getElementById('momGrowthChart');
            if (momEl) {
            new Chart(momEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: {!! json_encode(collect($momGrowth)->pluck('month')) !!},
                    datasets: [{
                        label: 'Count',
                        data: {!! json_encode(collect($momGrowth)->pluck('count')) !!},
                        backgroundColor: '#10b981',
                        borderRadius: 4,
                        barThickness: 14,
                        yAxisID: 'y',
                        order: 2
                    }, {
                        label: 'Revenue',
                        data: {!! json_encode(collect($momGrowth)->pluck('revenue')) !!},
                        type: 'line',
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.08)',
                        borderWidth: 2,
                        pointRadius: 3,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y1',
                        order: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { labels: { font: chartFont, boxWidth: 8 } } },
                    scales: {
                        y: { beginAtZero: true, position: 'left', ticks: { stepSize: 1, font: chartFont }, grid: { color: gridColor } },
                        y1: { beginAtZero: true, position: 'right', ticks: { font: chartFont }, grid: { drawOnChartArea: false } },
                        x: { ticks: { font: chartFont }, grid: { display: false } }
                    }
                }
            });
            }

            const tvEl = document.getElementById('taxVarianceChart');
            if (tvEl) {
            new Chart(tvEl.getContext('2d'), {
                type: 'line',
                data: {
                    labels: {!! json_encode(collect($taxVariance)->pluck('month')) !!},
                    datasets: [{
                        label: 'Actual',
                        data: {!! json_encode(collect($taxVariance)->pluck('actual')) !!},
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.08)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 3
                    }, {
                        label: 'Expected',
                        data: {!! json_encode(collect($taxVariance)->pluck('expected')) !!},
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        borderDash: [4, 4],
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { font: chartFont, boxWidth: 8 } } },
                    scales: {
                        y: { beginAtZero: true, ticks: { font: chartFont }, grid: { color: gridColor } },
                        x: { ticks: { font: chartFont }, grid: { display: false } }
                    }
                }
            });
            }

            @if($hsRiskData->count() > 0)
            const hsRiskEl = document.getElementById('hsRiskChart');
            if (hsRiskEl) {
            const hsLabels = {!! json_encode($hsRiskData->pluck('hs_prefix')->map(fn($p) => 'HS ' . ($p ?? 'N/A'))) !!};
            const hsCounts = {!! json_encode($hsRiskData->pluck('count')) !!};
            const hsTax = {!! json_encode($hsRiskData->pluck('total_tax')) !!};
            const hsValue = {!! json_encode($hsRiskData->pluck('total_value')) !!};
            const hsColors = hsValue.map((v, i) => {
                const rate = v > 0 ? (hsTax[i] / v) * 100 : 0;
                if (rate >= 16 && rate <= 20) return '#10b981';
                if (rate >= 10 && rate < 16) return '#f59e0b';
                return '#ef4444';
            });
            new Chart(hsRiskEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: hsLabels,
                    datasets: [{
                        label: 'Items',
                        data: hsCounts,
                        backgroundColor: hsColors,
                        borderRadius: 4,
                        barThickness: 14
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                afterLabel: function(ctx) {
                                    const idx = ctx.dataIndex;
                                    const rate = hsValue[idx] > 0 ? ((hsTax[idx] / hsValue[idx]) * 100).toFixed(1) : 0;
                                    return 'Tax: ' + rate + '% | Rs. ' + hsValue[idx].toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, ticks: { stepSize: 1, font: chartFont }, grid: { color: gridColor } },
                        y: { ticks: { font: chartFont }, grid: { display: false } }
                    }
                }
            });
            }
            @endif

            const gaugeCanvas = document.getElementById('auditGauge');
            if (gaugeCanvas) {
                const gCtx = gaugeCanvas.getContext('2d');
                const prob = {{ $auditProbability['probability'] }};
                const cx = 48, cy = 54, r = 36;
                const startAngle = Math.PI;
                const endAngle = 2 * Math.PI;
                const valueAngle = startAngle + (prob / 100) * Math.PI;

                gCtx.beginPath();
                gCtx.arc(cx, cy, r, startAngle, endAngle);
                gCtx.lineWidth = 10;
                gCtx.strokeStyle = '#e5e7eb';
                gCtx.lineCap = 'round';
                gCtx.stroke();

                gCtx.beginPath();
                gCtx.arc(cx, cy, r, startAngle, valueAngle);
                gCtx.lineWidth = 10;
                gCtx.strokeStyle = prob < 25 ? '#10b981' : (prob < 50 ? '#f59e0b' : (prob < 70 ? '#f97316' : '#ef4444'));
                gCtx.lineCap = 'round';
                gCtx.stroke();
            }
        });
    </script>
</x-app-layout>
