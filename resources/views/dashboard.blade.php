<x-app-layout>


    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="mb-5">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h2 class="text-xl sm:text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">
                                Welcome back<span class="text-emerald-600">,</span> {{ Auth::user()->name }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $company->name ?? 'My Company' }} &middot; {{ now()->format('l, d M Y') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold tracking-wide {{ $riskBadge['bg'] }} {{ $riskBadge['text'] }} shadow-sm">
                                <span class="w-2 h-2 rounded-full mr-1.5 {{ $hybridScore >= 80 ? 'bg-emerald-500' : ($hybridScore >= 50 ? 'bg-amber-500' : 'bg-red-500') }} animate-pulse"></span>
                                {{ $hybridScore }} - {{ $riskBadge['label'] }}
                            </span>
                            <a href="/compliance/certificate" target="_blank" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 rounded-lg text-xs font-bold text-white hover:bg-indigo-700 shadow-sm transition">
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
                                Certificate
                            </a>
                            <a href="/invoice/create" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 rounded-lg text-xs font-bold text-white hover:bg-emerald-700 shadow-sm transition">
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                New Invoice
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            @if(!empty($trialInfo) && $trialInfo['is_trial'])
            <div class="mb-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center space-x-3">
                        <div class="p-2.5 bg-blue-600 rounded-lg flex-shrink-0">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-blue-900 dark:text-blue-100">Free Trial &middot; {{ round($trialInfo['days_left']) }} days remaining</p>
                            <p class="text-xs text-blue-600 dark:text-blue-400">Upgrade anytime to unlock full features</p>
                        </div>
                    </div>
                    <a href="/billing/plans" class="px-4 py-2 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-700 shadow-sm transition text-center flex-shrink-0">Upgrade Now</a>
                </div>
            </div>
            @endif

            @if(!empty($trialInfo) && !empty($trialInfo['is_expired']) && $trialInfo['is_expired'])
            <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="p-2.5 bg-red-600 rounded-lg">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-red-900 dark:text-red-100">Trial Expired &middot; FBR submissions blocked</p>
                        <p class="text-xs text-red-600 dark:text-red-400">Subscribe to continue using all features</p>
                    </div>
                </div>
                <a href="/billing/plans" class="px-4 py-2 bg-red-600 text-white text-xs font-bold rounded-lg hover:bg-red-700 shadow-sm transition">Subscribe</a>
            </div>
            @endif

            @if($notifications->count() > 0)
            <div class="mb-4 space-y-2">
                @foreach($notifications as $notif)
                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-3 flex items-center space-x-3">
                    <div class="p-1.5 bg-amber-500 rounded-lg shadow-sm">
                        <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                    </div>
                    <p class="text-sm text-amber-900 dark:text-amber-100"><span class="font-bold">{{ $notif->title }}</span> &middot; {{ $notif->message }}</p>
                </div>
                @endforeach
            </div>
            @endif

            @php $usagePercent = $invoiceLimit > 0 ? min(100, ($invoicesUsed / $invoiceLimit) * 100) : 0; @endphp
            @if($usagePercent >= 80)
            <div class="mb-4 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-xl p-4 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="p-2.5 bg-orange-600 rounded-lg">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-orange-900 dark:text-orange-100">{{ round($usagePercent) }}% Capacity Used</p>
                        <p class="text-xs text-orange-600 dark:text-orange-400">{{ $invoicesUsed }} of {{ $invoiceLimit }} invoices used this period</p>
                    </div>
                </div>
                <a href="/billing/plans" class="px-4 py-2 bg-orange-600 text-white text-xs font-bold rounded-lg hover:bg-orange-700 shadow-sm transition">Upgrade</a>
            </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 border-t-2 border-blue-500"></div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalInvoices }}</p>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Total Invoices</p>
                    <div class="mt-3 flex items-center space-x-2 text-xs">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 font-medium">{{ $draftCount }} draft</span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 font-medium">{{ $lockedCount }} locked</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 border-t-2 border-emerald-500"></div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">Rs. {{ number_format($totalRevenue) }}</p>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Total Revenue</p>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 border-t-2 border-purple-500"></div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $lockedCount }}</p>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">FBR Locked</p>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 border-t-2 border-indigo-500"></div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold {{ $fbrSuccessRate >= 80 ? 'text-emerald-600 dark:text-emerald-400' : ($fbrSuccessRate >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">{{ $fbrSuccessRate }}%</p>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">FBR Success</p>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 border-t-2 border-orange-500"></div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $invoicesUsed }}<span class="text-base text-gray-400 dark:text-gray-500 font-normal">/{{ $invoiceLimit }}</span></p>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Plan Usage</p>
                    @if($subscription)
                    <div class="mt-3">
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
                            <div class="h-1.5 rounded-full {{ $usagePercent > 80 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $usagePercent }}%"></div>
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5 font-medium">{{ $subscription->pricingPlan->name }}</p>
                    </div>
                    @endif
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-2.5 mb-5">
                <a href="/invoice/create" class="group flex items-center space-x-3 p-3.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl hover:border-emerald-300 dark:hover:border-emerald-700 transition-all duration-300">
                    <div class="p-2.5 bg-emerald-500 rounded-lg">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <span class="text-xs font-bold text-gray-700 dark:text-gray-200 group-hover:text-emerald-700 dark:group-hover:text-emerald-400 transition">Create Invoice</span>
                </a>
                <a href="/customer-profiles" class="group flex items-center space-x-3 p-3.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl hover:border-blue-300 dark:hover:border-blue-700 transition-all duration-300">
                    <div class="p-2.5 bg-blue-500 rounded-lg">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <span class="text-xs font-bold text-gray-700 dark:text-gray-200 group-hover:text-blue-700 dark:group-hover:text-blue-400 transition">Customers</span>
                </a>
                <a href="/reports/wht" class="group flex items-center space-x-3 p-3.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl hover:border-purple-300 dark:hover:border-purple-700 transition-all duration-300">
                    <div class="p-2.5 bg-purple-500 rounded-lg">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <span class="text-xs font-bold text-gray-700 dark:text-gray-200 group-hover:text-purple-700 dark:group-hover:text-purple-400 transition">Reports</span>
                </a>
                <a href="/customers" class="group flex items-center space-x-3 p-3.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl hover:border-amber-300 dark:hover:border-amber-700 transition-all duration-300">
                    <div class="p-2.5 bg-amber-500 rounded-lg">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <span class="text-xs font-bold text-gray-700 dark:text-gray-200 group-hover:text-amber-700 dark:group-hover:text-amber-400 transition">Ledger</span>
                </a>
                <a href="/company/fbr-settings" class="group flex items-center space-x-3 p-3.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl hover:border-gray-300 dark:hover:border-gray-600 transition-all duration-300">
                    <div class="p-2.5 bg-gray-500 rounded-lg">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <span class="text-xs font-bold text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition">Settings</span>
                </a>
            </div>

            <div x-data="{ showAdvanced: false }">
            <div class="mb-4 flex justify-center">
                <button @click="showAdvanced = !showAdvanced" class="inline-flex items-center px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-bold hover:bg-indigo-700 shadow-sm transition-all duration-150 group">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    <span x-text="showAdvanced ? 'Hide Advanced Insights' : 'Advanced Insights'"></span>
                    <svg class="w-4 h-4 ml-2 transition-transform duration-200" :class="showAdvanced ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </div>

            <div x-show="showAdvanced" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 transform -translate-y-2" x-transition:enter-end="opacity-100 transform translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 relative overflow-hidden">
                    <div class="relative">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 flex items-center space-x-2 uppercase tracking-wider">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Compliance</span>
                            </h3>
                            @if($hybridScore >= 80)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-500 text-white">Compliant</span>
                            @elseif($hybridScore >= 50)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-500 text-white">Needs Attention</span>
                            @else
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-500 text-white">At Risk</span>
                            @endif
                        </div>
                        <div class="flex items-center space-x-5">
                            <div class="relative">
                                <svg class="w-20 h-20 -rotate-90" viewBox="0 0 36 36">
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="currentColor" stroke-width="2.5" class="text-gray-100 dark:text-gray-700"/>
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke-width="2.5" stroke-dasharray="{{ min(100, $hybridScore) }}, 100" stroke-linecap="round" class="{{ $hybridScore >= 80 ? 'stroke-emerald-500' : ($hybridScore >= 50 ? 'stroke-amber-500' : 'stroke-red-500') }}" style="transition: stroke-dasharray 1.5s ease-out;"/>
                                </svg>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-xl font-extrabold {{ $hybridScore >= 80 ? 'text-emerald-600 dark:text-emerald-400' : ($hybridScore >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">{{ $hybridScore }}</span>
                                </div>
                            </div>
                            <div class="flex-1">
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-500 dark:text-gray-400">Base Score</span>
                                        <span class="font-bold text-gray-700 dark:text-gray-300">{{ $complianceScore }}/100</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-500 dark:text-gray-400">Hybrid Score</span>
                                        <span class="font-bold text-gray-700 dark:text-gray-300">{{ $hybridScore }}/100</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-500 dark:text-gray-400">Risk Level</span>
                                        <span class="font-bold {{ $hybridScore >= 80 ? 'text-emerald-600' : ($hybridScore >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $riskLevel }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 relative overflow-hidden">
                    <div class="relative">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center space-x-2 uppercase tracking-wider">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span>Payment Summary</span>
                        </h3>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="text-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-100 dark:border-blue-800">
                                <p class="text-lg font-extrabold text-blue-700 dark:text-blue-400">Rs. {{ number_format($totalRevenue) }}</p>
                                <p class="text-xs font-medium text-blue-600/70 dark:text-blue-500 mt-1 uppercase tracking-wider">Total Billed</p>
                            </div>
                            <div class="text-center p-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-100 dark:border-emerald-800">
                                <p class="text-lg font-extrabold text-emerald-700 dark:text-emerald-400">{{ $lockedCount }}</p>
                                <p class="text-xs font-medium text-emerald-600/70 dark:text-emerald-500 mt-1 uppercase tracking-wider">Locked</p>
                            </div>
                            <div class="text-center p-3 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-100 dark:border-amber-800">
                                <p class="text-lg font-extrabold text-amber-700 dark:text-amber-400">{{ $draftCount }}</p>
                                <p class="text-xs font-medium text-amber-600/70 dark:text-amber-500 mt-1 uppercase tracking-wider">Pending</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if($planTier !== 'retail')
            @if(count($smartInsights) > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 mb-5">
                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-3 flex items-center space-x-2 uppercase tracking-wider">
                    <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                    <span>Smart Insights</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                    @foreach($smartInsights as $insight)
                    <div class="flex items-start space-x-3 p-3 rounded-xl {{ $insight['type'] === 'danger' ? 'bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800' : 'bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800' }}">
                        <div class="flex-shrink-0 mt-0.5 p-1.5 rounded-lg {{ $insight['type'] === 'danger' ? 'bg-red-500/10 dark:bg-red-500/20' : 'bg-amber-500/10 dark:bg-amber-500/20' }}">
                            @if($insight['icon'] === 'clock')
                            <svg class="w-3.5 h-3.5 {{ $insight['type'] === 'danger' ? 'text-red-500' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            @elseif($insight['icon'] === 'refresh')
                            <svg class="w-3.5 h-3.5 {{ $insight['type'] === 'danger' ? 'text-red-500' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            @else
                            <svg class="w-3.5 h-3.5 {{ $insight['type'] === 'danger' ? 'text-red-500' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                            @endif
                        </div>
                        <div>
                            <p class="text-xs font-bold {{ $insight['type'] === 'danger' ? 'text-red-800 dark:text-red-300' : 'text-amber-800 dark:text-amber-300' }}">{{ $insight['title'] }}</p>
                            <p class="text-xs {{ $insight['type'] === 'danger' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }} mt-0.5">{{ $insight['message'] }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 mb-5">
                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-3 uppercase tracking-wider">Draft Aging</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div class="text-center p-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-100 dark:border-emerald-800">
                        <p class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">{{ $draftAging['1_day'] }}</p>
                        <p class="text-xs font-medium text-emerald-600/70 dark:text-emerald-500 mt-1">&lt;1 day</p>
                    </div>
                    <div class="text-center p-3 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-100 dark:border-amber-800">
                        <p class="text-2xl font-extrabold text-amber-600 dark:text-amber-400">{{ $draftAging['3_days'] }}</p>
                        <p class="text-xs font-medium text-amber-600/70 dark:text-amber-500 mt-1">1-3 days</p>
                    </div>
                    <div class="text-center p-3 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-100 dark:border-red-800">
                        <p class="text-2xl font-extrabold text-red-600 dark:text-red-400">{{ $draftAging['7_plus'] }}</p>
                        <p class="text-xs font-medium text-red-600/70 dark:text-red-500 mt-1">7+ days</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-3 uppercase tracking-wider">Invoice Status</h3>
                    <div style="height: 150px;"><canvas id="statusChart"></canvas></div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-3 uppercase tracking-wider">Monthly Invoices</h3>
                    <div style="height: 150px;"><canvas id="monthlyChart"></canvas></div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-3 uppercase tracking-wider">Compliance Trend</h3>
                    <div style="height: 150px;"><canvas id="complianceChart"></canvas></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Compliance %</p>
                            <p class="text-2xl font-extrabold mt-1 {{ $kpis['compliance_percent'] >= 70 ? 'text-emerald-600 dark:text-emerald-400' : ($kpis['compliance_percent'] >= 40 ? 'text-orange-600 dark:text-orange-400' : 'text-red-600 dark:text-red-400') }}">{{ $kpis['compliance_percent'] }}%</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 font-medium">Locked / Total</p>
                        </div>
                        <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Avg Invoice</p>
                            <p class="text-2xl font-extrabold text-gray-900 dark:text-white mt-1">Rs. {{ number_format($kpis['avg_invoice_value']) }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 font-medium">Per invoice avg</p>
                        </div>
                        <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Rejection Rate</p>
                            <p class="text-2xl font-extrabold mt-1 {{ $kpis['rejection_rate'] <= 10 ? 'text-emerald-600 dark:text-emerald-400' : ($kpis['rejection_rate'] <= 30 ? 'text-orange-600 dark:text-orange-400' : 'text-red-600 dark:text-red-400') }}">{{ $kpis['rejection_rate'] }}%</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 font-medium">Failed / Total</p>
                        </div>
                        <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                    </div>
                </div>
            </div>

            @if(in_array($planTier, ['business', 'enterprise']) && $topCustomers->count() > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden mb-5">
                <div class="px-5 py-4 border-b border-gray-100/80 dark:border-gray-700/50 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 uppercase tracking-wider">Top 5 Customers</h3>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100/80 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400">{{ $topCustomers->count() }} customers</span>
                </div>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700/50">
                    <thead class="bg-gray-50/80 dark:bg-gray-700/30">
                        <tr>
                            <th class="px-5 py-2.5 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Customer</th>
                            <th class="px-5 py-2.5 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Revenue</th>
                            <th class="px-5 py-2.5 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Invoices</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/30">
                        @foreach($topCustomers as $cust)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition-colors duration-200">
                            <td class="px-5 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $cust->buyer_name }}</td>
                            <td class="px-5 py-3 text-sm font-bold text-emerald-600 dark:text-emerald-400 text-right">Rs. {{ number_format($cust->total_amount) }}</td>
                            <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400 text-right">{{ $cust->invoice_count }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            @if($planTier === 'enterprise' && $branchComparison->count() > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden mb-5">
                <div class="px-5 py-4 border-b border-gray-100/80 dark:border-gray-700/50">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 uppercase tracking-wider">Branch Comparison</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700/50">
                    <thead class="bg-gray-50/80 dark:bg-gray-700/30">
                        <tr>
                            <th class="px-5 py-2.5 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Branch</th>
                            <th class="px-5 py-2.5 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Invoices</th>
                            <th class="px-5 py-2.5 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/30">
                        @foreach($branchComparison as $branch)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition-colors duration-200">
                            <td class="px-5 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $branch->branch_name }}</td>
                            <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400 text-right">{{ $branch->invoice_count }}</td>
                            <td class="px-5 py-3 text-sm font-bold text-emerald-600 dark:text-emerald-400 text-right">Rs. {{ number_format($branch->total_revenue) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100/80 dark:border-gray-700/50 flex items-center justify-between">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 uppercase tracking-wider">Recent Invoices</h3>
                        <a href="/invoices" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300 transition">View All &rarr;</a>
                    </div>
                    <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        @forelse($recentInvoices as $invoice)
                        <a href="/invoice/{{ $invoice->id }}" class="block p-3 rounded-xl border border-gray-200 dark:border-gray-700 hover:border-emerald-200 dark:hover:border-emerald-800 hover:shadow-md transition-all duration-300 group bg-white dark:bg-gray-900">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-mono text-gray-400 dark:text-gray-500 font-medium">{{ $invoice->invoice_number ?? 'INV-' . $invoice->id }}</span>
                                <span class="inline-flex px-2 py-0.5 rounded-lg text-xs font-bold
                                    @if($invoice->status === 'draft') bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300
                                    @elseif($invoice->status === 'locked') bg-emerald-100/80 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400
                                    @elseif($invoice->status === 'pending_verification') bg-amber-100/80 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400
                                    @endif">{{ $invoice->status === 'pending_verification' ? 'Pending' : ucfirst($invoice->status) }}</span>
                            </div>
                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate group-hover:text-emerald-700 dark:group-hover:text-emerald-400 transition">{{ $invoice->buyer_name }}</p>
                            <div class="flex items-center justify-between mt-2">
                                <span class="text-sm font-extrabold text-emerald-600 dark:text-emerald-400">Rs. {{ number_format($invoice->total_amount) }}</span>
                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $invoice->created_at->format('d M') }}</span>
                            </div>
                        </a>
                        @empty
                        <div class="col-span-2 text-center py-8 text-gray-400 dark:text-gray-500">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <p class="text-sm font-medium">No invoices yet</p>
                        </div>
                        @endforelse
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100/80 dark:border-gray-700/50">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100 uppercase tracking-wider">Recent Activity</h3>
                    </div>
                    <div class="p-4 max-h-80 overflow-y-auto">
                        @forelse($recentActivity as $activity)
                        <div class="flex items-start space-x-3 py-2.5 {{ !$loop->last ? 'border-b border-gray-50 dark:border-gray-700/30' : '' }}">
                            <div class="flex-shrink-0 mt-0.5">
                                @if($activity->action === 'created')
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-emerald-500"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span>
                                @elseif($activity->action === 'edited')
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-blue-500"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                                @elseif($activity->action === 'submitted')
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-purple-500"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg></span>
                                @elseif($activity->action === 'locked')
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-emerald-500"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></span>
                                @else
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-gray-500"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-gray-800 dark:text-gray-200">
                                    <span class="font-bold">{{ $activity->user->name ?? 'System' }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ $activity->action }}</span>
                                    <a href="/invoice/{{ $activity->invoice_id }}" class="text-emerald-600 dark:text-emerald-400 hover:underline font-bold">#{{ $activity->invoice->invoice_number ?? $activity->invoice_id }}</a>
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $activity->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-8 text-gray-400 dark:text-gray-500">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p class="text-sm font-medium">No activity yet</p>
                        </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chartFont = { family: "'Inter', sans-serif", size: 10, weight: '500' };
            const gridColor = 'rgba(0,0,0,0.04)';

            const statusEl = document.getElementById('statusChart');
            if (statusEl) {
            new Chart(statusEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Draft', 'Locked'],
                    datasets: [{
                        data: [{{ $statusData['draft'] }}, {{ $statusData['locked'] }}],
                        backgroundColor: ['#f59e0b', '#10b981'],
                        borderWidth: 0,
                        spacing: 3,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: { legend: { position: 'right', labels: { font: chartFont, boxWidth: 8, padding: 10, usePointStyle: true, pointStyle: 'circle' } } }
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
                        backgroundColor: function(context) {
                            const chart = context.chart;
                            const {ctx, chartArea} = chart;
                            if (!chartArea) return '#10b981';
                            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            gradient.addColorStop(0, '#10b981');
                            gradient.addColorStop(1, '#6366f1');
                            return gradient;
                        },
                        borderRadius: 8,
                        barThickness: 18
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, font: chartFont }, grid: { color: gridColor, drawBorder: false } },
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
                        backgroundColor: function(context) {
                            const chart = context.chart;
                            const {ctx, chartArea} = chart;
                            if (!chartArea) return 'rgba(99, 102, 241, 0.1)';
                            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            gradient.addColorStop(0, 'rgba(99, 102, 241, 0.02)');
                            gradient.addColorStop(1, 'rgba(99, 102, 241, 0.15)');
                            return gradient;
                        },
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2.5,
                        pointRadius: 4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#6366f1',
                        pointBorderWidth: 2,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { font: chartFont }, grid: { color: gridColor, drawBorder: false } },
                        x: { ticks: { font: chartFont }, grid: { display: false } }
                    }
                }
            });
            }
        });
    </script>
</x-app-layout>