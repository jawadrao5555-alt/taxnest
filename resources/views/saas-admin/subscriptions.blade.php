<x-admin-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Subscriptions</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-white">Assign Subscription</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'Assign New'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.subscriptions.assign') }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <select name="company_id" required class="flex-1 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="">Select Company</option>
                @foreach($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
            <select name="pricing_plan_id" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="">Select Plan</option>
                @foreach($plans as $p)<option value="{{ $p->id }}">{{ $p->name }} (PKR {{ number_format($p->price) }})</option>@endforeach
            </select>
            <select name="billing_cycle" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition whitespace-nowrap">Assign</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3">Plan</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Cycle</th>
                        <th class="px-4 py-3 hidden md:table-cell">Start</th>
                        <th class="px-4 py-3 hidden md:table-cell">End</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($subscriptions as $sub)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-white font-medium">{{ $sub->company->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $sub->pricingPlan->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 hidden sm:table-cell">{{ ucfirst($sub->billing_cycle) }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ $sub->start_date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ $sub->end_date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $sub->active ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $sub->active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST" action="{{ route('saas.admin.subscriptions.toggle', $sub->id) }}" class="inline">@csrf
                                <button class="text-xs {{ $sub->active ? 'text-red-400 hover:text-red-300' : 'text-emerald-400 hover:text-emerald-300' }}">{{ $sub->active ? 'Deactivate' : 'Activate' }}</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-500">No subscriptions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($subscriptions->hasPages())<div class="mt-4">{{ $subscriptions->links() }}</div>@endif
</div>
</x-admin-layout>
