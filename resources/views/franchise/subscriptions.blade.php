<x-franchise-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Company Subscriptions</h1>
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3">Plan</th>
                        <th class="px-4 py-3">Cycle</th>
                        <th class="px-4 py-3">End Date</th>
                        <th class="px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($subscriptions as $sub)
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $sub->company->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $sub->pricingPlan->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ ucfirst($sub->billing_cycle) }}</td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs">{{ $sub->end_date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-center"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $sub->active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">{{ $sub->active ? 'Active' : 'Inactive' }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">No subscriptions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($subscriptions->hasPages())<div class="mt-4">{{ $subscriptions->links() }}</div>@endif
</div>
</x-franchise-layout>
