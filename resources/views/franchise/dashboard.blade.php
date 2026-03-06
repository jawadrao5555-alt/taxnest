<x-franchise-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Franchise Dashboard</h1>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <p class="text-xs text-gray-500 mb-1">My Companies</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total_companies']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <p class="text-xs text-gray-500 mb-1">Active Subscriptions</p>
            <p class="text-2xl font-bold text-teal-600">{{ number_format($stats['active_subscriptions']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <p class="text-xs text-gray-500 mb-1">Total Revenue</p>
            <p class="text-2xl font-bold text-emerald-600">PKR {{ number_format($stats['total_revenue'], 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <p class="text-xs text-gray-500 mb-1">Today Transactions</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['today_transactions']) }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Recent Companies</h3>
        <div class="space-y-2">
            @forelse($recentCompanies as $company)
            <div class="flex items-center justify-between text-sm border-b border-gray-100 dark:border-gray-800 pb-2">
                <span class="text-gray-900 dark:text-white font-medium">{{ $company->name }}</span>
                <span class="text-xs text-gray-500">{{ $company->created_at->diffForHumans() }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-500 text-center py-4">No companies yet</p>
            @endforelse
        </div>
    </div>
</div>
</x-franchise-layout>
