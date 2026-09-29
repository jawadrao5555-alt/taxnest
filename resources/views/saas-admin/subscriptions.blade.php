<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Subscriptions</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-white">Assign Subscription</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'Assign New'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.subscriptions.assign') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            @csrf
            <select name="company_id" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="">Select Company</option>
                @foreach($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
            <select name="pricing_plan_id" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="">Select Plan</option>
                @foreach($plans as $p)<option value="{{ $p->id }}">{{ $p->name }} (PKR {{ number_format($p->price) }})</option>@endforeach
            </select>
            <select name="billing_cycle" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="annual">Annual</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition whitespace-nowrap">Assign</button>
        </form>
    </div>

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
                        $activeCount = $allSubs->where('active', true)->count();
                    @endphp
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-white font-medium">{{ $company->name }}@if($company->trashed()) <span class="text-xs text-gray-400">(archived)</span>@endif</td>
                        <td class="px-4 py-3 text-gray-300">{{ $sub->pricingPlan->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 hidden sm:table-cell">{{ ucfirst($sub->billing_cycle ?? 'monthly') }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ optional($sub->start_date)->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ optional($sub->end_date)->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $sub->active ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $sub->active ? 'Active' : 'Inactive' }}</span>
                            @if($activeCount > 1)<span class="block text-xs text-amber-400 mt-1">Multiple active records — review</span>@endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if(!$company->trashed())
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
                                    <tr class="border-t border-gray-800">
                                        <td class="py-1">{{ $past->pricingPlan->name ?? '—' }}</td>
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
</x-admin-layout>
