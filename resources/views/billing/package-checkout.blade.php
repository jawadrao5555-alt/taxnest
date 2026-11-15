<x-dynamic-component :component="$layout">
    <main class="max-w-5xl mx-auto p-4 space-y-6" id="package-checkout">
        <h1 class="text-2xl font-bold">Package selection and payment review</h1>
        <p>Select an available package and billing period, then upload evidence of a payment already made. This page does not charge a payment method or activate a subscription. Selection alone does not change access. Receipt uploads remain subject to the existing review and temporary-access policy.</p>
        @if($errors->any())
        <div role="alert" class="border border-red-400 p-3">{{ $errors->first() }}</div>
        @endif
        <section class="grid grid-cols-1 sm:grid-cols-2 gap-4" aria-label="Available packages">
        @foreach($plans as $plan)
            <form method="POST" action="{{ route($checkoutRoute.'.select') }}" class="rounded-xl border p-4 space-y-3" data-checkout-plan="{{ $plan->id }}">
                @csrf
                <h2 class="font-bold">{{ $plan->name }}</h2>
                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                <label for="checkout-cycle-{{ $plan->id }}">Billing period and payable total</label>
                <select id="checkout-cycle-{{ $plan->id }}" name="billing_cycle" class="block w-full rounded-lg dark:bg-gray-800">
                    @foreach(\App\Services\SubscriptionAssignmentService::cyclesForPlan($plan) as $cycle)
                        @php($price = \App\Services\DistributorDiscountService::quote($company, $plan, $cycle))
                        <option value="{{ $cycle }}" @selected(($quote['cycle'] ?? request('billing_cycle', 'annual')) === $cycle)>{{ \App\Models\Subscription::getCycleLabel($cycle) }} · PKR {{ number_format($price['net_quote'], 2) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg bg-blue-700 text-white p-2">Select period and review quote</button>
            </form>
        @endforeach
        </section>
        @if($selectedPlan && $quote)
        <section id="selected-package-quote" class="rounded-xl border p-4 space-y-3">
            <h2 class="font-bold">Selected package: {{ $selectedPlan->name }}</h2>
            <p>{{ \App\Models\Subscription::getCycleLabel($quote['cycle']) }} · Payable total: PKR {{ number_format($quote['net_quote'], 2) }}</p>
            <p>This saved quote is used for the receipt review. Submitting evidence does not confirm payment; the existing review and temporary-access policy still applies.</p>
            <form method="POST" action="{{ route($proofRoute) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <input type="hidden" name="pricing_plan_id" value="{{ $selectedPlan->id }}">
                <input type="hidden" name="billing_cycle" value="{{ $quote['cycle'] }}">
                <label class="block">Amount you paid (PKR)<input name="amount" type="number" min="0.01" step="0.01" value="{{ $quote['net_quote'] }}" required class="block rounded-lg dark:bg-gray-800"></label>
                <label class="block">Payment method<select name="payment_method" class="block rounded-lg dark:bg-gray-800"><option value="bank">Bank transfer</option><option value="jazzcash">JazzCash</option><option value="easypaisa">Easypaisa</option><option value="other">Other</option></select></label>
                <label class="block">Transaction reference<input name="reference" maxlength="120" class="block rounded-lg dark:bg-gray-800"></label>
                <label class="block">Payment receipt (JPG, PNG or PDF; up to 5 MB)<input name="proof" type="file" accept=".jpg,.jpeg,.png,.pdf" required class="block max-w-full"></label>
                <button type="submit" class="rounded-lg bg-blue-700 text-white p-2">Submit receipt for review</button>
            </form>
        </section>
        @endif
    </main>
</x-dynamic-component>
