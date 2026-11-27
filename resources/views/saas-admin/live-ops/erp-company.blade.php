<x-admin-layout>
    <div class="mx-auto max-w-5xl space-y-5 p-4 sm:p-6">
        <a class="text-sm underline" href="{{ route('saas.admin.live-ops', ['product' => \App\Support\ProductCatalog::ERPS]) }}">Back to ERP companies</a>
        <header>
            <h1 class="text-2xl font-bold">{{ $company->name }}</h1>
            <p class="text-sm">{{ \App\Support\NestErps::verticalLabel(\App\Support\NestErps::verticalOf($company)) }} · Read-only ERP billing and activity</p>
            <p class="text-sm">Measured {{ $measuredAt->format('d M Y, H:i T') }}</p>
        </header>

        <form method="GET" action="{{ route('saas.admin.live-ops.erp-company', $company->id) }}" class="grid gap-3 rounded-lg border p-4 sm:grid-cols-3">
            <label>Start date
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="block w-full rounded border-gray-500 bg-transparent">
            </label>
            <label>End date
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="block w-full rounded border-gray-500 bg-transparent">
            </label>
            <label>Branch or campus
                <select name="branch_id" class="block w-full rounded border-gray-500 bg-white dark:bg-gray-900">
                    <option value="">All branches and campuses</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="justify-self-start rounded bg-indigo-600 px-4 py-2 text-white">Filter activity</button>
        </form>

        @include('saas-admin.partials.erp-financial-summary', ['summary' => $summary])

        <div class="flex flex-wrap gap-4 text-sm">
            <a class="underline" href="{{ route('saas.admin.usage', ['company_id' => $company->id, 'product_scope' => \App\Support\ProductCatalog::ERPS] + $filters) }}">Company Usage</a>
            <a class="underline" href="{{ route('saas.admin.companies.show', $company->id) }}">Company details</a>
        </div>
    </div>
</x-admin-layout>
