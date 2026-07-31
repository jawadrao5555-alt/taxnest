<x-fbr-pos-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="{{ route('fbrpos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition mb-3">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_to_customize') }}
            </a>
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('pos.fbr_pos_plans') }}</h2>
                <p class="text-gray-500 dark:text-gray-400 mt-2">{{ __('pos.simple_annual_billing') }}</p>
            </div>

            @if($currentSubscription && $currentSubscription->pricingPlan)
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-4 mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-800 flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div>
                        <p class="font-semibold text-blue-800 dark:text-blue-200">{{ __('pos.plan_named', ['name' => $currentSubscription->pricingPlan->name]) }}</p>
                        <p class="text-xs text-blue-600 dark:text-blue-400">{{ __('pos.active_until', ['date' => \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y')]) }}</p>
                    </div>
                </div>
                <span class="px-3 py-1 bg-blue-600 text-white rounded-full text-xs font-bold">{{ __('pos.active_caps') }}</span>
            </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($plans as $plan)
                @php
                    $yearlyTotal = (int) round($plan->sale_price * 12 * 0.94);
                    $perMonth = (int) round($yearlyTotal / 12);
                    $hasOffer = ($plan->sale_percent ?? 0) > 0;
                    $compareYearly = $hasOffer ? (int) round($plan->price * 12 * 0.94) : 0;
                    $isCurrent = $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id;
                    $isPopular = $plan->name === 'Business';
                    $planFeatures = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                @endphp
                <div class="relative rounded-2xl overflow-hidden transition duration-300 hover:-translate-y-1 {{ $isPopular ? 'ring-2 ring-blue-500' : '' }} shadow-sm">
                    @if($isPopular)
                    <div class="bg-blue-600 text-center py-1.5">
                        <span class="text-white text-xs font-bold tracking-wide">{{ __('pos.most_popular') }}</span>
                    </div>
                    @endif
                    <div class="bg-white dark:bg-gray-900 border {{ $isPopular ? 'border-blue-500 border-t-0 rounded-b-2xl' : 'border-gray-200 dark:border-gray-800 rounded-2xl' }} p-5">
                        @if($isCurrent)
                        <span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 rounded text-[10px] font-bold mb-2">{{ __('pos.your_plan') }}</span>
                        @endif
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h3>

                        <div class="mt-4 mb-1">
                            @if($hasOffer)
                            <div class="mb-1.5"><span class="inline-block px-2 py-0.5 bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300 rounded-full text-[11px] font-bold">{{ $plan->sale_badge }}</span></div>
                            <span class="text-base text-gray-400 line-through mr-1">PKR {{ number_format($compareYearly) }}</span>
                            @endif
                            <span class="text-3xl font-black text-gray-900 dark:text-gray-100">PKR {{ number_format($yearlyTotal) }}</span>
                            <span class="text-gray-400 text-sm">{{ __('pos.per_year') }}</span>
                        </div>
                        <p class="text-xs text-gray-400">{{ __('pos.pkr_per_mo_effective', ['amount' => number_format($perMonth)]) }}</p>
                        @if($hasOffer)<p class="text-xs text-blue-600 font-medium mt-0.5">{{ __('pos.save_pkr', ['amount' => number_format($compareYearly - $yearlyTotal)]) }}</p>@endif

                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-2 text-sm text-gray-600 dark:text-gray-400">
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
                            <button disabled class="w-full py-2.5 rounded-lg text-sm font-semibold bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed">{{ __('pos.current_plan') }}</button>
                            @else
                            <a href="{{ route('fbrpos.landing') }}#pricing" class="block w-full py-2.5 rounded-lg text-sm font-semibold text-center transition
                                {{ $isPopular ? 'bg-blue-600 text-white hover:bg-blue-700 shadow-sm' : 'bg-gray-900 dark:bg-gray-700 text-white hover:bg-gray-800 dark:hover:bg-gray-600' }}">
                                {{ __('pos.get_plan', ['name' => $plan->name]) }}
                            </a>
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
                        {{ __('pos.fbr_compliant') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="text-xs font-bold text-blue-400">PKR</span>
                        {{ __('pos.annual_savings_6') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        {{ __('pos.free_trial_3day') }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</x-fbr-pos-layout>
