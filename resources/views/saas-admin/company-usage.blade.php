<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Company Usage Monitoring</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3 text-right">POS Transactions</th>
                        <th class="px-4 py-3 text-right">Total Sales (PKR)</th>
                        <th class="px-4 py-3 text-right">Terminals</th>
                        <th class="px-4 py-3 text-right">Users</th>
                        <th class="px-4 py-3 text-right">Inventory Items</th>
                        <th class="px-4 py-3">Last Activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($usageStats as $stat)
                    <tr class="hover:bg-gray-800/50 {{ $loop->even ? 'bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 text-white font-medium">
                            <a href="{{ route('saas.admin.companies.show', $stat->company_id) }}" class="hover:text-indigo-400 transition">{{ $stat->company->name ?? 'Unknown' }}</a>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300 font-medium">{{ number_format($stat->total_pos_transactions) }}</td>
                        <td class="px-4 py-3 text-right text-emerald-400 font-medium">{{ number_format($stat->total_sales_amount, 0) }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $stat->active_terminals }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $stat->active_users }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $stat->inventory_items }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $stat->last_activity_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-500">No usage data yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</x-admin-layout>
