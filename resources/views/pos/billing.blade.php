<x-pos-layout>
    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Billing & Plans</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">NestPOS subscription plans for your point-of-sale operations</p>
            </div>

            @if($currentSubscription && $currentSubscription->pricingPlan)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Current Plan: {{ $currentSubscription->pricingPlan->name }}</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Active until {{ \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y') }}
                        </p>
                    </div>
                    <span class="inline-flex px-3 py-1 bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300 rounded-full text-sm font-medium">Active</span>
                </div>
            </div>
            @endif

            <div class="text-center mb-8">
                <h3 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Choose Your NestPOS Plan</h3>
                <p class="text-gray-500 mt-2">All plans include PRA compliance, thermal receipt printing, and multi-terminal support.</p>
            </div>

            <div x-data="{
                cycle: 'monthly',
                discounts: { monthly: 0, quarterly: 1, semi_annual: 3, annual: 6 },
                months: { monthly: 1, quarterly: 3, semi_annual: 6, annual: 12 },
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
                        <button @click="cycle = 'monthly'"
                            :class="cycle === 'monthly' ? 'bg-white dark:bg-gray-700 shadow-sm text-purple-700 dark:text-purple-400 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'"
                            class="px-4 py-2 rounded-lg text-sm transition">Monthly</button>
                        <button @click="cycle = 'quarterly'"
                            :class="cycle === 'quarterly' ? 'bg-white dark:bg-gray-700 shadow-sm text-purple-700 dark:text-purple-400 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'"
                            class="px-4 py-2 rounded-lg text-sm transition">Quarterly <span class="ml-1 text-xs text-purple-600 font-bold">-1%</span></button>
                        <button @click="cycle = 'semi_annual'"
                            :class="cycle === 'semi_annual' ? 'bg-white dark:bg-gray-700 shadow-sm text-purple-700 dark:text-purple-400 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'"
                            class="px-4 py-2 rounded-lg text-sm transition">Semi-Annual <span class="ml-1 text-xs text-purple-600 font-bold">-3%</span></button>
                        <button @click="cycle = 'annual'"
                            :class="cycle === 'annual' ? 'bg-white dark:bg-gray-700 shadow-sm text-purple-700 dark:text-purple-400 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'"
                            class="px-4 py-2 rounded-lg text-sm transition">Annual <span class="ml-1 text-xs text-purple-600 font-bold">-6%</span></button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach($plans as $index => $plan)
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border-2 transition relative
                        {{ $plan->name === 'Business' ? 'border-purple-500 ring-2 ring-purple-500' : 'border-gray-200 dark:border-gray-800' }}
                        {{ $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id ? 'border-purple-500' : '' }}">

                        @if($plan->name === 'Business')
                        <div class="absolute -top-3 left-1/2 transform -translate-x-1/2">
                            <span class="bg-purple-600 text-white text-xs font-bold px-3 py-1 rounded-full shadow-sm">MOST POPULAR</span>
                        </div>
                        @endif

                        <div class="p-6">
                            @if($currentSubscription && $currentSubscription->pricing_plan_id === $plan->id)
                            <span class="inline-flex px-2 py-0.5 bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300 rounded text-xs font-medium mb-3">Current Plan</span>
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
                                <li class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    {{ $plan->getInvoiceLimitDisplay() }} transactions/mo
                                </li>
                                <li class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    {{ $plan->getUserLimitDisplay() }} users
                                </li>
                                <li class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    PRA Integration
                                </li>
                                <li class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Thermal Receipt Printing
                                </li>
                                <li class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Multi-Terminal Support
                                </li>
                            </ul>
                            @if(!$currentSubscription || $currentSubscription->pricing_plan_id !== $plan->id)
                            <div class="mt-6">
                                <a href="{{ route('pos.landing') }}" class="block w-full py-2.5 rounded-lg font-semibold text-sm text-center transition shadow-sm
                                    {{ $plan->name === 'Business' ? 'bg-purple-600 text-white hover:bg-purple-700' : 'bg-gray-900 text-white hover:bg-gray-800 dark:bg-gray-700 dark:hover:bg-gray-600' }}">
                                    Contact Sales
                                </a>
                            </div>
                            @else
                            <div class="mt-6">
                                <button disabled class="w-full py-2.5 rounded-lg font-semibold text-sm bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed">Current Plan</button>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="mt-8 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-800">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">POS Feature Comparison</h3>
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
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">Monthly Price</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">Rs. {{ number_format($plan->price) }}</td>
                                    @endforeach
                                </tr>
                                <tr class="bg-gray-50 dark:bg-gray-800/50">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">Transactions</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $plan->getInvoiceLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">Users</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $plan->getUserLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                @php
                                $features = [
                                    'PRA Integration' => [true, true, true, true],
                                    'Thermal Receipts (58mm/80mm)' => [true, true, true, true],
                                    'Multi-Terminal Support' => [true, true, true, true],
                                    'Tax Reports & Export' => [true, true, true, true],
                                    'Inventory Management' => [false, true, true, true],
                                    'Customer Database' => [false, true, true, true],
                                    'Product Catalog' => [true, true, true, true],
                                    'Offline Mode & Auto-Sync' => [false, false, true, true],
                                    'Advanced Analytics' => [false, false, true, true],
                                    'Priority Support' => [false, false, true, true],
                                    'Custom Integrations' => [false, false, false, true],
                                ];
                                @endphp
                                @foreach($features as $feature => $availability)
                                <tr class="{{ $loop->even ? 'bg-gray-50 dark:bg-gray-800/50' : '' }} hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">{{ $feature }}</td>
                                    @foreach($availability as $avail)
                                    <td class="py-3 px-4 text-center">
                                        @if($avail)
                                        <svg class="w-5 h-5 text-purple-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        @else
                                        <svg class="w-5 h-5 text-gray-300 dark:text-gray-600 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
</x-pos-layout>
