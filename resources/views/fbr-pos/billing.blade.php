<x-fbr-pos-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">FBR POS Plans</h2>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Simple annual billing — pick a plan, start selling</p>
            </div>

            @if($currentSubscription && $currentSubscription->pricingPlan)
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-4 mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-800 flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div>
                        <p class="font-semibold text-blue-800 dark:text-blue-200">{{ $currentSubscription->pricingPlan->name }} Plan</p>
                        <p class="text-xs text-blue-600 dark:text-blue-400">Active until {{ \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y') }}</p>
                    </div>
                </div>
                <span class="px-3 py-1 bg-blue-600 text-white rounded-full text-xs font-bold">ACTIVE</span>
            </div>
            @endif

            @php $annualDiscount = 6; @endphp

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($plans as $plan)
                @php
                    $yearlyTotal = round($plan->price * 12 * (1 - $annualDiscount / 100));
                    $perMonth = round($yearlyTotal / 12);
                    $saved = round($plan->price * 12 * $annualDiscount / 100);
                    $isCurrent = $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id;
                    $isPopular = $plan->name === 'Business';
                @endphp
                <div class="relative rounded-2xl overflow-hidden transition duration-300 hover:-translate-y-1 {{ $isPopular ? 'ring-2 ring-blue-500 shadow-lg shadow-blue-500/10' : 'shadow-sm' }}">
                    @if($isPopular)
                    <div class="bg-blue-600 text-center py-1.5">
                        <span class="text-white text-xs font-bold tracking-wide">MOST POPULAR</span>
                    </div>
                    @endif
                    <div class="bg-white dark:bg-gray-900 shadow-md border {{ $isPopular ? 'border-blue-500 border-t-0' : 'border-gray-200 dark:border-gray-800' }} {{ $isPopular ? '' : 'rounded-2xl' }} {{ $isPopular ? 'rounded-b-2xl' : '' }} p-5">
                        @if($isCurrent)
                        <span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 rounded text-[10px] font-bold mb-2">YOUR PLAN</span>
                        @endif
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h3>

                        <div class="mt-4 mb-1">
                            <span class="text-3xl font-black text-gray-900 dark:text-gray-100">PKR {{ number_format($yearlyTotal) }}</span>
                            <span class="text-gray-400 text-sm">/year</span>
                        </div>
                        <p class="text-xs text-gray-400">PKR {{ number_format($perMonth) }}/mo effective</p>
                        <p class="text-xs text-blue-600 font-medium mt-0.5">Save PKR {{ number_format($saved) }}</p>

                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            @php
                                $planFeatures = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                            @endphp
                            @if(!empty($planFeatures))
                                @foreach($planFeatures as $feature)
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    {{ $feature }}
                                </div>
                                @endforeach
                            @endif
                        </div>

                        <div class="mt-5">
                            @if($isCurrent)
                            <span class="block w-full py-2.5 rounded-lg text-sm font-semibold text-center bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 cursor-default">Current Plan</span>
                            @else
                            <button class="w-full py-2.5 rounded-lg text-sm font-semibold text-center transition shadow-md hover:shadow-lg {{ $isPopular ? 'bg-gradient-to-r from-blue-500 to-blue-700 text-white' : 'bg-gray-900 text-white hover:bg-gray-800 dark:bg-gray-700 dark:hover:bg-gray-600' }}">
                                Select Plan
                            </button>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-8 text-center">
                <div class="inline-flex flex-wrap items-center justify-center gap-6 text-xs text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Annual billing only (6% discount included)
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        14-day free trial
                    </span>
                </div>
            </div>
        </div>
    </div>
</x-fbr-pos-layout>
