<x-admin-layout>
    <section class="p-4 sm:p-6 max-w-6xl mx-auto text-gray-100">
        <h1 class="text-2xl font-bold mb-4">Signup package requests</h1>
        <nav aria-label="Request status" class="flex flex-wrap gap-2 mb-5">
            @foreach(['pending', 'approved', 'rejected', 'all'] as $filter)
                <a href="{{ route('saas.admin.package-requests.index', ['status' => $filter]) }}"
                   @if($filter === $status) aria-current="page" @endif
                   class="rounded px-3 py-2 {{ $filter === $status ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-200' }}">{{ ucfirst($filter) }}</a>
            @endforeach
        </nav>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-left">
                <thead class="text-gray-300"><tr>
                    <th class="p-2">Company</th><th class="p-2">Requested package</th><th class="p-2">Cycle</th>
                    <th class="p-2">Quoted amount</th><th class="p-2">Status</th><th class="p-2">Review</th>
                </tr></thead>
                <tbody>
                    @forelse($sales as $sale)
                        <tr class="border-t border-gray-700">
                            <td class="p-2">{{ $sale->company?->name ?? 'Company #' . $sale->company_id }}</td>
                            <td class="p-2">{{ $sale->plan_name }}</td>
                            <td class="p-2">{{ \App\Models\Subscription::getCycleLabel($sale->billing_cycle) }}</td>
                            <td class="p-2">Rs {{ number_format($sale->quoted_amount, 2) }}</td>
                            <td class="p-2">{{ ucfirst($sale->status) }}</td>
                            <td class="p-2"><a class="text-indigo-300 underline" href="{{ route('saas.admin.package-requests.show', $sale) }}">View request</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-3 text-gray-300">No package requests in this view.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $sales->links() }}
    </section>
</x-admin-layout>
