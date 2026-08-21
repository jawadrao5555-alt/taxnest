<x-pos-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Customize
            </a>
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">NestPOS Plans</h2>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Annual or quarterly billing — pick a plan, start selling</p>
            </div>

            @if($currentSubscription && $currentSubscription->pricingPlan)
            <div class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-xl p-4 mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-purple-100 dark:bg-purple-800 flex items-center justify-center">
                        <svg class="w-5 h-5 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div>
                        <p class="font-semibold text-purple-800 dark:text-purple-200">{{ $currentSubscription->pricingPlan->name }} Plan</p>
                        <p class="text-xs text-purple-600 dark:text-purple-400">Active until {{ \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y') }}</p>
                    </div>
                </div>
                <span class="px-3 py-1 bg-purple-600 text-white rounded-full text-xs font-bold">ACTIVE</span>
            </div>
            @endif

            {{-- Paid extra branches (Rs 10,000/branch/year add-on). The renewal total
                 comes from SubscriptionAssignmentService::computePrice($plan, $cycle,
                 $company) — the SAME formula the renewal charge uses, so this page can
                 never disagree with the expiry popup / lock modal / admin panel. --}}
            @php
                $ebSlots = \App\Services\BranchAddonService::slots($company);
                $ebRenewal = ($ebSlots > 0 && $currentSubscription && $currentSubscription->pricingPlan)
                    ? \App\Services\SubscriptionAssignmentService::computePrice(
                        $currentSubscription->pricingPlan,
                        $currentSubscription->billing_cycle ?? 'annual',
                        $company
                      )
                    : null;
            @endphp
            @if($ebRenewal)
            <div class="bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-700 rounded-xl p-4 mb-8">
                <p class="text-sm font-semibold text-sky-800 dark:text-sky-200">
                    {{ __('pos.eb_billing_title', ['slots' => $ebSlots]) }}
                </p>
                <p class="text-xs text-sky-700 dark:text-sky-300 mt-1">
                    PKR {{ number_format($ebRenewal['base_price']) }} {{ __('pos.eb_package_word') }}
                    + PKR {{ number_format($ebRenewal['extra_branch_price']) }} {{ __('pos.eb_branches_word') }}
                    = <span class="font-bold">PKR {{ number_format($ebRenewal['final_price']) }}</span>
                    {{ __('pos.eb_on_renewal') }}
                </p>
            </div>
            @endif

            @php $annualDiscount = 6; @endphp

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach($plans as $plan)
                @php
                    $yearlyTotal = round($plan->sale_price);
                    $perMonth = round($plan->sale_price / 12);
                    $hasOffer = $plan->sale_percent > 0;
                    $compareYearly = $hasOffer ? round($plan->price) : 0;
                    $isCurrent = $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id;
                    $isPopular = $plan->name === 'Business';
                @endphp
                <div class="relative rounded-2xl overflow-hidden transition duration-300 hover:-translate-y-1 {{ $isPopular ? 'ring-2 ring-purple-500 shadow-lg' : 'shadow-sm' }}">
                    @if($isPopular)
                    <div class="bg-purple-600 text-center py-1.5">
                        <span class="text-white text-xs font-bold tracking-wide">MOST POPULAR</span>
                    </div>
                    @endif
                    <div class="bg-white dark:bg-gray-900 shadow-md border {{ $isPopular ? 'border-purple-500 border-t-0' : 'border-gray-200 dark:border-gray-800' }} {{ $isPopular ? '' : 'rounded-2xl' }} {{ $isPopular ? 'rounded-b-2xl' : '' }} p-5">
                        @if($isCurrent)
                        <span class="inline-block px-2 py-0.5 bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300 rounded text-[10px] font-bold mb-2">YOUR PLAN</span>
                        @endif
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h3>

                        <div class="mt-4 mb-1">
                            @if($hasOffer)
                            <div class="mb-1.5"><span class="inline-block px-2 py-0.5 bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300 rounded-full text-[11px] font-bold">{{ $plan->sale_badge }}</span></div>
                            <span class="text-base text-gray-400 line-through mr-1">PKR {{ number_format($compareYearly) }}</span>
                            @endif
                            <span class="text-3xl font-black text-gray-900 dark:text-gray-100">PKR {{ number_format($yearlyTotal) }}</span>
                            <span class="text-gray-400 text-sm">/year</span>
                        </div>
                        <p class="text-xs text-gray-400">PKR {{ number_format($perMonth) }}/mo effective</p>
                        @if((float) ($plan->price_quarterly ?? 0) > 0)
                        {{-- never glue @if after a word char ("months@if") — Blade skips the @if
                             but compiles the @endif → unmatched endif → ParseError 500. --}}
                        <p class="text-xs text-gray-500 mt-0.5 font-medium">
                            or PKR {{ number_format($plan->price_quarterly) }} / 3 months
                            @if($hasOffer)
                                <span class="text-amber-600 font-semibold">(sale sirf annual par)</span>
                            @endif
                        </p>
                        @endif
                        @if($hasOffer)<p class="text-xs text-purple-600 font-medium mt-0.5">Save PKR {{ number_format($compareYearly - $yearlyTotal) }}</p>@endif

                        @php
                            $prevPlan = $loop->index > 0 ? ($plans[$loop->index - 1] ?? null) : null;
                            // Task 1384 — bullets are generated from the SAME plan columns the
                            // comparison table below reads. Numbers (bills / team / branches /
                            // counters / products) live in that table ONLY, so a card can never
                            // promise something the table disagrees with.
                            $highlights = \App\Services\PosPlanComparisonService::cardHighlights($plan, $prevPlan);
                            $inherits   = \App\Services\PosPlanComparisonService::cardInherits($plan, $prevPlan);
                            $floorHolds = \App\Services\PosPlanComparisonService::cardIncludedFloorHolds($plans);
                        @endphp

                        @if(!empty($highlights))
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-purple-600 dark:text-purple-400 mb-2">
                                {{-- Only claim the package below (or the whole ladder) when the plan rows still back it up. --}}
                                {{ $inherits ? 'Everything in ' . $prevPlan->name . ', plus:' : (!$prevPlan && $floorHolds ? 'Every package includes:' : 'This package includes:') }}
                            </p>
                            <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                                @foreach($highlights as $highlight)
                                <div class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-purple-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>
                                        {{ $highlight['label'] }}
                                        @if(!empty($highlight['hint']))
                                        <span class="block text-[11px] text-gray-400 dark:text-gray-500">{{ $highlight['hint'] }}</span>
                                        @endif
                                    </span>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif
                        <p class="mt-3 text-[11px] text-gray-400 dark:text-gray-500">Bills, team accounts, branches &amp; counters — see the comparison table below.</p>

                        <div class="mt-5">
                            @if($isCurrent)
                            <button disabled class="w-full py-2.5 rounded-lg text-sm font-semibold bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed">Current Plan</button>
                            @else
                            <a href="{{ route('pos.landing') }}" class="block w-full py-2.5 rounded-lg text-sm font-semibold text-center transition
                                {{ $isPopular ? 'bg-purple-600 text-white hover:bg-purple-700 shadow-sm' : 'bg-gray-900 dark:bg-gray-700 text-white hover:bg-gray-800 dark:hover:bg-gray-600' }}">
                                Get {{ $plan->name }}
                            </a>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Same comparison table the landing page shows (Task 1350), with the
                 customer's own package highlighted so an upgrade is a glance away. --}}
            <x-pos-plan-comparison
                :plans="$plans"
                :current-plan-id="$currentSubscription->pricing_plan_id ?? null"
                surface="panel"
                class="mt-10" />

            <div class="mt-8 text-center">
                <div class="inline-flex items-center gap-6 text-xs text-gray-400 dark:text-gray-500">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        PRA compliant
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="text-xs font-bold text-purple-400">PKR</span>
                        Annual or quarterly billing
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        3-day free trial
                    </span>
                </div>
            </div>
        </div>
    </div>
</x-pos-layout>
