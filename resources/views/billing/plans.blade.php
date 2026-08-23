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
                                <p class="text-sm text-blue-600">@if((int) $usageData['trial']['days_left'] === 0) Expires today @elseif((int) $usageData['trial']['days_left'] === 1) 1 day left - Expires @else {{ (int) $usageData['trial']['days_left'] }} days left - Expires @endif {{ $usageData['trial']['ends_at'] }}. Upgrade now to keep your data.</p>
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
                                <p class="text-sm text-yellow-600">@if((int) $usageData['days_left'] === 0) <span class="font-bold">Expires today</span> on your @elseif((int) $usageData['days_left'] === 1) <span class="font-bold">1</span> day remaining on your @else <span class="font-bold">{{ (int) $usageData['days_left'] }}</span> days remaining on your @endif {{ $currentSubscription->pricingPlan->name }} plan</p>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Current Plan: {{ $currentSubscription->pricingPlan->name }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                {{ \App\Models\Subscription::getCycleLabel($usageData['billing_cycle']) }} billing
                                &middot; Active until {{ \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y') }}
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-2">
                            <span class="inline-flex px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-sm font-medium">Active</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">
                                Invoices this month
                                @if($usageData['has_override'])
                                    <span class="ml-1 inline-flex px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 text-[10px] font-bold align-middle">CUSTOM</span>
                                @endif
                            </p>
                            <div class="flex items-center space-x-3">
                                @if($usageData['invoice_limit'] === -1)
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ number_format($usageData['invoice_count']) }} / Unlimited</span>
                                @else
                                    <div class="flex-1 bg-gray-200 rounded-full h-3">
                                        <div class="h-3 rounded-full transition-all {{ $usageData['usage_percent'] > 80 ? 'bg-red-500' : 'bg-emerald-500' }}"
                                            style="width: {{ $usageData['usage_percent'] }}%"></div>
                                    </div>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ number_format($usageData['invoice_count']) }}/{{ number_format($usageData['invoice_limit']) }}</span>
                                @endif
                            </div>
                            <p class="text-[11px] text-gray-400 mt-1">Only FBR-submitted invoices count &middot; resets {{ $usageData['quota_resets_on'] }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">AI Reader pages</p>
                            @if($aiPages)
                                @if($aiPages['unlimited'])
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Unlimited</span>
                                @else
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ number_format($aiPages['allowance_used']) }}/{{ number_format($aiPages['allowance']) }} used</span>
                                    <p class="text-[11px] text-gray-400 mt-1">
                                        @if($aiPages['purchased'] > 0)
                                            + {{ number_format($aiPages['purchased']) }} purchased pages
                                        @else
                                            Monthly allowance &middot; resets {{ $aiPages['resets_on'] }}
                                        @endif
                                    </p>
                                @endif
                            @else
                                <span class="text-sm font-medium text-gray-400">—</span>
                            @endif
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Users</p>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $usageData['user_count'] }} / {{ $usageData['user_limit_display'] }}</span>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Branches</p>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $usageData['branch_count'] }} / {{ $usageData['branch_limit_display'] }}</span>
                        </div>
                    </div>
                </div>
            @endif

            {{-- AI Reader page top-up (Sep 2026). Monthly allowance package ke sath
                 aati hai; khatam ho jaye to shop yahan se extra pages khareedti hai.
                 Approve par SIRF pages barhte hain — package ko haath nahi lagta. --}}
            @if($aiPages && $aiReaderAllowed && !$aiPages['unlimited'])
            @php
                $aiBank = [
                    'bank_name' => \App\Models\SystemSetting::get('payment_bank_name', ''),
                    'account_title' => \App\Models\SystemSetting::get('payment_account_title', ''),
                    'account_number' => \App\Models\SystemSetting::get('payment_account_number', ''),
                    'iban' => \App\Models\SystemSetting::get('payment_iban', ''),
                    'instructions' => \App\Models\SystemSetting::get('payment_instructions', ''),
                ];
                $aiCanBuy = in_array(auth()->user()->role, ['super_admin', 'company_admin'], true);
                $aiPackList = [];
                foreach ($aiPages['packs'] as $aiPackPages => $aiPackPrice) {
                    $aiPackList[] = ['pages' => (int) $aiPackPages, 'price' => (int) $aiPackPrice];
                }
            @endphp
            <div id="ai-pages" class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8"
                 x-data="{ open: false, pack: {{ $aiPackList[1]['pages'] ?? ($aiPackList[0]['pages'] ?? 100) }} }">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                            <svg class="w-5 h-5 text-fuchsia-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            AI Reader Pages
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            {{ number_format($aiPages['allowance_remaining']) }} of {{ number_format($aiPages['allowance']) }} monthly pages left &middot; resets {{ $aiPages['resets_on'] }}.
                            @if($aiPages['purchased'] > 0)
                                You also have <span class="font-semibold text-gray-700 dark:text-gray-300">{{ number_format($aiPages['purchased']) }} purchased pages</span> that never expire.
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400 uppercase tracking-wide">Available now</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($aiPages['total_remaining']) }}</p>
                        <p class="text-xs text-gray-400">pages</p>
                        <a href="{{ route('billing.ai-pages') }}" class="text-xs font-semibold text-fuchsia-600 dark:text-fuchsia-400 hover:underline">Page history</a>
                    </div>
                </div>

                @if($aiTopupPending)
                    <div class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                        Your top-up payment is under review. The pages will be added as soon as our team verifies it.
                    </div>
                @elseif($aiCanBuy)
                    <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        @foreach($aiPackList as $aiPack)
                        <label class="relative flex flex-col rounded-xl border p-4 cursor-pointer transition"
                               :class="pack === {{ $aiPack['pages'] }} ? 'border-fuchsia-500 bg-fuchsia-50 dark:bg-fuchsia-900/20 ring-1 ring-fuchsia-500' : 'border-gray-200 dark:border-gray-700 hover:border-fuchsia-300'">
                            <input type="radio" class="sr-only" :checked="pack === {{ $aiPack['pages'] }}" @change="pack = {{ $aiPack['pages'] }}">
                            <span class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ number_format($aiPack['pages']) }} pages</span>
                            <span class="text-sm font-semibold text-fuchsia-600 dark:text-fuchsia-400 mt-1">PKR {{ number_format($aiPack['price']) }}</span>
                            <span class="text-[11px] text-gray-400 mt-1">Rs {{ number_format($aiPack['price'] / $aiPack['pages'], 2) }} per page</span>
                        </label>
                        @endforeach
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-[11px] text-gray-400 dark:text-gray-500 max-w-md">
                            Purchased pages never expire and are only used after your monthly allowance is finished. A page is charged only when a read succeeds.
                        </p>
                        <button type="button" @click="open = !open"
                                class="bg-fuchsia-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-fuchsia-700 transition">
                            Buy Pages
                        </button>
                    </div>

                    <div x-show="open" x-cloak class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                        <form method="POST" action="{{ route('payment-proof.store') }}" enctype="multipart/form-data" class="space-y-3">
                            @csrf
                            <input type="hidden" name="request_type" value="ai_pages">
                            <input type="hidden" name="ai_pages" :value="pack">

                            @if($aiBank['bank_name'] || $aiBank['account_number'] || $aiBank['iban'])
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs text-gray-600 dark:text-gray-300 space-y-0.5">
                                <p class="font-semibold text-gray-700 dark:text-gray-200">Bank details</p>
                                @if($aiBank['bank_name'])<p>{{ $aiBank['bank_name'] }}</p>@endif
                                @if($aiBank['account_title'])<p>{{ $aiBank['account_title'] }}</p>@endif
                                @if($aiBank['account_number'])<p class="font-mono">{{ $aiBank['account_number'] }}</p>@endif
                                @if($aiBank['iban'])<p class="font-mono">{{ $aiBank['iban'] }}</p>@endif
                                @if($aiBank['instructions'])<p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $aiBank['instructions'] }}</p>@endif
                            </div>
                            @endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <select name="payment_method"
                                        class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                                    <option value="">Payment method</option>
                                    <option value="bank">Bank Transfer</option>
                                    <option value="jazzcash">JazzCash</option>
                                    <option value="easypaisa">EasyPaisa</option>
                                    <option value="other">Other</option>
                                </select>
                                <input type="text" name="reference" maxlength="120" placeholder="Transaction reference (optional)"
                                       autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                       class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            </div>
                            <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required
                                   class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-fuchsia-600 file:text-white hover:file:bg-fuchsia-500">
                            @error('proof')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                            @error('ai_pages')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                            <p class="text-[11px] text-gray-400">JPG, PNG or PDF &middot; up to 5 MB</p>

                            <button type="submit" class="w-full sm:w-auto bg-fuchsia-600 text-white rounded-lg px-5 py-2.5 text-sm font-semibold hover:bg-fuchsia-700">
                                Submit Payment Proof
                            </button>
                        </form>
                    </div>
                @endif
            </div>
            @endif

            <div class="text-center mb-8">
                <h3 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Choose Your Plan</h3>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Every package includes FBR digital invoicing, the AI Invoice Reader and the 6-year audit archive.</p>
            </div>

            {{-- Prices come from the SERVER (planPricing), never from a client-side
                 discount ladder — the card, the toggle, the comparison table and
                 checkout must all quote the same figure. --}}
            <div x-data="{
                cycle: 'annual',
                pricing: {{ \Illuminate\Support\Js::from($planPricing) }},
                months: { annual: 12 },
                cycleLabels: { annual: 'year' },
                row(planId) { return (this.pricing[planId] || {})[this.cycle] || null; },
                total(planId) { let r = this.row(planId); return r ? Math.round(r.final_price) : 0; },
                perMonth(planId) { let r = this.row(planId); return r ? Math.round(r.monthly_effective) : 0; },
                saving(planId) { let r = this.row(planId); return r ? Math.max(0, Math.round(r.total_before_discount - r.final_price)) : 0; },
                fmt(n) { return Number(n).toLocaleString('en-US'); }
            }">
                {{-- Annual-only since 23 Aug 2026 (owner): nothing to toggle, so
                     the row just states how the packages are billed. --}}
                <div class="flex justify-center mb-8">
                    <span class="inline-flex items-center gap-2 bg-gray-100 dark:bg-gray-800 rounded-xl px-4 py-2 border border-gray-200 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-300">
                        Billed annually
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($plans as $plan)
                    @php
                        $hasOffer = $plan->sale_percent > 0;
                        // "Most popular" = the middle package, whatever it is called.
                        $isFeatured = $plans->count() === 3 && $loop->index === 1;
                        $isCurrent = $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id;
                        $planFeatures = $plan->features;
                        if (is_string($planFeatures)) $planFeatures = json_decode($planFeatures, true);
                        if (is_string($planFeatures)) $planFeatures = json_decode($planFeatures, true);
                        if (!is_array($planFeatures)) $planFeatures = [];
                    @endphp
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border-2 transition relative
                        {{ $isFeatured ? 'border-emerald-500 ring-2 ring-emerald-500' : 'border-gray-200 dark:border-gray-800' }}
                        {{ $isCurrent ? 'border-emerald-500' : '' }}">

                        @if($isFeatured)
                        <div class="absolute -top-3 left-1/2 transform -translate-x-1/2">
                            <span class="bg-emerald-600 text-white text-xs font-bold px-3 py-1 rounded-full shadow-sm">MOST POPULAR</span>
                        </div>
                        @endif

                        <div class="p-6">
                            @if($isCurrent)
                            <span class="inline-flex px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded text-xs font-medium mb-3">Current Plan</span>
                            @endif
                            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h3>
                            @if($hasOffer)
                            <span class="inline-block mt-2 px-2 py-0.5 bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300 rounded-full text-[11px] font-bold">{{ $plan->sale_badge }}</span>
                            @endif
                            <div class="mt-4">
                                <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">PKR <span x-text="fmt(perMonth({{ $plan->id }}))"></span></span>
                                <span class="text-gray-500 dark:text-gray-400 text-sm">/mo</span>
                                <p class="text-xs text-gray-400 mt-1">
                                    PKR <span x-text="fmt(total({{ $plan->id }}))"></span> billed every year
                                    <template x-if="saving({{ $plan->id }}) > 0">
                                        <span class="text-emerald-600 font-semibold"> &middot; save PKR <span x-text="fmt(saving({{ $plan->id }}))"></span></span>
                                    </template>
                                </p>
                            </div>
                            <ul class="mt-5 space-y-2.5">
                                @foreach($planFeatures as $feature)
                                <li class="flex items-start text-sm text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4 text-emerald-500 mr-2 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>{{ $feature }}</span>
                                </li>
                                @endforeach
                            </ul>
                            @if(!$isCurrent)
                            <form method="POST" action="/billing/subscribe" class="mt-6">
                                @csrf
                                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                <input type="hidden" name="billing_cycle" :value="cycle">
                                <button type="submit" class="w-full py-2.5 rounded-lg font-semibold text-sm transition shadow-sm
                                    {{ $isFeatured ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-gray-900 text-white hover:bg-gray-800' }}">
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

                {{-- Sep 2026: the self-serve "Build Custom Plan" card is gone. The
                     catalogue is exactly these three packages; anything special is
                     an admin arrangement (limit overrides), not a plan a shop can
                     invent for itself at a formula price nobody reviewed. --}}

                {{-- Comparison table. Every row is driven by the SAME plan rows and
                     feature gates the cards use, so the two can never drift apart. --}}
                <div class="mt-8 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-800">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Feature Comparison</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500 dark:text-gray-400 w-1/4">Feature</th>
                                    @foreach($plans as $plan)
                                    <th class="text-center py-3 px-4 text-sm font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">Price</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        PKR <span x-text="fmt(total({{ $plan->id }}))"></span>
                                        <span class="block text-[11px] font-normal text-gray-400">per <span x-text="cycleLabels[cycle]"></span></span>
                                    </td>
                                    @endforeach
                                </tr>
                                <tr class="bg-gray-50 dark:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">FBR invoices / month</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ $plan->getInvoiceLimitDisplay() }}
                                        @if($plan->invoice_limit === -1 && (int) ($plan->fair_use_limit ?? 0) > 0)
                                        <span class="block text-[11px] font-normal text-gray-400">fair use {{ number_format($plan->fair_use_limit) }}</span>
                                        @endif
                                    </td>
                                    @endforeach
                                </tr>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">AI Reader pages / month</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $plan->getAiPageLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                <tr class="bg-gray-50 dark:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">Users</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $plan->getUserLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">Branches</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $plan->getBranchLimitDisplay() }}</td>
                                    @endforeach
                                </tr>
                                @php
                                    // Ticks come from the real gates (DiFeatureService) or from the
                                    // package's own position in the ladder — never a name lookup,
                                    // which silently turns into a column of crosses after a rename.
                                    $rankByPlanId = [];
                                    foreach ($plans as $i => $p) {
                                        $rankByPlanId[$p->id] = $i + 1;
                                    }
                                    $comparisonRows = [
                                        'FBR e-invoicing & digital invoice numbers' => fn ($p) => true,
                                        'PDF, WhatsApp & share-link delivery' => fn ($p) => true,
                                        'Excel / CSV bulk import' => fn ($p) => true,
                                        'AI Invoice Reader (PDF, Excel, photo)' => fn ($p) => \App\Services\DiFeatureService::planIncludes($p, 'ai_reader'),
                                        'Customers, products & MIS reports' => fn ($p) => true,
                                        'Compliance scoring' => fn ($p) => true,
                                        'FBR Audit Pack (6-year archive)' => fn ($p) => true,
                                        'White-label branding' => fn ($p) => \App\Services\DiFeatureService::planIncludes($p, 'white_label'),
                                        'Public REST API & webhooks' => fn ($p) => \App\Services\DiFeatureService::planIncludes($p, 'public_api'),
                                        'Priority support' => fn ($p) => ($rankByPlanId[$p->id] ?? 0) >= 2,
                                        'Dedicated account manager' => fn ($p) => ($rankByPlanId[$p->id] ?? 0) >= 3,
                                    ];
                                @endphp
                                @foreach($comparisonRows as $feature => $has)
                                <tr class="{{ $loop->even ? 'bg-gray-50 dark:bg-gray-800' : '' }} hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                    <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">{{ $feature }}</td>
                                    @foreach($plans as $plan)
                                    <td class="py-3 px-4 text-center">
                                        @if($has($plan))
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
                    <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-500 dark:text-gray-400 space-y-1">
                        <p>Invoice quota is per calendar month and only counts invoices actually submitted to FBR — drafts and failed submissions are free.</p>
                        <p>Extra AI Reader pages can be topped up any time from this page; purchased pages never expire.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
