@php
    $cycleQuotes = [];
    foreach (\App\Services\SubscriptionAssignmentService::cyclesForPlan($plan) as $quoteCycle) {
        $cycleQuotes[$quoteCycle] = \App\Services\SubscriptionAssignmentService::computePrice($plan, $quoteCycle, $company ?? null)['final_price'];
    }
@endphp
<div class="my-4" data-configured-cycle-quote="{{ $plan->id }}" x-data="{ cycle: 'annual', quotes: @js($cycleQuotes) }">
    <label class="block text-sm font-medium" for="plan-cycle-{{ $plan->id }}">Billing period</label>
    <select id="plan-cycle-{{ $plan->id }}" x-model="cycle" class="mt-1 rounded-lg dark:bg-gray-800 tn-billing-field">
        @foreach($cycleQuotes as $quoteCycle => $quoteTotal)
            <option value="{{ $quoteCycle }}">{{ \App\Models\Subscription::getCycleLabel($quoteCycle) }} · PKR {{ number_format($quoteTotal, 2) }}</option>
        @endforeach
    </select>
    <p class="mt-2 text-sm font-semibold">Payable total: PKR <span x-text="Number(quotes[cycle]).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})">{{ number_format($cycleQuotes['annual'], 2) }}</span></p>
    <p class="text-xs text-gray-500 tn-billing-muted">Only configured periods are offered. Selecting a period here does not activate or renew a subscription.</p>
    @if(!empty($signupRoute))
    <a class="block mt-3 font-semibold text-blue-700" :href="@js(route($signupRoute, ['plan' => $plan->name], false)) + '&amp;billing_cycle=' + encodeURIComponent(cycle)">Choose {{ $plan->name }} and continue registration</a>
    @elseif(!empty($company))
    @php($checkoutName = $quoteCheckoutRoute ?? ($company->product_type === 'fbrpos' ? 'fbrpos.package-checkout' : ($company->product_type === 'pos' ? 'pos.package-checkout' : 'billing.package-checkout')))
    <a class="block mt-3 font-semibold text-blue-700 tn-billing-link" :href="@js(route($checkoutName, ['plan_id' => $plan->id], false)) + '&amp;billing_cycle=' + encodeURIComponent(cycle)">Review this package and period</a>
    @else
    <p class="text-xs text-gray-500">For a package change or renewal, send the selected package, period and payable total to your account administrator for review. No payment is collected here.</p>
    @endif
</div>
