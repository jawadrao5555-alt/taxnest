<x-pos-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Customize
            </a>
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">NestPOS Plans</h2>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Annual billing — pick a plan, start selling</p>
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

            {{-- ── Optional paid features (Aug 2026) ──
                 Six add-ons a Business+ shop can buy instead of upgrading. Every
                 price and the purchasable list come from PosAddonService /
                 PosAddonPricingService, so this box can never quote something the
                 approval will not activate. Features the package already grants are
                 filtered out server-side. --}}
            @php
                $adCatalog = $addons['catalog'] ?? [];
                $adEligible = $addons['eligibility']['allowed'] ?? false;
                $adPurchasable = $addons['purchasable'] ?? [];
                $adActive = $addons['active'] ?? [];
                $adPending = $addons['pending'] ?? [];
                $adCanBuy = $addons['can_buy'] ?? true;
                $adPreselected = array_values(array_intersect((array) ($addons['preselected'] ?? []), $adPurchasable));
                $adPreselectedCycle = \App\Services\PosAddonPricingService::normalizeCycle($addons['preselected_cycle'] ?? null);
                $adForceOpen = session('payment_proof') || $errors->has('proof') || $errors->has('addon_codes') || !empty($adPreselected);
                $adPrices = [];
                $adTerm = null;
                foreach ($adPurchasable as $adCode) {
                    foreach (\App\Services\PosAddonPricingService::CYCLES as $adCycle) {
                        $adQuote = $addons['quotes'][$adCode][$adCycle] ?? [];
                        $adPrices[$adCode][$adCycle] = (int) ($adQuote['lines'][$adCode] ?? 0);
                        $adTerm ??= $adQuote;
                    }
                }
            @endphp

            <div class="mt-10">
                <div class="text-center mb-5">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ __('pos.addons_title') }}</h3>
                    <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">{{ __('pos.addons_subtitle') }}</p>
                </div>

                @if(!empty($adActive))
                <div class="mb-4 rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-4">
                    <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">{{ __('pos.addons_active_title') }}</p>
                    <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-1">
                        {{ implode(', ', array_map(fn ($c) => __('pos.addon_label_' . $c), $adActive)) }}
                    </p>
                </div>
                @endif

                @if(!empty($adPending))
                <div class="mb-4 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">{{ __('pos.addons_pending_title') }}</p>
                    <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">
                        {{ __('pos.addons_pending_desc') }}
                        <span class="block mt-0.5 font-medium">{{ implode(', ', array_map(fn ($c) => __('pos.addon_label_' . $c), $adPending)) }}</span>
                    </p>
                </div>
                @endif

                @if(!$adEligible)
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __($addons['eligibility']['reason_key'] ?? 'pos.addons_not_available') }}</p>
                </div>
                @elseif(empty($adPurchasable))
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('pos.addons_none_left') }}</p>
                </div>
                @elseif(!$adCanBuy)
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('pos.addons_owner_only') }}</p>
                </div>
                @else
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-md p-5"
                     x-data="{
                        open: {{ $adForceOpen ? 'true' : 'false' }},
                        cycle: {{ \Illuminate\Support\Js::from(old('addon_cycle', $adPreselectedCycle)) }},
                        picked: {{ \Illuminate\Support\Js::from(array_values(array_intersect((array) old('addon_codes', $adPreselected), $adPurchasable))) }},
                        prices: {{ \Illuminate\Support\Js::from($adPrices) }},
                        toggle(code) {
                            const i = this.picked.indexOf(code);
                            if (i === -1) { this.picked.push(code); } else { this.picked.splice(i, 1); }
                        },
                        priceOf(code) {
                            const row = this.prices[code];
                            return row ? Number(row[this.cycle] || 0) : 0;
                        },
                        total() {
                            return this.picked.reduce((sum, code) => sum + this.priceOf(code), 0);
                        },
                        fmt(v) { return Number(v || 0).toLocaleString('en-US'); }
                     }">
                    {{-- Annual-only since 23 Aug 2026 (owner): no cycle picker,
                         the strip just states what the shop is buying. --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <span class="inline-flex items-center rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200">
                            {{ __('pos.addons_cycle_annual') }}
                        </span>
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('pos.addons_included_note') }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach($adPurchasable as $adCode)
                        <label class="relative flex flex-col gap-1 rounded-xl border p-4 cursor-pointer transition"
                               :class="picked.includes({{ \Illuminate\Support\Js::from($adCode) }})
                                    ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20 ring-1 ring-purple-500'
                                    : 'border-gray-200 dark:border-gray-700 hover:border-purple-300'">
                            <div class="flex items-start gap-2">
                                <input type="checkbox" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                       :checked="picked.includes({{ \Illuminate\Support\Js::from($adCode) }})"
                                       @change="toggle({{ \Illuminate\Support\Js::from($adCode) }})">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('pos.addon_label_' . $adCode) }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.addon_desc_' . $adCode) }}</p>
                                </div>
                            </div>
                            <p class="text-sm font-bold text-purple-700 dark:text-purple-300 mt-1">
                                PKR <span x-text="fmt(priceOf({{ \Illuminate\Support\Js::from($adCode) }}))"></span>
                                <span class="text-[11px] font-medium text-gray-400">/ {{ __('pos.addons_per_year') }}</span>
                            </p>
                        </label>
                        @endforeach
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ __('pos.addons_total_label') }}
                            <span class="font-bold text-gray-900 dark:text-gray-100">PKR <span x-text="fmt(total())"></span></span>
                        </p>
                        <button type="button" @click="open = !open" :disabled="picked.length === 0"
                                class="bg-purple-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed">
                            {{ __('pos.addons_buy_cta') }}
                        </button>
                    </div>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-2">
                        @if(($adTerm['until'] ?? null) && ($adTerm['months'] ?? 0) > 0)
                            {{ __('pos.addons_prorated_note', ['months' => $adTerm['months'], 'date' => \Illuminate\Support\Carbon::parse($adTerm['until'])->format('d M Y')]) }}
                        @endif
                        {{ __('pos.addons_renewal_note') }}
                    </p>

                    <div x-show="open && picked.length > 0" x-cloak class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                        <form method="POST" action="{{ route('pos.payment-proof.store') }}" enctype="multipart/form-data" class="space-y-3">
                            @csrf
                            <input type="hidden" name="request_type" value="pos_addon">
                            <input type="hidden" name="addon_cycle" :value="cycle">
                            <template x-for="code in picked" :key="code">
                                <input type="hidden" name="addon_codes[]" :value="code">
                            </template>

                            @error('addon_codes')<p class="text-xs text-red-500">{{ $message }}</p>@enderror

                            @if($bank['bank_name'] || $bank['account_number'] || $bank['iban'])
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs text-gray-600 dark:text-gray-300 space-y-0.5">
                                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.pp_bank_details') }}</p>
                                @if($bank['bank_name'])<p>{{ $bank['bank_name'] }}</p>@endif
                                @if($bank['account_title'])<p>{{ $bank['account_title'] }}</p>@endif
                                @if($bank['account_number'])<p class="font-mono">{{ $bank['account_number'] }}</p>@endif
                                @if($bank['iban'])<p class="font-mono">{{ $bank['iban'] }}</p>@endif
                                @if($bank['instructions'])<p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $bank['instructions'] }}</p>@endif
                            </div>
                            @endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" placeholder="{{ __('pos.pp_amount_paid') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                       class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                                <select name="payment_method"
                                        class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                                    <option value="">{{ __('pos.pp_payment_method') }}</option>
                                    <option value="bank" @selected(old('payment_method') === 'bank')>{{ __('pos.pp_method_bank') }}</option>
                                    <option value="jazzcash" @selected(old('payment_method') === 'jazzcash')>JazzCash</option>
                                    <option value="easypaisa" @selected(old('payment_method') === 'easypaisa')>EasyPaisa</option>
                                    <option value="other" @selected(old('payment_method') === 'other')>{{ __('pos.pp_method_other') }}</option>
                                </select>
                            </div>
                            <input type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="{{ __('pos.pp_reference') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required
                                   class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-600 file:text-white hover:file:bg-purple-500">
                            @error('proof')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                            <p class="text-[11px] text-gray-400">{{ __('pos.pp_accepted_formats') }}</p>

                            <button type="submit" class="w-full sm:w-auto bg-purple-600 text-white rounded-lg px-5 py-2.5 text-sm font-semibold hover:bg-purple-700">
                                {{ __('pos.addons_submit_cta') }}
                            </button>
                        </form>
                    </div>
                </div>
                @endif
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
                        Annual billing
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
