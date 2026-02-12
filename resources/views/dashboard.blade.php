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

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 mb-4">
                <a href="/invoice/create" class="flex items-center space-x-2 p-3 bg-gradient-to-br from-emerald-50 to-emerald-100/50 dark:from-emerald-900/20 dark:to-emerald-800/10 border border-emerald-200 dark:border-emerald-800 rounded-xl hover:shadow-md transition group">
                    <div class="p-2 bg-emerald-100 dark:bg-emerald-800 rounded-lg group-hover:bg-emerald-200 dark:group-hover:bg-emerald-700 transition">
                        <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">Create Invoice</span>
                </a>
                <a href="/customer-profiles" class="flex items-center space-x-2 p-3 bg-gradient-to-br from-blue-50 to-blue-100/50 dark:from-blue-900/20 dark:to-blue-800/10 border border-blue-200 dark:border-blue-800 rounded-xl hover:shadow-md transition group">
                    <div class="p-2 bg-blue-100 dark:bg-blue-800 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-700 transition">
                        <svg class="w-4 h-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <span class="text-xs font-semibold text-blue-700 dark:text-blue-300">View Customers</span>
                </a>
                <a href="/mis-reports" class="flex items-center space-x-2 p-3 bg-gradient-to-br from-purple-50 to-purple-100/50 dark:from-purple-900/20 dark:to-purple-800/10 border border-purple-200 dark:border-purple-800 rounded-xl hover:shadow-md transition group">
                    <div class="p-2 bg-purple-100 dark:bg-purple-800 rounded-lg group-hover:bg-purple-200 dark:group-hover:bg-purple-700 transition">
                        <svg class="w-4 h-4 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <span class="text-xs font-semibold text-purple-700 dark:text-purple-300">Download Reports</span>
                </a>
                <a href="/ledger" class="flex items-center space-x-2 p-3 bg-gradient-to-br from-amber-50 to-amber-100/50 dark:from-amber-900/20 dark:to-amber-800/10 border border-amber-200 dark:border-amber-800 rounded-xl hover:shadow-md transition group">
                    <div class="p-2 bg-amber-100 dark:bg-amber-800 rounded-lg group-hover:bg-amber-200 dark:group-hover:bg-amber-700 transition">
                        <svg class="w-4 h-4 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-300">View Ledger</span>
                </a>
                <a href="/company/settings" class="flex items-center space-x-2 p-3 bg-gradient-to-br from-gray-50 to-gray-100/50 dark:from-gray-800 dark:to-gray-700/30 border border-gray-200 dark:border-gray-600 rounded-xl hover:shadow-md transition group">
                    <div class="p-2 bg-gray-100 dark:bg-gray-700 rounded-lg group-hover:bg-gray-200 dark:group-hover:bg-gray-600 transition">
                        <svg class="w-4 h-4 text-gray-600 dark:text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">FBR Settings</span>
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>Compliance Status</span>
                        </h3>
                        @if($hybridScore >= 80)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-800 dark:text-emerald-200">Compliant</span>
                        @elseif($hybridScore >= 50)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-800 dark:text-amber-200">Needs Attention</span>
                        @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-200">At Risk</span>
                        @endif
                    </div>
                    <div class="flex items-center space-x-4">
                        <p class="text-3xl font-bold {{ $hybridScore >= 80 ? 'text-emerald-600' : ($hybridScore >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $hybridScore }}</p>
                        <div class="flex-1">
                            <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2.5">
                                <div class="h-2.5 rounded-full {{ $hybridScore >= 80 ? 'bg-emerald-500' : ($hybridScore >= 50 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ min(100, $hybridScore) }}%"></div>
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Score out of 100</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <span>Payment Summary</span>
                    </h3>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="text-center p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <p class="text-lg font-bold text-blue-700 dark:text-blue-400">Rs. {{ number_format($totalRevenue) }}</p>
                            <p class="text-xs text-blue-600 dark:text-blue-500">Total Billed</p>
                        </div>
                        <div class="text-center p-2 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                            <p class="text-lg font-bold text-emerald-700 dark:text-emerald-400">{{ $lockedCount }}</p>
                            <p class="text-xs text-emerald-600 dark:text-emerald-500">Locked Invoices</p>
                        </div>
                        <div class="text-center p-2 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                            <p class="text-lg font-bold text-amber-700 dark:text-amber-400">{{ $draftCount + $submittedCount }}</p>
                            <p class="text-xs text-amber-600 dark:text-amber-500">Pending Invoices</p>
                        </div>
                    </div>
                </div>
            </div>

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

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 mb-4">
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

            @if(in_array($planTier, ['business', 'enterprise']) && $topCustomers->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden mb-4">
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

            @if($planTier === 'enterprise' && $branchComparison->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden mb-4">
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
        });
    </script>
</x-app-layout>
