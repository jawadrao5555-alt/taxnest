<div class="bg-gray-900 border border-gray-800 rounded-xl p-5" x-data="{ editing: false }">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
            <h3 class="text-lg font-bold text-white">{{ $plan->name }}</h3>
            @if($plan->is_trial)<span class="text-[10px] px-1.5 py-0.5 bg-blue-900/50 text-blue-300 rounded font-bold">TRIAL</span>@endif
            @if(!$plan->is_trial && \Illuminate\Support\Facades\Schema::hasColumn('pricing_plans', 'is_public') && !$plan->is_public)
                <span class="text-[10px] px-1.5 py-0.5 bg-amber-900/50 text-amber-300 rounded font-bold" title="Kept for existing subscriptions, hidden from every buying surface">RETIRED</span>
            @endif
            @php $badgeColors = ['di' => 'bg-emerald-900/50 text-emerald-300', 'pos' => 'bg-purple-900/50 text-purple-300', 'fbrpos' => 'bg-blue-900/50 text-blue-300']; @endphp
            <span class="text-[10px] px-1.5 py-0.5 rounded font-bold {{ $badgeColors[$plan->product_type ?? 'di'] ?? 'bg-gray-900/50 text-gray-300' }}">{{ strtoupper($plan->product_type ?? 'di') }}</span>
        </div>
        <button @click="editing = !editing" class="text-xs px-2 py-1 rounded transition" :class="editing ? 'bg-red-600/20 text-red-400 hover:bg-red-600/30' : 'bg-indigo-600/20 text-indigo-400 hover:bg-indigo-600/30'" x-text="editing ? 'Cancel' : 'Edit'"></button>
    </div>

    <div x-show="!editing">
        @php $hasOffer = $plan->sale_percent > 0; @endphp
        <div class="mb-3">
            @if($hasOffer)
            <div class="flex items-center gap-2 mb-1">
                <span class="text-sm text-gray-500 line-through">PKR {{ number_format($plan->price, 0) }}</span>
                <span class="text-[10px] px-1.5 py-0.5 bg-rose-900/40 text-rose-300 rounded font-bold">{{ $plan->sale_badge }}</span>
            </div>
            @endif
            <div class="text-2xl font-bold text-{{ $color }}-400">PKR {{ number_format($hasOffer ? $plan->sale_price : $plan->price, 0) }}<span class="text-sm text-gray-500 dark:text-gray-400 font-normal">{{ in_array($plan->product_type, ['pos', 'fbrpos', 'standalone']) ? '/yr' : '/mo' }}</span></div>
        </div>

        {{-- Sep 2026: this card used to quote the monthly figure ONLY, so the
             package looked like a monthly-only subscription even though
             checkout sells all four cycles. Every cycle a buyer can pick is
             listed with the exact rupees they are charged. --}}
        @if($plan->product_type === 'di')
        @php
            $cycleLabels = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Half-Year', 'annual' => 'Annual'];
            $cycleRates = [];
            foreach ($cycleLabels as $cycleKey => $cycleLabel) {
                $cycleRates[$cycleKey] = [
                    'label'  => $cycleLabel,
                    'row'    => \App\Models\Subscription::priceForPlanCycle($plan, $cycleKey),
                    'is_set' => $plan->explicitCyclePrice($cycleKey) !== null,
                ];
            }
            $anyDerived = collect($cycleRates)->contains(fn ($r) => !$r['is_set']);
        @endphp
        <div class="mb-3 pb-3 border-b border-gray-800">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase mb-1.5">Cycle Rates (what checkout charges)</p>
            <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                @foreach($cycleRates as $rate)
                <div class="flex justify-between gap-2 text-[11px]">
                    <span class="text-gray-400">{{ $rate['label'] }}</span>
                    <span class="text-gray-200 font-medium whitespace-nowrap">
                        {{ number_format($rate['row']['final_price']) }}@if(!$rate['is_set'])<span class="text-amber-400" title="No hand-set rate — worked out from the shared cycle-discount ladder">*</span>@endif
                    </span>
                </div>
                @endforeach
            </div>
            @if($anyDerived)
            <p class="text-[10px] text-amber-400/80 mt-1.5">* worked out from the shared cycle-discount ladder — set a rate in Edit to fix it.</p>
            @endif
        </div>
        @elseif(in_array($plan->product_type, ['pos', 'fbrpos']))
        {{-- Both POS lines bill ANNUALLY by default (price = the yearly rate),
             with hand-set shorter cycles. A blank rate is not sold — checkout
             falls back to annual — so "—" here means "this cycle is off". --}}
        <div class="mb-3 pb-3 border-b border-gray-800">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase mb-1.5">Cycle Rates (what checkout charges)</p>
            <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                <div class="flex justify-between gap-2 text-[11px]"><span class="text-gray-400">Annual</span><span class="text-gray-200 font-medium">{{ number_format($hasOffer ? $plan->sale_price : $plan->price) }}</span></div>
                <div class="flex justify-between gap-2 text-[11px]"><span class="text-gray-400">Quarterly</span><span class="text-gray-200 font-medium">{{ $plan->price_quarterly !== null ? number_format($plan->price_quarterly) : '—' }}</span></div>
                <div class="flex justify-between gap-2 text-[11px]"><span class="text-gray-400">Monthly</span><span class="text-gray-200 font-medium">{{ $plan->price_monthly !== null ? number_format($plan->price_monthly) : '—' }}</span></div>
            </div>
        </div>
        @endif

        <div class="space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-gray-400">Invoices{{ $plan->product_type === 'pos' ? '' : '/mo' }}</span><span class="text-white">{{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) : ($plan->invoice_limit == -1 ? 'Unlimited' : '0') }}</span></div>
            @if($plan->product_type === 'di')
            <div class="flex justify-between"><span class="text-gray-400">AI pages/mo</span><span class="text-white">{{ $plan->getAiPageLimitDisplay() }}</span></div>
            @endif
            <div class="flex justify-between"><span class="text-gray-400">Users</span><span class="text-white">{{ ($plan->max_users ?? 0) == -1 ? 'Unlimited' : ($plan->max_users ?? ($plan->user_limit ?? 'N/A')) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Terminals</span><span class="text-white">{{ ($plan->max_terminals ?? 0) == -1 ? 'Unlimited' : ($plan->max_terminals ?? 'N/A') }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Products</span><span class="text-white">{{ ($plan->max_products ?? 0) == -1 ? 'Unlimited' : ($plan->max_products ?? 'N/A') }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Inventory</span><span class="{{ $plan->inventory_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->inventory_enabled ? 'Yes' : 'No' }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Reports</span><span class="{{ $plan->reports_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->reports_enabled ? 'Yes' : 'No' }}</span></div>
        </div>
        @if($plan->features && is_array($plan->features) && count($plan->features))
        <div class="mt-3 pt-3 border-t border-gray-800">
            {{-- Task 1384: PRA POS cards are GENERATED from the plan's own gate
                 columns (PosPlanComparisonService), so this list is dead copy
                 there — say so instead of letting an admin edit a no-op. --}}
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase mb-1.5">
                Landing Page Features
                @if($plan->product_type === 'pos')
                    <span class="text-amber-400 normal-case">— not shown on PRA POS cards</span>
                @endif
            </p>
            <div class="space-y-1">
                @foreach($plan->features as $feature)
                <div class="flex items-center gap-1.5 text-xs text-gray-300">
                    <svg class="w-3 h-3 text-{{ $color }}-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ $feature }}
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <form x-show="editing" method="POST" action="{{ route('saas.admin.plans.update', $plan->id) }}" class="space-y-3">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Name</label>
                <input type="text" name="name" value="{{ $plan->name }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Product Type</label>
                <select name="product_type" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    <option value="di" {{ $plan->product_type === 'di' ? 'selected' : '' }}>Digital Invoice</option>
                    <option value="pos" {{ $plan->product_type === 'pos' ? 'selected' : '' }}>PRA POS</option>
                    <option value="fbrpos" {{ $plan->product_type === 'fbrpos' ? 'selected' : '' }}>FBR POS</option>
                </select>
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Price (PKR{{ in_array($plan->product_type, ['pos', 'fbrpos', 'standalone']) ? '/yr' : '/mo' }})</label>
                <input type="number" name="price" value="{{ intval($plan->price) }}" step="1" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Quarterly Price (PKR / 3 mo — POS only; blank = annual-only)</label>
                <input type="number" name="price_quarterly" value="{{ $plan->price_quarterly !== null ? intval($plan->price_quarterly) : '' }}" step="1" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            @if(in_array($plan->product_type, ['pos', 'fbrpos']))
            {{-- Aug 2026: both POS lines sell a monthly cycle at a hand-set rate
                 (priced ABOVE the annual pro-rata). Blank = monthly is not sold
                 and checkout quietly falls back to the annual rate. DI ignores
                 this field — its monthly rate IS the price above. --}}
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Monthly Price (PKR / mo — POS only; blank = no monthly)</label>
                <input type="number" name="price_monthly" value="{{ $plan->price_monthly !== null ? intval($plan->price_monthly) : '' }}" step="1" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            @endif
            @if($plan->product_type === 'di')
            {{-- Sep 2026: DI packages quote hand-set rates for every cycle. The
                 global cycle-discount ladder is shared with FBR POS, so it must
                 NOT be touched to reprice a DI package — set the rate here. --}}
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Half-Year Price (PKR / 6 mo — blank = use ladder)</label>
                <input type="number" name="price_semi_annual" value="{{ $plan->price_semi_annual !== null ? intval($plan->price_semi_annual) : '' }}" step="1" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Annual Price (PKR / 12 mo — blank = use ladder)</label>
                <input type="number" name="price_yearly" value="{{ $plan->price_yearly !== null ? intval($plan->price_yearly) : '' }}" step="1" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            @endif
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Invoice Limit</label>
                <input type="number" name="invoice_limit" value="{{ $plan->invoice_limit }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            @if($plan->product_type === 'di')
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">AI Pages / month (-1 = unlimited)</label>
                <input type="number" name="ai_page_limit" value="{{ (int) ($plan->ai_page_limit ?? 0) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Fair-Use Cap (unlimited plans only — blank = none)</label>
                <input type="number" name="fair_use_limit" value="{{ $plan->fair_use_limit !== null ? intval($plan->fair_use_limit) : '' }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            @endif
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Max Terminals</label>
                <input type="number" name="max_terminals" value="{{ $plan->max_terminals }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Max Users</label>
                <input type="number" name="max_users" value="{{ $plan->max_users }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Max Products</label>
                <input type="number" name="max_products" value="{{ $plan->max_products }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="flex flex-wrap items-center gap-4 pt-4">
                <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="inventory_enabled" value="1" {{ $plan->inventory_enabled ? 'checked' : '' }} class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Inventory</label>
                <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="reports_enabled" value="1" {{ $plan->reports_enabled ? 'checked' : '' }} class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Reports</label>
                {{-- Unticking this retires the package: existing subscriptions keep
                     working, but it disappears from the landing, the billing page
                     and signup. --}}
                <label class="flex items-center gap-1.5 text-sm text-gray-400" title="Show this package on the landing page, billing page and signup"><input type="checkbox" name="is_public" value="1" {{ $plan->is_public ? 'checked' : '' }} class="rounded bg-gray-800 border-gray-600 text-indigo-500"> On sale</label>
            </div>
        </div>
        <div>
            <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase mb-1 block">
                Features (one per line — shown on landing page)
                @if($plan->product_type === 'pos')
                    <span class="text-amber-400 normal-case block mt-0.5">PRA POS ignores this: those cards are built from the plan's own limits &amp; feature switches, so a card can never promise more than the package gives.</span>
                @endif
            </label>
            <textarea name="features_text" rows="4" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">{{ $plan->features && is_array($plan->features) ? implode("\n", $plan->features) : '' }}</textarea>
        </div>
        {{-- Task 1455: the save is refused when it would break the package
             ladder (a costlier package losing a tick, a tightened cap, a
             reprice that reorders it). This is the deliberate way past it. --}}
        <label class="flex items-start gap-1.5 text-[11px] text-amber-300/90">
            <input type="checkbox" name="ladder_override" value="1" class="mt-0.5 rounded bg-gray-800 border-gray-600 text-amber-500">
            <span>Save anyway (breaks the ladder) — only tick this if the warning is expected.</span>
        </label>
        <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Save Changes</button>
    </form>
</div>
