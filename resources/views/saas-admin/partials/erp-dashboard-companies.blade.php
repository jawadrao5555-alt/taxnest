<div data-company-panel="{{ $erpType }}" hidden class="tn-company-panel bg-gray-900 border border-gray-800 border-t-0 rounded-b-xl overflow-hidden">
    <div class="overflow-x-auto" role="region" tabindex="0" aria-label="ERP company results">
        <table class="w-full text-sm table-cards">
            <thead>
                <tr class="text-left text-[10px] text-gray-400 uppercase border-b border-gray-800 bg-gray-800/30">
                    <th scope="col" class="px-4 py-3">Company</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">TaxNest subscriptions</th>
                    <th scope="col" class="px-4 py-3">Company billing</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/50">
                @forelse($erpCompaniesList as $company)
                    @php
                        $summary = $erpSummaries->get($company->id) ?? [];
                        $isHealthcare = \App\Support\HealthPanel::ownsErpRecord($company);
                    @endphp
                    <tr class="hover:bg-gray-800/30">
                        <td class="px-4 py-3 align-top" data-label="Company">
                            <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="font-semibold">{{ $company->name }}</a>
                            @if($isHealthcare)
                                <p class="text-xs">{{ __(\App\Support\HealthPanel::orgTypeLabelKey($company->health_org_type)) }}</p>
                            @endif
                            <p class="text-xs">Company ID: {{ \App\Services\CompanyProductId::display($company) }} · <a class="underline" href="{{ route('saas.admin.usage', ['product_scope' => $erpType, 'company_id' => $company->id] + $erpFilters) }}">Reports</a></p>
                        </td>
                        <td class="px-4 py-3 align-top" data-label="Status">{{ ucfirst($company->status) }}</td>
                        <td class="px-4 py-3 align-top" data-label="TaxNest subscriptions">
                            @include('saas-admin.partials.erp-financial-amounts', ['lane' => $summary['subscription'] ?? null, 'isSubscription' => true])
                        </td>
                        <td class="px-4 py-3 align-top" data-label="Company billing">
                            @if($isHealthcare)
                                @include('saas-admin.partials.erp-financial-amounts', ['lane' => $summary['operations'] ?? null, 'isSubscription' => false])
                            @else
                                @include('saas-admin.partials.erp-financial-amounts', ['lane' => $summary['school_fees'] ?? null, 'isSubscription' => false, 'reversalLabel' => 'Receipt reversals'])
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No ERP companies found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap gap-4 px-4 py-3 border-t border-gray-800">
        <a href="{{ route('saas.admin.subscriptions', ['product' => $erpType]) }}" class="text-sm underline">ERP subscriptions</a>
        @if($stats['erp_companies'] > $erpCompaniesList->count())
            <a href="{{ route('saas.admin.companies', ['product_type' => $erpType]) }}" class="text-sm underline">View all ERP companies</a>
        @endif
    </div>
</div>
