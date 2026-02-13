<x-app-layout>
    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Billing & Plans</h2>
            </div>

            @if($currentSubscription && $usageData)
                @if($usageData['trial'] && $usageData['trial']['is_trial'])
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <svg class="w-8 h-8 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <div>
                                <h3 class="text-lg font-semibold text-blue-800">Free Trial Active</h3>
                                <p class="text-sm text-blue-600">{{ $usageData['trial']['days_left'] }} days left - Expires {{ $usageData['trial']['ends_at'] }}. Upgrade now to keep your data.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @elseif($usageData['is_expired'])
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-6 mb-6">
                    <div class="flex items-center space-x-3">
                        <svg class="w-8 h-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.268 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div>
                            <h3 class="text-lg font-semibold text-red-800">Subscription Expired!</h3>
                            <p class="text-sm text-red-600">Please subscribe to continue creating invoices and accessing FBR services.</p>
                        </div>
                    </div>
                </div>
                @elseif($usageData['is_expiring_soon'])
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-xl p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <svg class="w-8 h-8 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <div>
                                <h3 class="text-lg font-semibold text-yellow-800">Subscription Expiring Soon</h3>
                                <p class="text-sm text-yellow-600"><span class="font-bold">{{ $usageData['days_left'] }}</span> days remaining on your {{ $currentSubscription->pricingPlan->name }} plan</p>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Current Plan: {{ $currentSubscription->pricingPlan->name }}</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ \App\Models\Subscription::getCycleLabel($usageData['billing_cycle']) }} billing
                                @if($usageData['discount_percent'] > 0) &middot; {{ $usageData['discount_percent'] }}% discount @endif
                                &middot; Active until {{ \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y') }}
                            </p>
                        </div>
                        <span class="inline-flex px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-sm font-medium">Active</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Invoices</p>
                            <div class="flex items-center space-x-3">
                                @if($usageData['invoice_limit'] === -1)
                                    <span class="text-sm font-medium text-gray-700">{{ $usageData['invoice_count'] }} / Unlimited</span>
                                @else
                                    <div class="flex-1 bg-gray-200 rounded-full h-3">
                                        <div class="h-3 rounded-full transition-all {{ $usageData['usage_percent'] > 80 ? 'bg-red-500' : 'bg-emerald-500' }}"
                                            style="width: {{ $usageData['usage_percent'] }}%"></div>
                                    </div>
                                    <span class="text-sm font-medium text-gray-700">{{ $usageData['invoice_count'] }}/{{ $usageData['invoice_limit'] }}</span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Users</p>
                            <span class="text-sm font-medium text-gray-700">{{ $usageData['user_count'] }} / {{ $usageData['user_limit_display'] }}</span>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Branches</p>
                            <span class="text-sm font-medium text-gray-700">{{ $usageData['branch_count'] }} / {{ $usageData['branch_limit_display'] }}</span>
                        </div>
                    </div>
                </div>
            @endif

            <div class="text-center mb-8">
                <h3 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Choose Your Plan</h3>
                <p class="text-gray-500 mt-2">Aggressive pricing for high-volume businesses. All plans include FBR compliance.</p>
            </div>

            <div x-data="{
                cycle: 'monthly',
                discounts: { monthly: 0, quarterly: 1, semi_annual: 3, annual: 6 },
                months: { monthly: 1, quarterly: 3, semi_annual: 6, annual: 12 },
                cycleLabels: { monthly: 'Monthly', quarterly: 'Quarterly', semi_annual: 'Semi-Annual', annual: 'Annual' },
                calcPrice(base) {
                    let m = this.months[this.cycle];
                    let d = this.discounts[this.cycle];
                    let total = base * m;
                    return Math.round(total - (total * d / 100));
                },
                calcMonthly(base) {
                    return Math.round(this.calcPrice(base) / this.months[this.cycle]);
                }
            }">
                <div class="flex justify-center mb-8">
                    <div class="inline-flex bg-gray-100 dark:bg-gray-800 rounded-xl p-1 border border-gray-200 dark:border-gray-700">
                        @foreach($billingCycles as $key => $info)
                        <button @click="cycle = '{{ $key }}'"
                            :class="cycle === '{{ $key }}' ? 'bg-white dark:bg-gray-700 shadow-sm text-emerald-700 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'"
                            class="px-4 py-2 rounded-lg text-sm transition">
                            {{ $info['label'] }}
                            @if($info['discount'] > 0)
                            <span class="ml-1 text-xs text-emerald-600 font-bold">-{{ $info['discount'] }}%</span>
                            @endif
                        </button>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach($plans as $index => $plan)
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border-2 transition relative
                        {{ $plan->name === 'Business' ? 'border-emerald-500 ring-2 ring-emerald-500' : 'border-gray-200 dark:border-gray-800' }}
                        {{ $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id ? 'border-emerald-500' : '' }}">

                        @if($plan->name === 'Business')
                        <div class="absolute -top-3 left-1/2 transform -translate-x-1/2">
                            <span class="bg-emerald-600 text-white text-xs font-bold px-3 py-1 rounded-full shadow-sm">MOST POPULAR</span>
                        </div>
                        @endif

                        <div class="p-6">
                            @if($currentSubscription && $currentSubscription->pricing_plan_id === $plan->id)
                            <span class="inline-flex px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded text-xs font-medium mb-3">Current Plan</span>
                            @endif
                            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h3>
                            <div class="mt-4">
                                <div x-show="cycle === 'monthly'">
                                    <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">Rs. {{ number_format($plan->price) }}</span>
                                    <span class="text-gray-500 text-sm">/mo</span>
                                </div>
                                <div x-show="cycle !== 'monthly'">
                                    <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">Rs. <span x-text="calcMonthly({{ $plan->price }}).toLocaleString()"></span></span>
                                    <span class="text-gray-500 text-sm">/mo</span>
                                    <p class="text-xs text-gray-400 mt-1">Rs. <span x-text="calcPrice({{ $plan->price }}).toLocaleString()"></span> total</p>
                                </div>
                            </div>
                            <ul class="mt-5 space-y-2.5">
                                <li class="flex items-center text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    {{ $plan->getInvoiceLimitDisplay() }} invoices/mo
                                </li>
                                <li class="flex items-center text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    {{ $plan->getUserLimitDisplay() }} users
                                </li>
                                <li class="flex items-center text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    {{ $plan->getBranchLimitDisplay() }} {{ $plan->branch_limit === -1 ? 'branches' : ($plan->branch_limit === 1 ? 'branch' : 'branches') }}
                                </li>
                                <li class="flex items-center text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    FBR Integration
                                </li>
                                <li class="flex items-center text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Compliance Scoring
                                </li>
                            </ul>
                            @if(!$currentSubscription || $currentSubscription->pricing_plan_id !== $plan->id)
                            <form method="POST" action="/billing/subscribe" class="mt-6">
                                @csrf
                                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                <input type="hidden" name="billing_cycle" :value="cycle">
                                <button type="submit" class="w-full py-2.5 rounded-lg font-semibold text-sm transition shadow-sm
                                    {{ $plan->name === 'Business' ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-gray-900 text-white hover:bg-gray-800' }}">
                                    Subscribe
                                </button>
                            </form>
                            @else
                            <div class="mt-6">
                                <button disabled class="w-full py-2.5 rounded-lg font-semibold text-sm bg-gray-100 text-gray-400 cursor-not-allowed">Current Plan</button>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>

                @if(in_array(auth()->user()->role, ['super_admin', 'company_admin']))
                <div class="mt-8">
                    <a href="/billing/custom-plan" class="block bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-900/20 dark:to-teal-900/20 rounded-xl shadow-sm border-2 border-dashed border-emerald-300 dark:border-emerald-700 p-6 hover:border-emerald-500 dark:hover:border-emerald-500 transition group">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/40 rounded-xl flex items-center justify-center group-hover:bg-emerald-200 dark:group-hover:bg-emerald-800/40 transition">
                                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Build Custom Plan</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Need specific limits? Configure invoices, users, and branches to get instant pricing.</p>
                                </div>
                            </div>
                            <svg class="w-5 h-5 text-emerald-500 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </div>
                    </a>
                </div>
                @endif

                <div class="mt-8 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-800">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Feature Comparison</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500 w-1/5">Feature</th>
                                    @foreach($plans as $plan)
                                    <th class="text-center py-3 px-4 text-sm font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600">Monthly Price</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold">Rs. {{ number_format($plan->price) }}</td>
                                    @endforeach
                                </tr>
                                <tr class="bg-gray-50 dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600">Invoices</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold">{{ $plan->getInvoiceLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600">Users</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold">{{ $plan->getUserLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                <tr class="bg-gray-50 dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600">Branches</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold">{{ $plan->getBranchLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                @php
                                $features = [
                                    'FBR Integration' => [true, true, true, true],
                                    'PDF Generation' => [true, true, true, true],
                                    'Compliance Scoring' => [true, true, true, true],
                                    'MIS Reports' => [false, true, true, true],
                                    'Customer Ledger' => [false, true, true, true],
                                    'Audit Logs' => [false, false, true, true],
                                    'Priority Support' => [false, false, true, true],
                                    'Custom Integrations' => [false, false, false, true],
                                ];
                                @endphp
                                @foreach($features as $feature => $availability)
                                <tr class="{{ $loop->even ? 'bg-gray-50 dark:bg-gray-800' : '' }} hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600">{{ $feature }}</td>
                                    @foreach($availability as $avail)
                                    <td class="py-3 px-4 text-center">
                                        @if($avail)
                                        <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        @else
                                        <svg class="w-5 h-5 text-gray-300 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        @endif
                                    </td>
                                    @endforeach
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
