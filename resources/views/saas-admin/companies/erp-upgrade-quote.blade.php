<x-admin-layout>
    <div class="p-4 sm:p-6 max-w-3xl mx-auto space-y-4">
        <a href="{{ route('saas.admin.companies.show', $company) }}" class="text-sm text-indigo-300 underline">Back to company</a>
        <h1 class="text-2xl font-bold">Hospital ERP upgrade estimate</h1>
        <p class="text-sm">{{ $company->name }} · {{ $company->account_code ?: $company->id }}</p>
        <p class="text-sm">Estimate the difference between the destination package and the unused part of the source account’s verified package payment. This estimate does not collect payment or change either account.</p>

        @if ($plans->isEmpty())
            <p>No paid Healthcare package with inpatient care is available for this estimate.</p>
        @else
            <form class="space-y-4 border rounded-lg p-4" method="GET" action="{{ route('saas.admin.companies.erpUpgradeQuote', $company) }}">
                <label class="block text-sm">Hospital ERP package
                    <select name="pricing_plan_id" class="block w-full bg-gray-950 border border-gray-700 rounded p-2 text-white" required>
                        <option value="">Choose a package</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected($plan->id === $planId)>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">Billing period
                    <select name="billing_cycle" class="block w-full bg-gray-950 border border-gray-700 rounded p-2 text-white" required>
                        @foreach ($cycles as $availableCycle)
                            <option value="{{ $availableCycle }}" @selected($cycle === $availableCycle)>{{ \App\Models\Subscription::getCycleLabel($availableCycle) }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="px-4 py-2 rounded bg-indigo-600 text-white">Preview estimate</button>
            </form>
        @endif

        @if ($quoteError)
            <p class="border rounded-lg p-4" role="alert">{{ $quoteError }}</p>
        @endif
        @if ($quote)
            <section class="border rounded-lg p-4 space-y-3" aria-labelledby="erp-upgrade-estimate">
                <h2 id="erp-upgrade-estimate" class="text-lg font-bold">{{ $quote['plan_name'] }} · {{ \App\Models\Subscription::getCycleLabel($quote['cycle']) }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt>Destination package price</dt><dd>PKR {{ number_format($quote['target_price_paisa'] / 100, 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Unused paid term value</dt><dd>PKR {{ number_format($quote['unused_source_value_paisa'] / 100, 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Credit applied in estimate</dt><dd>PKR {{ number_format($quote['credit_paisa'] / 100, 2) }}</dd></div>
                    <div class="flex justify-between gap-4 font-bold"><dt>Estimated difference</dt><dd>PKR {{ number_format($quote['difference_paisa'] / 100, 2) }}</dd></div>
                </dl>
                <p class="text-sm">{{ $quote['remaining_days'] }} of {{ $quote['total_days'] }} paid days remain as of {{ $quote['as_of'] }}. The source term ends on {{ $quote['source_term_ends'] }}.</p>
            </section>
        @endif
    </div>
</x-admin-layout>
