<x-admin-layout>
@php
    $canManageBilling = auth('admin')->user() instanceof \App\Models\AdminUser && auth('admin')->user()->canManageBilling();
@endphp
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Subscriptions</h1>
    <form method="GET" action="{{ route('saas.admin.subscriptions') }}" class="mb-5 flex flex-wrap items-end gap-3">
        <label class="text-sm text-gray-300">Product
            <select name="product" class="block mt-1 rounded-lg bg-gray-800 border-gray-700 text-sm">
                <option value="">All products</option>
                @foreach(\App\Support\ProductCatalog::TYPES as $type)
                    <option value="{{ $type }}" @selected($product === $type)>{{ \App\Support\ProductCatalog::label($type) }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-gray-300">Status
            <select name="status" class="block mt-1 rounded-lg bg-gray-800 border-gray-700 text-sm">
                <option value="">All statuses</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>
        </label>
        <button type="submit" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-white">Apply filters</button>
    </form>

    @if($canManageBilling)
    @if($documents = session('package_sale_documents'))
    <div class="mb-5 flex flex-wrap items-center gap-4 rounded-lg bg-gray-900 p-4 text-sm text-white">
        <strong>Receipt {{ $documents['receipt_number'] }}</strong>
        <a class="text-indigo-300 hover:underline" href="{{ route('saas.admin.billing.documents.receipt', [$documents['company'], $documents['payment']]) }}">Download receipt PDF</a>
        <a class="text-indigo-300 hover:underline" target="_blank" rel="noopener" href="{{ route('saas.admin.billing.documents.receipt', ['company' => $documents['company'], 'payment' => $documents['payment'], 'print' => 1]) }}">Print receipt</a>
        <a class="text-indigo-300 hover:underline" href="{{ route('saas.admin.billing.documents.invoice', [$documents['company'], $documents['invoice']]) }}">Download invoice PDF</a>
    </div>
    @endif
    @php
        $initialAssignment = [
            'companyId' => (string) old('company_id', ''),
            'planId' => (string) old('pricing_plan_id', ''),
            'cycle' => old('billing_cycle', 'annual'),
            'mode' => old('assignment_mode', 'received'),
            'showForm' => $errors->any(),
        ];
    @endphp
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6"
         x-data="packageAssignment(@js($initialAssignment), @js($assignmentPlans), @js(route('saas.admin.subscriptions.quote')))">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-white">Assign Subscription</h3>
            <button type="button" @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'Assign New'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.subscriptions.assign') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            @csrf
            <label class="block text-sm text-gray-300">Company
                <select name="company_id" required x-model="companyId" @change="planId = ''; resetChoice()" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                    <option value="">Select Company</option>
                    @foreach($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm text-gray-300">Package
                <select name="pricing_plan_id" required x-model="planId" @change="resetChoice()" :disabled="!companyId" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                    <option value="" x-text="companyId ? 'Select a compatible package' : 'Select a company first'">Select a company first</option>
                    <template x-for="plan in availablePlans" :key="plan.id">
                        <option :value="String(plan.id)" x-text="plan.label" :selected="String(plan.id) === planId"></option>
                    </template>
                </select>
            </label>
            <label class="block text-sm text-gray-300">Billing period
                <select name="billing_cycle" required x-model="cycle" @change="refreshQuote()" :disabled="!planId" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                    <template x-for="option in availableCycles" :key="option">
                        <option :value="option" x-text="cycleLabels[option]" :selected="option === cycle"></option>
                    </template>
                </select>
            </label>
            <label class="block text-sm text-gray-300">Assignment
                <select name="assignment_mode" required x-model="mode" :disabled="!planId" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                    <option value="received" :disabled="freePlan">Record received payment</option>
                    <option value="unpaid">Assign without payment receipt</option>
                </select>
            </label>
            <p x-show="quote" class="col-span-full text-sm text-white" aria-live="polite" x-text="quote ? 'Package total: PKR ' + Number(quote.amount).toLocaleString('en-PK', {minimumFractionDigits: 2}) : ''"></p>
            <p x-show="quoteError" x-text="quoteError" class="col-span-full text-sm text-amber-300" role="alert"></p>
            <fieldset x-show="mode === 'received' && planId" :disabled="mode !== 'received' || !planId" class="col-span-full grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <legend class="mb-2 text-sm font-semibold text-white">Received payment</legend>
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <input type="hidden" name="expected_subscription_id" :value="quote?.expected_subscription_id ?? ''">
                <label class="block text-sm text-gray-300">Payment method
                    <select required name="payment_method" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Choose Cash or Card</option>
                        <option value="cash" @selected(old('payment_method') === 'cash')>Cash</option>
                        <option value="card" @selected(old('payment_method') === 'card')>Card</option>
                    </select>
                </label>
                <label class="block text-sm text-gray-300">Amount received (PKR)
                    <input required type="number" name="verified_received_amount" min="0.01" step="0.01" value="{{ old('verified_received_amount') }}" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                </label>
                <label class="block text-sm text-gray-300">Payment date
                    <input required type="date" name="payment_date" max="{{ now('Asia/Karachi')->toDateString() }}" value="{{ old('payment_date', now('Asia/Karachi')->toDateString()) }}" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                </label>
                <label class="block text-sm text-gray-300">Reference (optional)
                    <input type="text" name="reference" maxlength="100" value="{{ old('reference') }}" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                </label>
            </fieldset>
            <div class="col-span-full">
                <button type="submit" :disabled="!planId || (mode === 'received' && !quote)" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg transition whitespace-nowrap" x-text="mode === 'received' ? 'Confirm payment and assign package' : 'Assign package'">Assign</button>
            </div>
        </form>
    </div>

    @else
    <p class="mb-4 text-sm text-gray-400">Billing is read-only for this administrator.</p>
    @endif
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <p class="px-4 py-3 text-xs text-gray-400 border-b border-gray-800">One row per company. Previous trials, package changes and renewals are available under History.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3">Plan</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Cycle</th>
                        <th class="px-4 py-3 hidden md:table-cell">Start</th>
                        <th class="px-4 py-3 hidden md:table-cell">End</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Action</th>
                        <th class="px-4 py-3 text-center">History</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800" x-data="{ history: {} }">
                    @forelse($companiesPage as $company)
                    @php
                        $allSubs = $companySubscriptions->get($company->id, collect());
                        $sub = $allSubs->first();
                        $displayPlan = $sub?->entitlementPlan();
                        $activeCount = $allSubs->where('active', true)->count();
                    @endphp
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-white font-medium">
                            {{ $company->name }}@if($company->trashed()) <span class="text-xs text-gray-400">(archived)</span>@endif
                            <span class="block text-xs text-gray-400">{{ \App\Services\CompanyProductId::display($company) }} · {{ \App\Support\ProductCatalog::label($company->product_type, $company->erps_vertical) }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-300">{{ $displayPlan?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 hidden sm:table-cell">{{ ucfirst($sub->billing_cycle ?? 'monthly') }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ optional($sub->start_date)->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ optional($sub->end_date)->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $sub->active ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $sub->active ? 'Active' : 'Inactive' }}</span>
                            @if($activeCount > 1)<span class="block text-xs text-amber-400 mt-1">Multiple active records — review</span>@endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if(!$company->trashed() && $canManageBilling)
                                @if($activeCount > 1)
                                <span class="text-xs text-amber-400">Review conflict</span>
                                @elseif($sub->active || ($activeCount === 0 && !$sub->isExpired() && !$sub->isTrialExpired() && !\App\Services\PlanSellabilityService::isRetired($sub->pricingPlan)))
                                <form method="POST" action="{{ route('saas.admin.subscriptions.toggle', $sub->id) }}" class="inline">@csrf
                                    <button class="text-xs {{ $sub->active ? 'text-red-400 hover:text-red-300' : 'text-emerald-400 hover:text-emerald-300' }}">{{ $sub->active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                                @else
                                <span class="text-xs text-gray-500">Assign package</span>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($canManageBilling)
                            <a href="{{ route('saas.admin.companies.package-receipts', ['companyId' => $company->id]) }}" class="block text-xs text-indigo-400 hover:underline">Package receipts</a>
                            @endif
                            @if($allSubs->count() > 1)
                            <button type="button" class="text-xs text-indigo-400 hover:underline"
                                    @click="history[{{ $company->id }}] = !history[{{ $company->id }}]"
                                    :aria-expanded="!!history[{{ $company->id }}]">
                                <span x-show="!history[{{ $company->id }}]">History ({{ $allSubs->count() - 1 }})</span>
                                <span x-show="history[{{ $company->id }}]" x-cloak>Hide history</span>
                            </button>
                            @else<span class="text-gray-500">—</span>@endif
                        </td>
                    </tr>
                    @if($allSubs->count() > 1)
                    <tr x-show="history[{{ $company->id }}]" x-cloak class="bg-gray-950/50">
                        <td colspan="8" class="px-4 py-3">
                            <div class="text-xs font-semibold text-gray-400 mb-2">Previous subscription records for {{ $company->name }}</div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs text-gray-400">
                                    <thead><tr class="text-left"><th class="py-1">Plan</th><th>Cycle</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
                                    <tbody>
                                    @foreach($allSubs->skip(1) as $past)
                                    @php($pastDisplayPlan = $past->entitlementPlan())
                                    <tr class="border-t border-gray-800">
                                        <td class="py-1">{{ $pastDisplayPlan?->name ?? '—' }}</td>
                                        <td>{{ ucfirst($past->billing_cycle ?? 'monthly') }}</td>
                                        <td>{{ optional($past->start_date)->format('d M Y') ?? '—' }}</td>
                                        <td>{{ optional($past->end_date)->format('d M Y') ?? '—' }}</td>
                                        <td>{{ $past->active ? 'Active — review' : 'Inactive' }}</td>
                                    </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    @endif
                    @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">No subscriptions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($companiesPage->hasPages())<div class="mt-4">{{ $companiesPage->links() }}</div>@endif
</div>
<script>
    function packageAssignment(initial, plans, quoteUrl) {
        return {
            ...initial, plans, quote: null, quoteError: '', requestId: 0,
            cycleLabels: {annual: 'Annual', monthly: 'Monthly', quarterly: 'Quarterly', semi_annual: 'Semi-Annual'},
            get availablePlans() {
                return this.plans.filter(plan => plan.company_ids.includes(Number(this.companyId)));
            },
            get availableCycles() {
                return this.availablePlans.find(plan => plan.id === Number(this.planId))?.cycles ?? ['annual'];
            },
            get freePlan() {
                return this.quote && (this.quote.is_trial || Number(this.quote.amount) === 0);
            },
            init() {
                this.refreshQuote();
            },
            resetChoice() {
                this.mode = 'received';
                if (!this.availableCycles.includes(this.cycle)) this.cycle = 'annual';
                this.refreshQuote();
            },
            async refreshQuote() {
                const requestId = ++this.requestId;
                this.quote = null;
                this.quoteError = '';
                if (!this.companyId || !this.planId) return;
                try {
                    const query = new URLSearchParams({company_id: this.companyId, pricing_plan_id: this.planId, billing_cycle: this.cycle});
                    const response = await fetch(quoteUrl + '?' + query, {headers: {Accept: 'application/json'}});
                    if (!response.ok) throw new Error();
                    const quote = await response.json();
                    if (requestId !== this.requestId) return;
                    this.quote = quote;
                    if (this.freePlan) this.mode = 'unpaid';
                } catch {
                    if (requestId === this.requestId) this.quoteError = 'Unable to load the package total. Reselect the package to retry.';
                }
            },
        };
    }
</script>
</x-admin-layout>
