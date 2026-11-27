<x-admin-layout>
    <div class="p-4 sm:p-6 max-w-3xl mx-auto space-y-4">
        <a href="{{ route('saas.admin.companies.show', $company) }}" class="text-sm text-indigo-300 underline">Back to company</a>
        <h1 class="text-2xl font-bold">Hospital ERP upgrade</h1>
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
                <form class="space-y-3 pt-3" method="POST" action="{{ route('saas.admin.companies.erpUpgradeFreeze', $company) }}">
                    @csrf
                    <input type="hidden" name="pricing_plan_id" value="{{ $planId }}">
                    <input type="hidden" name="billing_cycle" value="{{ $cycle }}">
                    <input type="hidden" name="expected_package_paisa" value="{{ $quote['target_price_paisa'] }}">
                    <input type="hidden" name="expected_credit_paisa" value="{{ $quote['credit_paisa'] }}">
                    <label class="block text-sm">Destination Hospital ERP company ID
                        <input type="number" name="destination_company_id" min="1" value="{{ old('destination_company_id') }}" class="block w-full rounded border-gray-500 bg-transparent" required>
                    </label>
                    <label class="block text-sm">Company owner’s approval reference
                        <input type="text" name="owner_authorization_reference" maxlength="150" value="{{ old('owner_authorization_reference') }}" class="block w-full rounded border-gray-500 bg-transparent" required>
                    </label>
                    <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Freeze this quote</button>
                </form>
            </section>
        @endif

        @if ($settlement)
            <section class="space-y-4 rounded-lg border p-4" aria-labelledby="erp-upgrade-frozen">
                <h2 class="text-lg font-bold" id="erp-upgrade-frozen">Quote #{{ $settlement->id }}</h2>
                <p class="text-sm">{{ $settlement->quote_snapshot['plan_name'] }} · {{ \App\Models\Subscription::getCycleLabel($settlement->billing_cycle) }} · Destination company #{{ $settlement->destination_company_id }}</p>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt>Package price</dt><dd>PKR {{ number_format($settlement->target_price_paisa / 100, 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Applied credit</dt><dd>PKR {{ number_format($settlement->credit_paisa / 100, 2) }}</dd></div>
                    <div class="flex justify-between gap-4 font-bold"><dt>Difference payable</dt><dd>PKR {{ number_format($settlement->difference_paisa / 100, 2) }}</dd></div>
                </dl>
                @if ($settlement->activated_at)
                    <p>Activated on {{ $settlement->activated_at->toDateString() }}.</p>
                @elseif ($settlement->settled_at)
                    <p>Difference verified. Map the source campuses to the destination before activating the ERP package.</p>
                    <form class="space-y-3" method="POST" action="{{ route('saas.admin.companies.erpUpgradeActivate', [$company, $settlement->id]) }}">
                        @csrf
                        @forelse ($branches->get($company->id, collect()) as $campus)
                            <label class="block text-sm">Destination campus for {{ $campus->name }}
                                <select name="branch_map[{{ $campus->id }}]" class="block w-full rounded border-gray-500 bg-white dark:bg-gray-900" required>
                                    <option value="">Choose a destination campus</option>
                                    @foreach ($branches->get($settlement->destination_company_id, collect()) as $destination)
                                        <option value="{{ $destination->id }}" @selected((int) old('branch_map.'.$campus->id) === $destination->id)>{{ $destination->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @empty
                            <p class="text-sm">The source company has no active campuses to map.</p>
                        @endforelse
                        <label class="block text-sm">Cutover approval reference
                            <input type="text" name="activation_reference" maxlength="150" value="{{ old('activation_reference') }}" class="block w-full rounded border-gray-500 bg-transparent" required>
                        </label>
                        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Activate Hospital ERP package</button>
                    </form>
                @else
                    <p class="text-sm">Valid on {{ $settlement->quoted_on->toDateString() }}. Verify only the difference shown above.</p>
                    <form class="space-y-3" method="POST" action="{{ route('saas.admin.companies.erpUpgradeSettle', [$company, $settlement->id]) }}">
                        @csrf
                        <label class="block text-sm">Verified amount received (PKR)
                            <input type="number" name="verified_received_amount" min="0" step="0.01" value="{{ old('verified_received_amount') }}" class="block w-full rounded border-gray-500 bg-transparent" required>
                        </label>
                        @if ($settlement->difference_paisa > 0)
                            <label class="block text-sm">Bank transfer reference
                                <input type="text" name="payment_reference" maxlength="120" value="{{ old('payment_reference') }}" class="block w-full rounded border-gray-500 bg-transparent" required>
                            </label>
                        @else
                            <p class="text-sm">The credit covers the package. Enter PKR 0 to confirm the settlement.</p>
                        @endif
                        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Verify upgrade difference</button>
                    </form>
                @endif
            </section>
        @endif

        @if ($settlements->count() > 1)
            <section aria-labelledby="erp-upgrade-history" class="space-y-2">
                <h2 id="erp-upgrade-history" class="font-bold">Recent quotes</h2>
                @foreach ($settlements as $item)
                    <a class="block underline" href="{{ route('saas.admin.companies.erpUpgradeQuote', ['company' => $company, 'settlement_id' => $item->id]) }}">Quote #{{ $item->id }} · {{ $item->quoted_on->toDateString() }} · PKR {{ number_format($item->difference_paisa / 100, 2) }}</a>
                @endforeach
            </section>
        @endif
    </div>
</x-admin-layout>
