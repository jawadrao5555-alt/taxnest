<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Company Usage Monitoring</h1>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">Bill totals cover PRA POS and standalone FBR POS shops. Terminal and stock figures cover PRA POS. A dash means unavailable or not applicable. Staff activity comes from recorded logins and session heartbeats. Opening this page does not count as company activity.</p>

    <h2 id="local-collections" class="mb-2 text-lg font-semibold">School / Hospital local collections</h2>
    <form method="get" class="mb-4 grid sm:grid-cols-3 gap-2">
        <label>Company ID<input name="company_id" type="text" maxlength="32" value="{{ request('company_id') }}"></label>
        <label>Product or collection category
            <select name="product_scope">
                <option value="">All products</option>
                @foreach($productScopes as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['product_scope'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>Branch or campus ID (0 = unassigned)<input name="branch_id" type="number" min="0" value="{{ request('branch_id') }}"></label>
        <label>Reporting period
            <select name="period">
                @foreach(['custom' => 'Custom dates', 'daily' => 'Today', 'monthly' => 'Month to date', 'yearly' => 'Year to date', 'two_year' => 'Compare this year and last year'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['period'] ?? 'custom') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>Custom start date<input name="date_from" type="date" value="{{ request('date_from') }}"></label>
        <label>Custom end date<input name="date_to" type="date" value="{{ request('date_to') }}"></label>
        <button>Apply filters</button>
    </form>
    <p>POS bills use their stored business date. DI documents use their invoice date. ERP package invoices use their issue-record date, and receipts use the payment date. Healthcare uses the bill date and receipt business date. Package billing applies to the whole company, so those totals are unavailable when a branch is selected.</p>
    @unless($erpFinancials->isEmpty())
        <p>TaxNest package collections are shown before any separately recorded adjustments. Healthcare totals cover the patient billing ledger. Standalone pharmacy checkout has its own ledger.</p>
    @endunless
    @if(isset($filters['date_from']) || isset($filters['date_to']))
        <p>Reporting dates: {{ $filters['date_from'] ?? 'Earliest' }} to {{ $filters['date_to'] ?? 'Latest' }}.
            @if(isset($filters['comparison']))
                Same period last year: {{ $filters['comparison']['date_from'] }} to {{ $filters['comparison']['date_to'] }}.
            @endif
        </p>
    @endif
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3 text-right">Completed POS Bills</th>
                        <th class="px-4 py-3 text-right">POS Bill Total (PKR)</th>
                        <th class="px-4 py-3 text-right">Terminals</th>
                        <th class="px-4 py-3 text-right">Users</th>
                        <th class="px-4 py-3 text-right">Inventory Items</th>
                        <th class="px-4 py-3">Last Staff Activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($usageStats as $stat)
                    @php
                        $bills = $stat->fbr_sales['bills'] ?? $stat->total_pos_transactions;
                        $amount = $stat->fbr_sales['amount'] ?? $stat->total_sales_amount;
                        $prior = $stat->fbr_comparison ?? $stat->pra_comparison;
                        $isFbr = $stat->fbr_sales !== null;
                    @endphp
                    <tr class="hover:bg-gray-800/50 {{ $loop->even ? 'bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 text-white font-medium">
                            <a href="{{ route('saas.admin.companies.show', $stat->company_id) }}" class="hover:text-indigo-400 transition">{{ $stat->company->name ?? 'Unknown' }}</a>
                            <span class="block font-mono text-xs">{{ \App\Services\CompanyProductId::display($stat->company) }}</span>
                            @if(auth('admin')->user()?->canManageSensitiveConfiguration())<a class="block text-xs text-indigo-400" href="{{ route('admin.company-ai-usage',$stat->company_id) }}">AI usage / budget</a>@endif
                            <span class="block text-xs text-gray-500">{{ \App\Support\ProductCatalog::label($stat->company->product_type) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300 font-medium">
                            {{ $bills === null ? '—' : number_format($bills) }}
                            @if($prior !== null)
                                <div class="text-xs text-gray-400">Last year: {{ number_format($prior['bills']) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-emerald-400 font-medium">
                            {{ $amount === null ? '—' : number_format($amount, 2) }}
                            @if($prior !== null)
                                <div class="text-xs text-gray-400">Last year: {{ number_format($prior['amount'], 2) }}</div>
                            @endif
                            @if($bills !== null)
                                <a class="block text-xs underline" href="{{ request()->fullUrlWithQuery([
                                    'export' => $isFbr ? 'fbr_csv' : 'pra_csv',
                                    'company_id' => $stat->company_id,
                                    'as_of' => $filters['date_to'] ?? null,
                                    'page' => 1,
                                ]) }}">Download {{ $isFbr ? 'FBR POS' : 'PRA' }} bill totals (CSV)</a>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $stat->active_terminals ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $stat->active_users ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $stat->inventory_items ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs">{{ $stat->last_activity_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                    @if($stat->di_documents !== null)
                        <tr><td colspan="7" class="px-4 py-3">
                            <p>DI documents with a stored production FBR reference: {{ number_format($stat->di_documents['bills']) }}</p>
                            <p>Net document value after credit notes: PKR {{ number_format($stat->di_documents['amount'], 2) }}</p>
                            @if($stat->di_comparison !== null)
                                <p>Same period last year: {{ number_format($stat->di_comparison['bills']) }} documents, PKR {{ number_format($stat->di_comparison['amount'], 2) }}</p>
                            @endif
                            <a class="underline" href="{{ request()->fullUrlWithQuery([
                                'export' => 'di_csv',
                                'company_id' => $stat->company_id,
                                'as_of' => $filters['date_to'] ?? null,
                                'page' => 1,
                            ]) }}">Download DI production document totals (CSV)</a>
                        </td></tr>
                    @endif
                    @if($erpFinancials->has($stat->company_id))
                        <tr><td colspan="7" class="px-4 py-3">
                            @include('saas-admin.partials.erp-financial-summary', ['summary' => $erpFinancials->get($stat->company_id)])
                            @if($erpComparison->has($stat->company_id))
                                <h3 class="mt-3 font-semibold">Same period last year</h3>
                                @include('saas-admin.partials.erp-financial-summary', ['summary' => $erpComparison->get($stat->company_id)])
                            @endif
                            <a class="mt-3 inline-block text-sm underline" href="{{ request()->fullUrlWithQuery([
                                'export' => 'erp_csv',
                                'company_id' => $stat->company_id,
                                'as_of' => $filters['date_to'] ?? null,
                                'page' => 1,
                            ]) }}">Download ERP billing and collections (CSV)</a>
                        </td></tr>
                    @endif
                    @if($stat->local_collections)
                        <tr><td colspan="7" class="px-4 py-3">
                            <a href="{{ request()->fullUrlWithQuery([
                                'export' => 'local_csv',
                                'company_id' => $stat->company_id,
                                'date_from' => $stat->local_collections['period']['from'],
                                'date_to' => $stat->local_collections['period']['to'],
                                'as_of' => $stat->local_collections['period']['to'],
                                'page' => 1,
                            ]) }}">Download this company's local collection totals (CSV)</a>
                            @include('saas-admin.partials.local-collections',['local'=>$stat->local_collections])
                            @if($stat->local_collections_comparison)
                                <h3 class="mt-3 font-semibold">Same period last year</h3>
                                @include('saas-admin.partials.local-collections',['local'=>$stat->local_collections_comparison])
                            @endif
                        </td></tr>
                    @endif
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">No usage data yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $companies->links() }}</div>
</div>
</x-admin-layout>
