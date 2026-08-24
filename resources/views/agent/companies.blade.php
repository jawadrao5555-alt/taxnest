<x-agent-layout>
<h1 class="text-2xl font-bold mb-6">My Companies</h1>
<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-x-auto">
<table class="w-full text-sm"><thead class="bg-gray-100 dark:bg-gray-800 text-left"><tr><th class="p-3">Company</th><th class="p-3">Signup</th><th class="p-3">Status</th><th class="p-3">Package</th><th class="p-3">Subscription</th></tr></thead>
<tbody>@forelse($companies as $company)
<tr class="border-t border-gray-200 dark:border-gray-800"><td class="p-3 font-medium">{{ $company->name }}</td><td class="p-3">{{ optional($company->created_at)->format('d M Y') }}</td><td class="p-3">{{ $company->company_status ?? $company->status }}</td><td class="p-3">{{ $company->activeSubscription?->pricingPlan?->name ?? '—' }}</td><td class="p-3">{{ $company->activeSubscription ? ($company->activeSubscription->isExpired() ? 'Expired' : 'Active') : 'None' }}</td></tr>
@empty<tr><td colspan="5" class="p-8 text-center text-gray-500">No attributed companies yet.</td></tr>@endforelse</tbody></table>
</div><div class="mt-4">{{ $companies->links() }}</div>
</x-agent-layout>